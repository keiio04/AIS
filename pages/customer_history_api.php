<?php
// ============================================================
// customer_history_api.php — Returns a single customer's
// transaction history (Sales Invoices + Payments) as JSON,
// for the "View Transaction History" modal on customers.php.
// ============================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;
$customer_id = (int)($_GET['id'] ?? 0);

if (!$company_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No active company selected.']);
    exit;
}
if (!$customer_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing customer id.']);
    exit;
}

// Confirm the customer belongs to this company, and get its current
// AR balance using the same calculation as the customers list page.
$stmt = $db->prepare("
    SELECT c.id, c.code, c.name, c.opening_balance,
        (
            c.opening_balance +
            COALESCE((
                SELECT SUM(l.debit - l.credit)
                FROM journal_entry_lines l
                JOIN journal_entries e ON l.journal_entry_id = e.id
                JOIN accounts a ON l.account_id = a.id
                WHERE e.entity_id = c.id AND e.entity_type = 'customer'
                  AND e.deleted_at IS NULL
                  AND a.name LIKE '%receivable%'
            ), 0)
        ) AS current_balance
    FROM customers c
    WHERE c.id = ? AND c.company_id = ?
");
$stmt->bind_param('ii', $customer_id, $company_id);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();

if (!$customer) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Customer not found.']);
    exit;
}

$invoices = [];
$payments = [];

// 1. Fetch Sales Invoices from sales_invoices table (with real status and payments)
$stmtInv = $db->prepare("
    SELECT si.id, si.invoice_no, si.invoice_date, si.amount, si.amount_paid, si.status, si.cancellation_reason,
           je.description, je.deleted_at
    FROM sales_invoices si
    LEFT JOIN journal_entries je ON si.journal_entry_id = je.id
    WHERE si.customer_id = ? AND si.company_id = ?
    ORDER BY si.invoice_date ASC, si.id ASC
");
$stmtInv->bind_param('ii', $customer_id, $company_id);
$stmtInv->execute();
$invoicesRes = $stmtInv->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($invoicesRes as $inv) {
    $status = !empty($inv['deleted_at']) ? 'Voided' : $inv['status'];
    $desc = $inv['description'] ?: 'Sales Invoice';
    if ($status === 'Cancelled') {
        if (!empty($inv['cancellation_reason'])) {
            $desc .= ' [Cancelled: ' . $inv['cancellation_reason'] . ']';
        } else {
            $desc .= ' [Cancelled]';
        }
    } elseif ($status === 'Partially Paid') {
        $bal = round((float)$inv['amount'] - (float)$inv['amount_paid'], 2);
        $desc .= ' (Bal: ₱' . number_format($bal, 2) . ')';
    }

    $invoices[] = [
        'date'          => date('M d, Y', strtotime($inv['invoice_date'])),
        'reference_no'  => $inv['invoice_no'],
        'description'   => $desc,
        'amount'        => (float)$inv['amount'],
        'amount_paid'   => (float)$inv['amount_paid'],
        'remaining'     => max(0, round((float)$inv['amount'] - (float)$inv['amount_paid'], 2)),
        'status'        => $status,
    ];
}

// 2. Fetch legacy SJ entries that do not yet have a record in sales_invoices
$stmtLegacy = $db->prepare("
    SELECT e.id, e.reference_no, e.date, e.description, e.deleted_at,
           COALESCE((SELECT SUM(l.debit) FROM journal_entry_lines l WHERE l.journal_entry_id = e.id), 0) AS total_amount
    FROM journal_entries e
    LEFT JOIN sales_invoices si ON si.journal_entry_id = e.id
    WHERE e.entity_id = ? AND e.entity_type = 'customer' AND e.company_id = ?
      AND e.journal_id = 'SJ'
      AND e.reference_no NOT LIKE 'CN-%'
      AND si.id IS NULL
    ORDER BY e.date ASC, e.id ASC
");
$stmtLegacy->bind_param('ii', $customer_id, $company_id);
$stmtLegacy->execute();
$legacyRes = $stmtLegacy->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($legacyRes as $leg) {
    $invoices[] = [
        'date'          => date('M d, Y', strtotime($leg['date'])),
        'reference_no'  => $leg['reference_no'],
        'description'   => $leg['description'] ?: 'Sales Invoice',
        'amount'        => (float)$leg['total_amount'],
        'amount_paid'   => 0.0,
        'remaining'     => (float)$leg['total_amount'],
        'status'        => !empty($leg['deleted_at']) ? 'Voided' : 'Outstanding',
    ];
}

// 3. Fetch Payments (CRJ entries linked to this customer)
$stmtPay = $db->prepare("
    SELECT e.id, e.reference_no, e.date, e.description, e.deleted_at, e.invoice_id,
           COALESCE(
               (SELECT SUM(l.credit) FROM journal_entry_lines l JOIN accounts a ON l.account_id = a.id WHERE l.journal_entry_id = e.id AND a.name LIKE '%receivable%'),
               (SELECT SUM(l.debit) FROM journal_entry_lines l WHERE l.journal_entry_id = e.id)
           ) AS total_amount,
           si.invoice_no AS applied_invoice_no
    FROM journal_entries e
    LEFT JOIN sales_invoices si ON e.invoice_id = si.id
    WHERE e.entity_id = ? AND e.entity_type = 'customer' AND e.company_id = ?
      AND e.journal_id = 'CRJ'
    ORDER BY e.date ASC, e.id ASC
");
$stmtPay->bind_param('ii', $customer_id, $company_id);
$stmtPay->execute();
$paymentsRes = $stmtPay->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($paymentsRes as $p) {
    $isVoided = !empty($p['deleted_at']);
    $desc = $p['description'] ?: 'Customer Collection';
    if (!empty($p['applied_invoice_no']) && stripos($desc, $p['applied_invoice_no']) === false) {
        $desc .= ' (Applied to SI #' . $p['applied_invoice_no'] . ')';
    }
    $payments[] = [
        'date'          => date('M d, Y', strtotime($p['date'])),
        'reference_no'  => $p['reference_no'],
        'description'   => $desc,
        'amount'        => (float)$p['total_amount'],
        'status'        => $isVoided ? 'Voided' : 'Paid',
    ];
}

echo json_encode([
    'success'             => true,
    'customer'            => [
        'id'   => (int)$customer['id'],
        'code' => $customer['code'],
        'name' => $customer['name'],
    ],
    'invoices'            => $invoices,
    'payments'            => $payments,
    'outstanding_balance' => (float)$customer['current_balance'],
]);