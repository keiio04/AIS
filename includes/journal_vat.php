<?php

/**
 * Shared tax calculation and persistence for all journal modules.
 * Submitted journal lines are treated as tax-exclusive, balanced base lines.
 */

function journal_vat_ensure_schema(mysqli $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $existing = [];
    $result = $db->query('SHOW COLUMNS FROM journal_entries');
    while ($column = $result->fetch_assoc()) {
        $existing[$column['Field']] = true;
    }
    $changes = [
        'is_taxable' => "ALTER TABLE journal_entries ADD COLUMN is_taxable TINYINT(1) NOT NULL DEFAULT 0 AFTER description",
        'tax_status' => "ALTER TABLE journal_entries ADD COLUMN tax_status ENUM('taxable','non_taxable') NOT NULL DEFAULT 'non_taxable' AFTER is_taxable",
        'base_amount' => "ALTER TABLE journal_entries ADD COLUMN base_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER tax_status",
        'vat_rate' => "ALTER TABLE journal_entries ADD COLUMN vat_rate DECIMAL(6,4) NOT NULL DEFAULT 0.0000 AFTER base_amount",
        'vat_amount' => "ALTER TABLE journal_entries ADD COLUMN vat_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER vat_rate",
        'total_amount' => "ALTER TABLE journal_entries ADD COLUMN total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER vat_amount",
    ];

    foreach ($changes as $column => $sql) {
        if (isset($existing[$column])) {
            continue;
        }
        try {
            $db->query($sql);
        } catch (mysqli_sql_exception $e) {
            // Duplicate-column errors are expected after the migration is applied.
            if ((int)$e->getCode() !== 1060) {
                throw $e;
            }
        }
    }
    $checked = true;
}

function journal_vat_company_registration(mysqli $db, int $companyId): bool
{
    $stmt = $db->prepare('SELECT tax_registered FROM companies WHERE id = ?');
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (bool)($row['tax_registered'] ?? false);
}

function journal_vat_account_ids(array $accounts): array
{
    $ids = ['input' => null, 'output' => null];
    foreach ($accounts as $account) {
        $name = strtolower(trim((string)$account['name']));
        if ($name === 'input vat' || str_contains($name, 'input vat')) {
            $ids['input'] = (int)$account['id'];
        } elseif ($name === 'output vat' || str_contains($name, 'output vat')) {
            $ids['output'] = (int)$account['id'];
        }
    }
    return $ids;
}

function journal_vat_ensure_accounts(mysqli $db, int $companyId, array $accounts): array
{
    $ids = journal_vat_account_ids($accounts);
    $definitions = [
        'input' => ['1-160', 'Input VAT', 'Assets', 'Current Assets', 'Value-added tax on purchases'],
        'output' => ['2-150', 'Output VAT', 'Liabilities', 'Current Liabilities', 'Value-added tax on sales'],
    ];
    foreach ($definitions as $kind => $definition) {
        if ($ids[$kind]) {
            continue;
        }
        [$code, $name, $category, $subCategory, $description] = $definition;
        $stmt = $db->prepare('INSERT INTO accounts (company_id, code, name, category, sub_category, description, opening_balance) SELECT ?, ?, ?, ?, ?, ?, 0 WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE company_id = ? AND LOWER(name) = LOWER(?))');
        $stmt->bind_param('isssssis', $companyId, $code, $name, $category, $subCategory, $description, $companyId, $name);
        $stmt->execute();
    }

    $stmt = $db->prepare('SELECT id, code, name, category FROM accounts WHERE company_id = ? ORDER BY code ASC');
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function journal_vat_parse_amount($raw, string $label): float
{
    $value = trim(str_replace(',', '', (string)$raw));
    if ($value === '') {
        return 0.0;
    }
    if (!preg_match('/^(?:\d+)(?:\.\d{1,2})?$/', $value)) {
        throw new InvalidArgumentException("$label must be a valid non-negative amount with no more than two decimal places.");
    }
    $amount = (float)$value;
    if (!is_finite($amount) || $amount < 0 || $amount > 9999999999999.99) {
        throw new InvalidArgumentException("$label is outside the allowed amount range.");
    }
    return round($amount, 2);
}

function journal_vat_parse_lines(array $post, array $accounts): array
{
    $accountIds = $post['account_id'] ?? [];
    $debits = $post['debit'] ?? [];
    $credits = $post['credit'] ?? [];
    if (!is_array($accountIds) || !is_array($debits) || !is_array($credits)) {
        throw new InvalidArgumentException('Invalid journal line data.');
    }

    $accountMap = [];
    foreach ($accounts as $account) {
        $accountMap[(int)$account['id']] = $account;
    }

    $lineCount = max(count($accountIds), count($debits), count($credits));
    $lines = [];
    for ($i = 0; $i < $lineCount; $i++) {
        $accountId = (int)($accountIds[$i] ?? 0);
        $debit = journal_vat_parse_amount($debits[$i] ?? '', 'Debit on line ' . ($i + 1));
        $credit = journal_vat_parse_amount($credits[$i] ?? '', 'Credit on line ' . ($i + 1));

        if ($accountId === 0 && $debit === 0.0 && $credit === 0.0) {
            continue;
        }
        if (!isset($accountMap[$accountId])) {
            throw new InvalidArgumentException('Select a valid company account on line ' . ($i + 1) . '.');
        }
        if (($debit > 0 && $credit > 0) || ($debit === 0.0 && $credit === 0.0)) {
            throw new InvalidArgumentException('Each journal line must contain either a debit or a credit amount.');
        }
        $lines[] = ['account_id' => $accountId, 'debit' => $debit, 'credit' => $credit];
    }

    if (count($lines) < 2) {
        throw new InvalidArgumentException('At least two journal lines are required.');
    }

    $totalDebit = round(array_sum(array_column($lines, 'debit')), 2);
    $totalCredit = round(array_sum(array_column($lines, 'credit')), 2);
    if ($totalDebit <= 0 || abs($totalDebit - $totalCredit) >= 0.01) {
        throw new InvalidArgumentException('Debits and credits must be positive and balanced before VAT.');
    }

    return $lines;
}

function journal_vat_is_cash(array $account): bool
{
    return $account['category'] === 'Assets' && (bool)preg_match('/cash|bank/i', (string)$account['name']);
}

function journal_vat_is_purchase_base(array $account): bool
{
    if (!in_array($account['category'], ['Expenses', 'Assets'], true)) {
        return false;
    }
    return !preg_match('/cash|bank|receivable|input vat|output vat/i', (string)$account['name']);
}

function journal_vat_validate_journal(string $journalId, array $lines, array $accountMap): void
{
    $hasRevenue = false;
    $hasLiability = false;
    $hasCashDebit = false;
    $hasCashCredit = false;
    foreach ($lines as $line) {
        $account = $accountMap[$line['account_id']];
        $hasRevenue = $hasRevenue || ($account['category'] === 'Revenue' && $line['credit'] > 0);
        $hasLiability = $hasLiability || ($account['category'] === 'Liabilities' && $line['credit'] > 0);
        $hasCashDebit = $hasCashDebit || (journal_vat_is_cash($account) && $line['debit'] > 0);
        $hasCashCredit = $hasCashCredit || (journal_vat_is_cash($account) && $line['credit'] > 0);
    }

    if ($journalId === 'SJ' && (!$hasRevenue || $hasCashDebit || $hasCashCredit)) {
        throw new InvalidArgumentException('Sales Journal requires a revenue credit and cannot contain a Cash/Bank account.');
    }
    if ($journalId === 'PJ' && ($hasRevenue || !$hasLiability || $hasCashDebit || $hasCashCredit)) {
        throw new InvalidArgumentException('Purchases Journal requires a payable/liability credit and cannot contain Revenue or Cash/Bank.');
    }
    if ($journalId === 'CRJ' && !$hasCashDebit) {
        throw new InvalidArgumentException('Cash Receipts Journal requires a Cash/Bank debit.');
    }
    if ($journalId === 'CDJ' && !$hasCashCredit) {
        throw new InvalidArgumentException('Cash Disbursements Journal requires a Cash/Bank credit.');
    }
}

function journal_vat_calculate(
    array $lines,
    array $accounts,
    array $vatAccountIds,
    string $mode,
    bool $companyRegistered,
    string $requestedStatus
): array {
    $accountMap = [];
    foreach ($accounts as $account) {
        $accountMap[(int)$account['id']] = $account;
    }

    $taxStatus = $companyRegistered && $requestedStatus === 'taxable' ? 'taxable' : 'non_taxable';
    $inputBase = 0.0;
    $outputBase = 0.0;
    foreach ($lines as $line) {
        $account = $accountMap[$line['account_id']];
        if ($account['category'] === 'Revenue' && $line['credit'] > 0 && $line['account_id'] !== $vatAccountIds['output']) {
            $outputBase += $line['credit'];
        }
        if (journal_vat_is_purchase_base($account) && $line['debit'] > 0 && $line['account_id'] !== $vatAccountIds['input']) {
            $inputBase += $line['debit'];
        }
    }

    $postingType = null;
    $baseAmount = round(array_sum(array_column($lines, 'debit')), 2);
    if ($taxStatus === 'taxable') {
        if ($mode === 'output') {
            $postingType = 'output';
            $baseAmount = round($outputBase, 2);
        } elseif ($mode === 'input') {
            $postingType = 'input';
            $baseAmount = round($inputBase, 2);
        } else {
            if ($inputBase > 0 && $outputBase > 0) {
                throw new InvalidArgumentException('A taxable General Journal entry cannot mix purchase/expense and sales/income VAT bases.');
            }
            if ($outputBase > 0) {
                $postingType = 'output';
                $baseAmount = round($outputBase, 2);
            } elseif ($inputBase > 0) {
                $postingType = 'input';
                $baseAmount = round($inputBase, 2);
            }
        }

        if ($postingType === null || $baseAmount <= 0) {
            throw new InvalidArgumentException('Taxable transactions require a revenue credit or an eligible purchase/expense debit.');
        }
        if (!$vatAccountIds[$postingType]) {
            throw new RuntimeException(ucfirst($postingType) . ' VAT account is missing. Update Company Setup to create the VAT accounts.');
        }
        foreach ($lines as $line) {
            if ($line['account_id'] === $vatAccountIds['input'] || $line['account_id'] === $vatAccountIds['output']) {
                throw new InvalidArgumentException('Do not enter VAT accounts manually; they are posted automatically.');
            }
        }
    }

    $vatRate = $taxStatus === 'taxable' ? (float)VAT_RATE : 0.0;
    $vatAmount = round($baseAmount * $vatRate, 2);
    $totalAmount = round($baseAmount + $vatAmount, 2);
    $finalLines = $lines;

    if ($vatAmount > 0 && $postingType === 'output') {
        $counterpartFound = false;
        foreach ($finalLines as &$line) {
            if (!$counterpartFound && $line['debit'] > 0) {
                $line['debit'] = round($line['debit'] + $vatAmount, 2);
                $counterpartFound = true;
            }
        }
        unset($line);
        if (!$counterpartFound) {
            throw new InvalidArgumentException('Output VAT requires a debit counterpart.');
        }
        $finalLines[] = ['account_id' => $vatAccountIds['output'], 'debit' => 0.0, 'credit' => $vatAmount];
    } elseif ($vatAmount > 0 && $postingType === 'input') {
        $counterpartFound = false;
        foreach ($finalLines as &$line) {
            if (!$counterpartFound && $line['credit'] > 0) {
                $line['credit'] = round($line['credit'] + $vatAmount, 2);
                $counterpartFound = true;
            }
        }
        unset($line);
        if (!$counterpartFound) {
            throw new InvalidArgumentException('Input VAT requires a credit counterpart.');
        }
        $finalLines[] = ['account_id' => $vatAccountIds['input'], 'debit' => $vatAmount, 'credit' => 0.0];
    }

    $finalDebit = round(array_sum(array_column($finalLines, 'debit')), 2);
    $finalCredit = round(array_sum(array_column($finalLines, 'credit')), 2);
    if (abs($finalDebit - $finalCredit) >= 0.01) {
        throw new RuntimeException('The server-calculated VAT posting is not balanced.');
    }

    return [
        'tax_status' => $taxStatus,
        'is_taxable' => $taxStatus === 'taxable' ? 1 : 0,
        'base_amount' => $baseAmount,
        'vat_rate' => $vatRate,
        'vat_amount' => $vatAmount,
        'total_amount' => $totalAmount,
        'posting_type' => $postingType,
        'lines' => $finalLines,
    ];
}

function journal_vat_save(mysqli $db, array $options, array $accounts, bool $companyRegistered): ?string
{
    try {
        $companyId = (int)$options['company_id'];
        $journalId = $options['journal_id'];
        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['add_entry', 'edit_entry'], true)) {
            return null;
        }

        $date = trim((string)($_POST['date'] ?? ''));
        $dateParts = explode('-', $date);
        if (count($dateParts) !== 3 || !checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])) {
            throw new InvalidArgumentException('Enter a valid transaction date.');
        }
        $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
        if ($referenceNo === '') {
            $referenceNo = $options['reference_prefix'] . '-' . str_replace('-', '', $date) . '-' . random_int(1000, 9999);
        }
        if (mb_strlen($referenceNo) > 50) {
            throw new InvalidArgumentException('Reference number cannot exceed 50 characters.');
        }
        $description = trim((string)($_POST['description'] ?? ''));
        if (mb_strlen($description) > 255) {
            throw new InvalidArgumentException('Description cannot exceed 255 characters.');
        }

        $vendorName = trim((string)($_POST['vendor_name'] ?? ''));
        if (mb_strlen($vendorName) > 150) {
            throw new InvalidArgumentException('Name cannot exceed 150 characters.');
        }
        $entityId = ($_POST['entity_id'] ?? '') !== '' ? (int)$_POST['entity_id'] : null;
        $entityType = ($_POST['entity_type'] ?? '') !== '' ? (string)$_POST['entity_type'] : null;
        if ($entityId !== null) {
            if (!in_array($entityType, ['customer', 'supplier'], true)) {
                throw new InvalidArgumentException('Select a valid customer or supplier.');
            }
            $table = $entityType === 'customer' ? 'customers' : 'suppliers';
            $entityStmt = $db->prepare("SELECT id, name FROM $table WHERE id = ? AND company_id = ?");
            $entityStmt->bind_param('ii', $entityId, $companyId);
            $entityStmt->execute();
            $entity = $entityStmt->get_result()->fetch_assoc();
            if (!$entity) {
                throw new InvalidArgumentException('The selected customer or supplier does not belong to this company.');
            }
            if ($vendorName === '') {
                $vendorName = $entity['name'];
            }
        } else {
            $entityType = null;
        }
        $vendorName = $vendorName !== '' ? $vendorName : null;

        $lines = journal_vat_parse_lines($_POST, $accounts);
        $accountMap = [];
        foreach ($accounts as $account) {
            $accountMap[(int)$account['id']] = $account;
        }
        journal_vat_validate_journal($journalId, $lines, $accountMap);

        $requestedStatus = (string)($_POST['tax_status'] ?? 'non_taxable');
        if ($companyRegistered && !in_array($requestedStatus, ['taxable', 'non_taxable'], true)) {
            throw new InvalidArgumentException('Select a valid Tax Type.');
        }
        $vat = journal_vat_calculate(
            $lines,
            $accounts,
            journal_vat_account_ids($accounts),
            $options['vat_mode'],
            $companyRegistered,
            $requestedStatus
        );

        $entryId = $action === 'edit_entry' ? (int)($_POST['entry_id'] ?? $_POST['id'] ?? 0) : 0;
        if ($action === 'edit_entry') {
            $check = $db->prepare('SELECT id FROM journal_entries WHERE id = ? AND company_id = ? AND journal_id = ? AND deleted_at IS NULL');
            $check->bind_param('iis', $entryId, $companyId, $journalId);
            $check->execute();
            if (!$check->get_result()->fetch_assoc()) {
                throw new InvalidArgumentException('Journal entry not found.');
            }
        }

        $db->begin_transaction();
        try {
            $particulars = '';
            $type = 'Operating';
            $isTaxable = $vat['is_taxable'];
            $taxStatus = $vat['tax_status'];
            $baseAmount = $vat['base_amount'];
            $vatRate = $vat['vat_rate'];
            $vatAmount = $vat['vat_amount'];
            $totalAmount = $vat['total_amount'];

            if ($action === 'add_entry') {
                $stmt = $db->prepare('INSERT INTO journal_entries (company_id, reference_no, date, description, is_taxable, tax_status, base_amount, vat_rate, vat_amount, total_amount, particulars, type, vendor_name, journal_id, entity_id, entity_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('isssisddddssssis', $companyId, $referenceNo, $date, $description, $isTaxable, $taxStatus, $baseAmount, $vatRate, $vatAmount, $totalAmount, $particulars, $type, $vendorName, $journalId, $entityId, $entityType);
                $stmt->execute();
                $entryId = $stmt->insert_id;
            } else {
                $stmt = $db->prepare('UPDATE journal_entries SET reference_no = ?, date = ?, description = ?, is_taxable = ?, tax_status = ?, base_amount = ?, vat_rate = ?, vat_amount = ?, total_amount = ?, vendor_name = ?, entity_id = ?, entity_type = ? WHERE id = ? AND company_id = ?');
                $stmt->bind_param('sssisddddsisii', $referenceNo, $date, $description, $isTaxable, $taxStatus, $baseAmount, $vatRate, $vatAmount, $totalAmount, $vendorName, $entityId, $entityType, $entryId, $companyId);
                $stmt->execute();
                $deleteLines = $db->prepare('DELETE FROM journal_entry_lines WHERE journal_entry_id = ?');
                $deleteLines->bind_param('i', $entryId);
                $deleteLines->execute();
            }

            $lineStmt = $db->prepare('INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)');
            foreach ($vat['lines'] as $line) {
                $accountId = $line['account_id'];
                $debit = $line['debit'];
                $credit = $line['credit'];
                $lineStmt->bind_param('iidd', $entryId, $accountId, $debit, $credit);
                $lineStmt->execute();
            }

            $userId = (int)($_SESSION['user_id'] ?? 0);
            $verb = $action === 'add_entry' ? 'Created' : 'Edited';
            $logAction = "$verb Journal Entry | Ref: $referenceNo | Amount: PHP " . number_format($totalAmount, 2);
            $logStmt = $db->prepare('INSERT INTO activity_logs (company_id, user_id, action) VALUES (?, ?, ?)');
            $logStmt->bind_param('iis', $companyId, $userId, $logAction);
            $logStmt->execute();

            $db->commit();
            header('Location: ' . $options['redirect']);
            exit;
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function journal_vat_delete(mysqli $db, int $companyId, string $journalId, string $redirect, string $journalLabel): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'delete') {
        return;
    }
    $entryId = (int)($_POST['id'] ?? 0);
    if ($entryId > 0) {
        $stmt = $db->prepare('UPDATE journal_entries SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND company_id = ? AND journal_id = ? AND deleted_at IS NULL');
        $stmt->bind_param('iis', $entryId, $companyId, $journalId);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $action = "Moved $journalLabel Entry #$entryId to Trash";
            $log = $db->prepare('INSERT INTO activity_logs (company_id, user_id, action) VALUES (?, ?, ?)');
            $log->bind_param('iis', $companyId, $userId, $action);
            $log->execute();
        }
    }
    header('Location: ' . $redirect);
    exit;
}

/**
 * Convert stored, VAT-inclusive posting lines back to the user's base lines for editing.
 */
function journal_vat_edit_lines(array $lines, array $entry, array $vatAccountIds): array
{
    $vatAmount = round((float)($entry['vat_amount'] ?? 0), 2);
    $postingType = null;
    if ($vatAmount <= 0) {
        foreach ($lines as $line) {
            if ($vatAccountIds['output'] && (int)$line['account_id'] === $vatAccountIds['output'] && (float)$line['credit'] > 0) {
                $vatAmount = round((float)$line['credit'], 2);
                $postingType = 'output';
                break;
            }
            if ($vatAccountIds['input'] && (int)$line['account_id'] === $vatAccountIds['input'] && (float)$line['debit'] > 0) {
                $vatAmount = round((float)$line['debit'], 2);
                $postingType = 'input';
                break;
            }
        }
    }
    if ($postingType === null && $vatAmount > 0) {
        foreach ($lines as $line) {
            if ($vatAccountIds['output'] && (int)$line['account_id'] === $vatAccountIds['output']) {
                $postingType = 'output';
            } elseif ($vatAccountIds['input'] && (int)$line['account_id'] === $vatAccountIds['input']) {
                $postingType = 'input';
            }
        }
    }
    if ($vatAmount <= 0 || $postingType === null) {
        return array_values($lines);
    }

    $editable = [];
    $adjusted = false;
    foreach ($lines as $line) {
        $isVatLine = ($postingType === 'output' && (int)$line['account_id'] === $vatAccountIds['output'])
            || ($postingType === 'input' && (int)$line['account_id'] === $vatAccountIds['input']);
        if ($isVatLine) {
            continue;
        }
        if (!$adjusted && $postingType === 'output' && (float)$line['debit'] > 0) {
            $line['debit'] = round((float)$line['debit'] - $vatAmount, 2);
            $adjusted = true;
        } elseif (!$adjusted && $postingType === 'input' && (float)$line['credit'] > 0) {
            $line['credit'] = round((float)$line['credit'] - $vatAmount, 2);
            $adjusted = true;
        }
        $editable[] = $line;
    }
    return array_values($editable);
}
