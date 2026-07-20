<?php
// Simulate session
session_start();
$_SESSION['active_company_id'] = 1;
$_SESSION['user_id'] = 1;

require_once 'db.php';
$db = get_db();
$company_id = 1;

// Simulate the fetch from sales_journal.php
$stmtCust = $db->prepare("SELECT id, name, 'customer' as type FROM customers WHERE company_id = ? ORDER BY name ASC");
$stmtCust->bind_param('i', $company_id);
$stmtCust->execute();
$customersList = $stmtCust->get_result()->fetch_all(MYSQLI_ASSOC);

$stmtSupp = $db->prepare("SELECT id, name, 'supplier' as type FROM suppliers WHERE company_id = ? ORDER BY name ASC");
$stmtSupp->bind_param('i', $company_id);
$stmtSupp->execute();
$suppliersList = $stmtSupp->get_result()->fetch_all(MYSQLI_ASSOC);

$entitiesList = array_merge($customersList, $suppliersList);
usort($entitiesList, function($a, $b) { return strcasecmp($a['name'], $b['name']); });

echo "Customers: " . count($customersList) . "\n";
echo "Suppliers: " . count($suppliersList) . "\n";
echo "Entities: " . count($entitiesList) . "\n";

// Check VAT accounts
$stmt = $db->prepare("SELECT id, code, name, category FROM accounts WHERE company_id = ? ORDER BY code ASC");
$stmt->bind_param('i', $company_id);
$stmt->execute();
$accountsList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$inputVatId = null;
$outputVatId = null;
foreach ($accountsList as $acc) {
    if (strpos(strtolower($acc['name']), 'input vat') !== false) {
        $inputVatId = $acc['id'];
    } elseif (strpos(strtolower($acc['name']), 'output vat') !== false) {
        $outputVatId = $acc['id'];
    }
}

echo "Input VAT ID: " . ($inputVatId ?? 'NOT FOUND') . "\n";
echo "Output VAT ID: " . ($outputVatId ?? 'NOT FOUND') . "\n";

// Check tax_registered
$stmtCo = $db->prepare("SELECT tax_registered FROM companies WHERE id = ?");
$stmtCo->bind_param('i', $company_id);
$stmtCo->execute();
$companyIsTaxRegistered = (bool)($stmtCo->get_result()->fetch_assoc()['tax_registered'] ?? false);
echo "Tax Registered: " . ($companyIsTaxRegistered ? 'YES' : 'NO') . "\n";

// Show recent SJ entries
$q = $db->prepare("SELECT e.id, e.reference_no, e.date, e.is_taxable FROM journal_entries e WHERE e.company_id = ? AND e.deleted_at IS NULL AND e.journal_id = 'SJ' ORDER BY e.date DESC, e.id DESC LIMIT 5");
$q->bind_param('i', $company_id);
$q->execute();
$entries = $q->get_result()->fetch_all(MYSQLI_ASSOC);
echo "\nRecent SJ entries:\n";
foreach ($entries as $e) {
    echo "  ID={$e['id']} Ref={$e['reference_no']} Date={$e['date']} is_taxable={$e['is_taxable']}\n";
    $lq = $db->prepare("SELECT l.*, a.name FROM journal_entry_lines l JOIN accounts a ON l.account_id = a.id WHERE l.journal_entry_id = ?");
    $lq->bind_param('i', $e['id']);
    $lq->execute();
    $lines = $lq->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($lines as $l) {
        echo "    account={$l['name']} dr={$l['debit']} cr={$l['credit']}\n";
    }
}
