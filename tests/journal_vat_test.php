<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/journal_vat.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectAmount(float $actual, float $expected, string $message): void
{
    expect(abs($actual - $expected) < 0.001, "$message (expected $expected, got $actual)");
}

$accounts = [
    ['id' => 1, 'code' => '1-100', 'name' => 'Cash in Bank', 'category' => 'Assets'],
    ['id' => 2, 'code' => '1-110', 'name' => 'Accounts Receivable', 'category' => 'Assets'],
    ['id' => 3, 'code' => '5-100', 'name' => 'Office Supplies Expense', 'category' => 'Expenses'],
    ['id' => 4, 'code' => '2-100', 'name' => 'Accounts Payable', 'category' => 'Liabilities'],
    ['id' => 5, 'code' => '4-100', 'name' => 'Service Revenue', 'category' => 'Revenue'],
    ['id' => 6, 'code' => '1-160', 'name' => 'Input VAT', 'category' => 'Assets'],
    ['id' => 7, 'code' => '2-150', 'name' => 'Output VAT', 'category' => 'Liabilities'],
];
$vatIds = journal_vat_account_ids($accounts);

$cases = [
    'General Journal' => ['mode' => 'auto', 'lines' => [['account_id' => 2, 'debit' => 10000.0, 'credit' => 0.0], ['account_id' => 5, 'debit' => 0.0, 'credit' => 10000.0]], 'vat_id' => 7, 'side' => 'credit'],
    'Sales Journal' => ['mode' => 'output', 'lines' => [['account_id' => 2, 'debit' => 10000.0, 'credit' => 0.0], ['account_id' => 5, 'debit' => 0.0, 'credit' => 10000.0]], 'vat_id' => 7, 'side' => 'credit'],
    'Purchases Journal' => ['mode' => 'input', 'lines' => [['account_id' => 3, 'debit' => 10000.0, 'credit' => 0.0], ['account_id' => 4, 'debit' => 0.0, 'credit' => 10000.0]], 'vat_id' => 6, 'side' => 'debit'],
    'Cash Receipts Journal' => ['mode' => 'output', 'lines' => [['account_id' => 1, 'debit' => 10000.0, 'credit' => 0.0], ['account_id' => 5, 'debit' => 0.0, 'credit' => 10000.0]], 'vat_id' => 7, 'side' => 'credit'],
    'Cash Disbursements Journal' => ['mode' => 'input', 'lines' => [['account_id' => 3, 'debit' => 10000.0, 'credit' => 0.0], ['account_id' => 1, 'debit' => 0.0, 'credit' => 10000.0]], 'vat_id' => 6, 'side' => 'debit'],
];

foreach ($cases as $journal => $case) {
    $taxable = journal_vat_calculate($case['lines'], $accounts, $vatIds, $case['mode'], true, 'taxable');
    expect($taxable['tax_status'] === 'taxable', "$journal did not retain taxable status");
    expectAmount($taxable['base_amount'], 10000.0, "$journal base amount");
    expectAmount($taxable['vat_rate'], 0.12, "$journal VAT rate");
    expectAmount($taxable['vat_amount'], 1200.0, "$journal VAT amount");
    expectAmount($taxable['total_amount'], 11200.0, "$journal total amount");
    $vatLines = array_values(array_filter($taxable['lines'], static fn($line) => $line['account_id'] === $case['vat_id']));
    expect(count($vatLines) === 1, "$journal did not create exactly one VAT line");
    expectAmount((float)$vatLines[0][$case['side']], 1200.0, "$journal VAT posting side");
    expectAmount(array_sum(array_column($taxable['lines'], 'debit')), array_sum(array_column($taxable['lines'], 'credit')), "$journal final balance");

    $nonTaxable = journal_vat_calculate($case['lines'], $accounts, $vatIds, $case['mode'], true, 'non_taxable');
    expect($nonTaxable['tax_status'] === 'non_taxable', "$journal did not retain non-taxable status");
    expectAmount($nonTaxable['vat_amount'], 0.0, "$journal non-taxable VAT");
    expectAmount($nonTaxable['total_amount'], 10000.0, "$journal non-taxable total");
    expect(count($nonTaxable['lines']) === 2, "$journal non-taxable entry added a VAT line");

    $notRegistered = journal_vat_calculate($case['lines'], $accounts, $vatIds, $case['mode'], false, 'taxable');
    expect($notRegistered['tax_status'] === 'non_taxable', "$journal non-registered company was not forced non-taxable");
    expectAmount($notRegistered['vat_amount'], 0.0, "$journal non-registered VAT");

    $editable = journal_vat_edit_lines($taxable['lines'], $taxable, $vatIds);
    expect(count($editable) === 2, "$journal edit did not remove its generated VAT line");
    expectAmount(array_sum(array_column($editable, 'debit')), 10000.0, "$journal edit debit base");
    expectAmount(array_sum(array_column($editable, 'credit')), 10000.0, "$journal edit credit base");
}

$negativeRejected = false;
try {
    journal_vat_parse_amount('-1.00', 'Test amount');
} catch (InvalidArgumentException $e) {
    $negativeRejected = true;
}
expect($negativeRejected, 'Negative amount validation did not run');

echo "PASS: 5 journal taxable postings\n";
echo "PASS: 5 journal non-taxable calculations\n";
echo "PASS: 5 journal non-registered overrides\n";
echo "PASS: 5 journal edit reconstructions\n";
echo "PASS: negative amount validation\n";
