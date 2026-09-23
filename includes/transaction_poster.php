<?php
/**
 * includes/transaction_poster.php
 * Automated journal entry generation & posting for TALA Accounting Simulation.
 */

if (!function_exists('get_company_standard_accounts')) {
    /**
     * Retrieves or auto-creates standard accounts needed for automated posting.
     */
    function get_company_standard_accounts(mysqli $db, int $company_id): array {
        $stmt = $db->prepare("SELECT id, code, name, category, sub_category FROM accounts WHERE company_id = ? ORDER BY code ASC");
        $stmt->bind_param('i', $company_id);
        $stmt->execute();
        $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $resolved = [
            'cash' => null,           // Cash on Hand (1-100) or first cash account
            'bank' => null,           // Cash in Bank (1-101)
            'ar' => null,             // Accounts Receivable (1-110)
            'ap' => null,             // Accounts Payable (2-100)
            'output_vat' => null,     // Output VAT (2-150)
            'input_vat' => null,      // Input VAT (1-160)
            'service_revenue' => null,// Service Revenue (4-100)
            'sales' => null,          // Sales (4-100)
            'purchases' => null,      // Purchases (5-100)
            'all' => $accounts
        ];

        foreach ($accounts as $acc) {
            $name = strtolower(trim($acc['name']));
            $cat = $acc['category'];

            if (!$resolved['cash'] && ($name === 'cash on hand' || $acc['code'] === '1-100')) {
                $resolved['cash'] = $acc;
            } elseif (!$resolved['bank'] && ($name === 'cash in bank' || $acc['code'] === '1-101')) {
                $resolved['bank'] = $acc;
            }

            if (!$resolved['ar'] && ($name === 'accounts receivable' || (strpos($name, 'receivable') !== false && $cat === 'Assets'))) {
                $resolved['ar'] = $acc;
            }

            if (!$resolved['ap'] && ($name === 'accounts payable' || (strpos($name, 'payable') !== false && $cat === 'Liabilities'))) {
                $resolved['ap'] = $acc;
            }

            if (!$resolved['output_vat'] && strpos($name, 'output vat') !== false) {
                $resolved['output_vat'] = $acc;
            }

            if (!$resolved['input_vat'] && strpos($name, 'input vat') !== false) {
                $resolved['input_vat'] = $acc;
            }

            if (!$resolved['service_revenue'] && ($name === 'service revenue' || (strpos($name, 'service') !== false && $cat === 'Revenue'))) {
                $resolved['service_revenue'] = $acc;
            }

            if (!$resolved['sales'] && ($name === 'sales' && $cat === 'Revenue')) {
                $resolved['sales'] = $acc;
            }

            if (!$resolved['purchases'] && ($name === 'purchases' || (strpos($name, 'purchases') !== false && $cat === 'Expenses'))) {
                $resolved['purchases'] = $acc;
            }
        }

        // Fallback for Cash if Cash on Hand specifically wasn't found
        // Fallback or auto-provision for Cash on Hand
        if (!$resolved['cash']) {
            foreach ($accounts as $acc) {
                if ($acc['category'] === 'Assets' && stripos($acc['name'], 'cash') !== false) {
                    $resolved['cash'] = $acc;
                    break;
                }
            }
            if (!$resolved['cash']) {
                try {
                    $cCode = '1-100';
                    $stmtC = $db->prepare("INSERT INTO accounts (company_id, code, name, category, sub_category, description, opening_balance) VALUES (?, ?, 'Cash on Hand', 'Assets', 'Current Assets', 'Primary cash account', 0.00)");
                    $stmtC->bind_param('is', $company_id, $cCode);
                    $stmtC->execute();
                    $resolved['cash'] = ['id' => $stmtC->insert_id, 'code' => $cCode, 'name' => 'Cash on Hand', 'category' => 'Assets'];
                } catch (Exception $e) {}
            }
        }

        // Auto-provision Accounts Receivable if missing
        if (!$resolved['ar']) {
            try {
                $arCode = '1-110';
                $stmtAR = $db->prepare("INSERT INTO accounts (company_id, code, name, category, sub_category, description, opening_balance) VALUES (?, ?, 'Accounts Receivable', 'Assets', 'Current Assets', 'Receivables from customers', 0.00)");
                $stmtAR->bind_param('is', $company_id, $arCode);
                $stmtAR->execute();
                $resolved['ar'] = ['id' => $stmtAR->insert_id, 'code' => $arCode, 'name' => 'Accounts Receivable', 'category' => 'Assets'];
            } catch (Exception $e) {}
        }

        // Auto-provision Accounts Payable if missing
        if (!$resolved['ap']) {
            try {
                $apCode = '2-100';
                $stmtAP = $db->prepare("INSERT INTO accounts (company_id, code, name, category, sub_category, description, opening_balance) VALUES (?, ?, 'Accounts Payable', 'Liabilities', 'Current Liabilities', 'Payables to suppliers', 0.00)");
                $stmtAP->bind_param('is', $company_id, $apCode);
                $stmtAP->execute();
                $resolved['ap'] = ['id' => $stmtAP->insert_id, 'code' => $apCode, 'name' => 'Accounts Payable', 'category' => 'Liabilities'];
            } catch (Exception $e) {}
        }

        // Auto-provision Service Revenue if missing
        if (!$resolved['service_revenue']) {
            try {
                $srCode = '4-100';
                $stmtSR = $db->prepare("INSERT INTO accounts (company_id, code, name, category, sub_category, description, opening_balance) VALUES (?, ?, 'Service Revenue', 'Revenue', 'Operating Revenue', 'Revenue from rendered services', 0.00)");
                $stmtSR->bind_param('is', $company_id, $srCode);
                $stmtSR->execute();
                $resolved['service_revenue'] = ['id' => $stmtSR->insert_id, 'code' => $srCode, 'name' => 'Service Revenue', 'category' => 'Revenue'];
            } catch (Exception $e) {}
        }

        // Auto-provision Purchases / Supplies Expense if missing
        if (!$resolved['purchases']) {
            try {
                $pCode = '5-100';
                $stmtP = $db->prepare("INSERT INTO accounts (company_id, code, name, category, sub_category, description, opening_balance) VALUES (?, ?, 'Purchases', 'Expenses', 'Cost of Sales', 'Purchases / Supplies expense', 0.00)");
                $stmtP->bind_param('is', $company_id, $pCode);
                $stmtP->execute();
                $resolved['purchases'] = ['id' => $stmtP->insert_id, 'code' => $pCode, 'name' => 'Purchases', 'category' => 'Expenses'];
            } catch (Exception $e) {}
        }

        // Auto-provision Output VAT if missing
        if (!$resolved['output_vat']) {
            try {
                $vatCode = '2-150';
                $stmtV = $db->prepare("INSERT INTO accounts (company_id, code, name, category, sub_category, description, opening_balance) VALUES (?, ?, 'Output VAT', 'Liabilities', 'Current Liabilities', 'Value-added tax on sales', 0.00)");
                $stmtV->bind_param('is', $company_id, $vatCode);
                $stmtV->execute();
                $newId = $stmtV->insert_id;
                $resolved['output_vat'] = ['id' => $newId, 'code' => $vatCode, 'name' => 'Output VAT', 'category' => 'Liabilities'];
            } catch (Exception $e) {}
        }

        // Auto-provision Input VAT if missing
        if (!$resolved['input_vat']) {
            try {
                $vatCode = '1-160';
                $stmtV = $db->prepare("INSERT INTO accounts (company_id, code, name, category, sub_category, description, opening_balance) VALUES (?, ?, 'Input VAT', 'Assets', 'Current Assets', 'Value-added tax on purchases', 0.00)");
                $stmtV->bind_param('is', $company_id, $vatCode);
                $stmtV->execute();
                $newId = $stmtV->insert_id;
                $resolved['input_vat'] = ['id' => $newId, 'code' => $vatCode, 'name' => 'Input VAT', 'category' => 'Assets'];
            } catch (Exception $e) {}
        }

        return $resolved;
    }
}

if (!function_exists('log_posting_activity')) {
    function log_posting_activity(mysqli $db, int $company_id, ?int $user_id, string $action, ?string $module = 'Journal Entries', ?string $desc = null): void {
        try {
            $stmt = $db->prepare("INSERT INTO activity_logs (company_id, user_id, action, module, description) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('iisss', $company_id, $user_id, $action, $module, $desc);
            $stmt->execute();
        } catch (Exception $e) {
            try {
                $stmt = $db->prepare("INSERT INTO activity_logs (company_id, user_id, action) VALUES (?, ?, ?)");
                $stmt->bind_param('iis', $company_id, $user_id, $action);
                $stmt->execute();
            } catch (Exception $e2) {}
        }
    }
}

if (!function_exists('post_student_transaction')) {
    /**
     * Automatic transaction-to-journal-entry generation and posting.
     * Routes transaction from Customer or Supplier page directly to the appropriate special journal:
     * - Customer + Cash   => Cash Receipts Journal (CRJ)
     * - Customer + Credit => Sales Journal (SJ)
     * - Supplier + Cash   => Cash Disbursements Journal (CDJ)
     * - Supplier + Credit => Purchases Journal (PJ)
     *
     * @param mysqli $db
     * @param int $company_id
     * @param int $user_id
     * @param array $data
     * @return array ['success' => bool, 'entry_id' => int, 'reference_no' => string, 'journal_id' => string, 'error' => ?string]
     */
    function post_student_transaction(mysqli $db, int $company_id, int $user_id, array $data): array {
        $entity_type = strtolower(trim($data['entity_type'] ?? ''));
        if (!in_array($entity_type, ['customer', 'supplier'], true)) {
            return ['success' => false, 'error' => 'Invalid transaction entity type.'];
        }

        $entity_id = !empty($data['entity_id']) ? (int)$data['entity_id'] : null;
        if (!$entity_id) {
            return ['success' => false, 'error' => ($entity_type === 'customer' ? 'Customer' : 'Supplier') . ' is required.'];
        }

        // Fetch entity name for reference and logs
        $entity_table = ($entity_type === 'customer') ? 'customers' : 'suppliers';
        $stmtEnt = $db->prepare("SELECT name, code FROM {$entity_table} WHERE id = ? AND company_id = ?");
        $stmtEnt->bind_param('ii', $entity_id, $company_id);
        $stmtEnt->execute();
        $entityRow = $stmtEnt->get_result()->fetch_assoc();
        if (!$entityRow) {
            return ['success' => false, 'error' => ucfirst($entity_type) . ' not found.'];
        }
        $entity_name = $entityRow['name'];

        // Terms must strictly be Cash or Credit
        $terms = ucfirst(strtolower(trim($data['terms'] ?? 'Cash')));
        if (!in_array($terms, ['Cash', 'Credit'], true)) {
            $terms = 'Cash';
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Transaction amount must be greater than zero.'];
        }

        $date = trim($data['date'] ?? '');
        if (empty($date) || !strtotime($date)) {
            $date = date('Y-m-d');
        }

        $is_vatable = !empty($data['is_vatable']);
        $is_vat_inclusive = !empty($data['is_vat_inclusive']);

        // Determine target journal automatically:
        // Customer + Cash   => Cash Receipts Journal (CRJ)
        // Customer + Credit => Sales Journal (SJ)
        // Supplier + Cash   => Cash Disbursements Journal (CDJ)
        // Supplier + Credit => Purchases Journal (PJ)
        if ($entity_type === 'customer') {
            $journal_id = ($terms === 'Cash') ? 'CRJ' : 'SJ';
        } else {
            $journal_id = ($terms === 'Cash') ? 'CDJ' : 'PJ';
        }

        $journal_names = [
            'CRJ' => 'Cash Receipts Journal',
            'SJ'  => 'Sales Journal',
            'CDJ' => 'Cash Disbursements Journal',
            'PJ'  => 'Purchases Journal',
        ];
        $journal_name = $journal_names[$journal_id] ?? 'Journal Entries';

        // Standard accounts resolution
        $stdAccts = get_company_standard_accounts($db, $company_id);

        // Calculate VAT amounts guaranteeing Total Debit = Total Credit
        if ($is_vatable) {
            if ($is_vat_inclusive) {
                $net_amount   = round($amount / 1.12, 2);
                $vat_amount   = round($amount - $net_amount, 2);
                $total_amount = $amount;
            } else {
                $net_amount   = round($amount, 2);
                $vat_amount   = round($amount * 0.12, 2);
                $total_amount = round($net_amount + $vat_amount, 2);
            }
        } else {
            $net_amount   = round($amount, 2);
            $vat_amount   = 0.00;
            $total_amount = $net_amount;
        }

        // Resolve Target Accounts & Build Balanced Debit/Credit Lines
        $lines = []; // list of ['account_id' => int, 'debit' => float, 'credit' => float]
        $zero = 0.00;

        if ($entity_type === 'customer') {
            // Target revenue account
            $rev_acc_id = !empty($data['target_account_id']) ? (int)$data['target_account_id'] : ($stdAccts['service_revenue']['id'] ?? $stdAccts['sales']['id'] ?? null);
            if (!$rev_acc_id) {
                return ['success' => false, 'error' => 'Service Revenue account not found. Please verify your Chart of Accounts.'];
            }

            // Customer + Cash   => Debit Cash, Credit Service Revenue, Credit Output VAT if VATable
            // Customer + Credit => Debit Accounts Receivable, Credit Service Revenue, Credit Output VAT if VATable
            $debit_acct = ($terms === 'Cash') ? $stdAccts['cash'] : $stdAccts['ar'];
            if (!$debit_acct) {
                return ['success' => false, 'error' => ($terms === 'Cash' ? 'Cash' : 'Accounts Receivable') . ' account not found in Chart of Accounts.'];
            }

            // Dr. Cash or AR
            $lines[] = [
                'account_id' => (int)$debit_acct['id'],
                'debit'      => $total_amount,
                'credit'     => $zero
            ];
            // Cr. Service Revenue
            $lines[] = [
                'account_id' => $rev_acc_id,
                'debit'      => $zero,
                'credit'     => $net_amount
            ];
            // Cr. Output VAT (if VATable)
            if ($vat_amount > 0) {
                if (!$stdAccts['output_vat']) {
                    return ['success' => false, 'error' => 'Output VAT account not found in Chart of Accounts.'];
                }
                $lines[] = [
                    'account_id' => (int)$stdAccts['output_vat']['id'],
                    'debit'      => $zero,
                    'credit'     => $vat_amount
                ];
            }

        } else {
            // Supplier Accounting
            // Target expense/supplies account
            $exp_acc_id = !empty($data['target_account_id']) ? (int)$data['target_account_id'] : ($stdAccts['purchases']['id'] ?? null);
            if (!$exp_acc_id) {
                // Find first available Expense or Asset account
                foreach ($stdAccts['all'] as $acc) {
                    if ($acc['category'] === 'Expenses') {
                        $exp_acc_id = (int)$acc['id'];
                        break;
                    }
                }
            }
            if (!$exp_acc_id) {
                return ['success' => false, 'error' => 'Expense or Supplies account not found. Please verify your Chart of Accounts.'];
            }

            // Supplier + Cash   => Debit Expense/Supplies, Debit Input VAT if VATable, Credit Cash
            // Supplier + Credit => Debit Expense/Supplies, Debit Input VAT if VATable, Credit Accounts Payable
            $credit_acct = ($terms === 'Cash') ? $stdAccts['cash'] : $stdAccts['ap'];
            if (!$credit_acct) {
                return ['success' => false, 'error' => ($terms === 'Cash' ? 'Cash' : 'Accounts Payable') . ' account not found in Chart of Accounts.'];
            }

            // Dr. Expense / Supplies
            $lines[] = [
                'account_id' => $exp_acc_id,
                'debit'      => $net_amount,
                'credit'     => $zero
            ];
            // Dr. Input VAT (if VATable)
            if ($vat_amount > 0) {
                if (!$stdAccts['input_vat']) {
                    return ['success' => false, 'error' => 'Input VAT account not found in Chart of Accounts.'];
                }
                $lines[] = [
                    'account_id' => (int)$stdAccts['input_vat']['id'],
                    'debit'      => $vat_amount,
                    'credit'     => $zero
                ];
            }
            // Cr. Cash or AP
            $lines[] = [
                'account_id' => (int)$credit_acct['id'],
                'debit'      => $zero,
                'credit'     => $total_amount
            ];
        }

        // Verify Total Debit == Total Credit
        $sumDr = 0.0;
        $sumCr = 0.0;
        foreach ($lines as $l) {
            $sumDr += (float)$l['debit'];
            $sumCr += (float)$l['credit'];
        }
        if (abs(round($sumDr, 2) - round($sumCr, 2)) > 0.01) {
            return ['success' => false, 'error' => "Entry imbalance detected: Total Debit (₱" . number_format($sumDr, 2) . ") != Total Credit (₱" . number_format($sumCr, 2) . ")."];
        }

        // Build description / particulars
        $custom_desc = trim($data['description'] ?? '');
        if (!empty($custom_desc)) {
            $description = $custom_desc;
        } else {
            if ($entity_type === 'customer') {
                $description = ($terms === 'Cash')
                    ? "Cash collection / revenue from {$entity_name}"
                    : "Sales on credit to {$entity_name}";
            } else {
                $description = ($terms === 'Cash')
                    ? "Cash payment for purchase from {$entity_name}"
                    : "Purchase on credit from {$entity_name}";
            }
        }

        // Generate unique reference number: {JOURNAL}-{YYYYMMDD}-{RAND}
        $dateFormatted = str_replace('-', '', $date);
        $attempts = 0;
        do {
            $randSuffix = str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $ref_no = "{$journal_id}-{$dateFormatted}-{$randSuffix}";
            $checkStmt = $db->prepare("SELECT id FROM journal_entries WHERE company_id = ? AND reference_no = ?");
            $checkStmt->bind_param('is', $company_id, $ref_no);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->fetch_assoc();
            $attempts++;
        } while ($exists && $attempts < 20);

        // Execute DB Transaction
        $db->begin_transaction();
        try {
            $is_taxable_int = $is_vatable ? 1 : 0;
            $particulars = '';
            $type = 'Operating';

            $stmtIns = $db->prepare("INSERT INTO journal_entries (company_id, reference_no, date, description, is_taxable, particulars, type, journal_id, entity_id, entity_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->bind_param('isssisssis', $company_id, $ref_no, $date, $description, $is_taxable_int, $particulars, $type, $journal_id, $entity_id, $entity_type);
            $stmtIns->execute();
            $entry_id = $stmtIns->insert_id;

            $stmtLine = $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
            foreach ($lines as $line) {
                $dr = (float)$line['debit'];
                $cr = (float)$line['credit'];
                $accId = (int)$line['account_id'];
                $stmtLine->bind_param('iidd', $entry_id, $accId, $dr, $cr);
                $stmtLine->execute();
            }

            // Log activity
            $log_action = "Auto-posted {$journal_name} ({$journal_id}) | Ref: {$ref_no} | {$entity_name} | Total: ₱" . number_format($total_amount, 2);
            log_posting_activity($db, $company_id, $user_id, $log_action, $journal_name, "Generated entry #{$entry_id} for {$entity_type}: {$entity_name}");

            $db->commit();

            return [
                'success'      => true,
                'entry_id'     => $entry_id,
                'reference_no' => $ref_no,
                'journal_id'   => $journal_id,
                'journal_name' => $journal_name,
                'total_amount' => $total_amount,
                'error'        => null
            ];
        } catch (Exception $e) {
            $db->rollback();
            return ['success' => false, 'error' => 'Database error saving entry: ' . $e->getMessage()];
        }
    }
}
