<?php
// ============================================================
// customer_history_api.php — Returns a single customer's
// transaction history (Sales Invoices + Payments) as JSON,
// for the "View Transaction History" modal on customers.php.
// ============================================================
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';

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

// Pull every journal entry tied to this customer. Transactions recorded
// via "Record Transaction" are auto-routed to either:
//   SJ  (Sales Journal)         -> a credit sale / invoice, still outstanding on AR
//   CRJ (Cash Receipts Journal) -> a cash sale/collection, settled immediately
$stmt = $db->prepare("
    SELECT e.id, e.reference_no, e.date, e.description, e.journal_id, e.deleted_at,
        COALESCE((SELECT SUM(l.debit) FROM journal_entry_lines l WHERE l.journal_entry_id = e.id), 0) AS total_amount
    FROM journal_entries e
    WHERE e.entity_id = ? AND e.entity_type = 'customer' AND e.company_id = ?
    ORDER BY e.date ASC, e.id ASC
");
$stmt->bind_param('ii', $customer_id, $company_id);
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$invoices = [];
$payments = [];

foreach ($entries as $e) {
    $isVoided = !empty($e['deleted_at']);
    $row = [
        'date'          => date('M d, Y', strtotime($e['date'])),
        'reference_no'  => $e['reference_no'],
        'description'   => $e['description'],
        'amount'        => (float)$e['total_amount'],
    ];

    if ($e['journal_id'] === 'SJ') {
        // Credit sale -> an invoice that adds to Accounts Receivable
        $row['status'] = $isVoided ? 'Voided' : 'Outstanding';
        $invoices[] = $row;
    } elseif ($e['journal_id'] === 'CRJ') {
        // Cash received -> settled at the time of the transaction
        $row['status'] = $isVoided ? 'Voided' : 'Paid';
        $payments[] = $row;
    }
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