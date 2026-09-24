<?php
// ============================================================
// supplier_history_api.php — Returns a single supplier's
// transaction history (Purchases + Payments) as JSON,
// for the "View Transaction History" modal on suppliers.php.
// ============================================================
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;
$supplier_id = (int)($_GET['id'] ?? 0);

if (!$company_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No active company selected.']);
    exit;
}
if (!$supplier_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing supplier id.']);
    exit;
}

// Confirm the supplier belongs to this company, and get its current
// AP balance using the same calculation as the suppliers list page.
$stmt = $db->prepare("
    SELECT s.id, s.code, s.name, s.opening_balance,
        (
            s.opening_balance +
            COALESCE((
                SELECT SUM(l.credit - l.debit)
                FROM journal_entry_lines l
                JOIN journal_entries e ON l.journal_entry_id = e.id
                JOIN accounts a ON l.account_id = a.id
                WHERE e.entity_id = s.id AND e.entity_type = 'supplier'
                  AND e.deleted_at IS NULL
                  AND a.name LIKE '%payable%'
            ), 0)
        ) AS current_balance
    FROM suppliers s
    WHERE s.id = ? AND s.company_id = ?
");
$stmt->bind_param('ii', $supplier_id, $company_id);
$stmt->execute();
$supplier = $stmt->get_result()->fetch_assoc();

if (!$supplier) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Supplier not found.']);
    exit;
}

// Pull every journal entry tied to this supplier. Transactions recorded
// via "Record Transaction" are auto-routed to either:
//   PJ  (Purchases Journal)          -> a credit purchase, still outstanding on AP
//   CDJ (Cash Disbursements Journal) -> a cash purchase/payment, settled immediately
$stmt = $db->prepare("
    SELECT e.id, e.reference_no, e.date, e.description, e.journal_id, e.deleted_at,
        COALESCE((SELECT SUM(l.credit) FROM journal_entry_lines l WHERE l.journal_entry_id = e.id), 0) AS total_amount
    FROM journal_entries e
    WHERE e.entity_id = ? AND e.entity_type = 'supplier' AND e.company_id = ?
    ORDER BY e.date ASC, e.id ASC
");
$stmt->bind_param('ii', $supplier_id, $company_id);
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$purchases = [];
$payments = [];

foreach ($entries as $e) {
    $isVoided = !empty($e['deleted_at']);
    $row = [
        'date'          => date('M d, Y', strtotime($e['date'])),
        'reference_no'  => $e['reference_no'],
        'description'   => $e['description'],
        'amount'        => (float)$e['total_amount'],
    ];

    if ($e['journal_id'] === 'PJ') {
        // Credit purchase -> a bill that adds to Accounts Payable
        $row['status'] = $isVoided ? 'Voided' : 'Outstanding';
        $purchases[] = $row;
    } elseif ($e['journal_id'] === 'CDJ') {
        // Cash disbursed -> settled at the time of the transaction
        $row['status'] = $isVoided ? 'Voided' : 'Paid';
        $payments[] = $row;
    }
}

echo json_encode([
    'success'             => true,
    'supplier'            => [
        'id'   => (int)$supplier['id'],
        'code' => $supplier['code'],
        'name' => $supplier['name'],
    ],
    'purchases'           => $purchases,
    'payments'            => $payments,
    'outstanding_balance' => (float)$supplier['current_balance'],
]);