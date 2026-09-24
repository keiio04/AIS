<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/header.php';

$db = get_db();
try { $db->query("CREATE TABLE IF NOT EXISTS customers (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, code VARCHAR(20) NULL, name VARCHAR(150) NOT NULL, opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0, status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); } catch (Exception $e) {}
try { $db->query("CREATE TABLE IF NOT EXISTS suppliers (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, code VARCHAR(20) NULL, name VARCHAR(150) NOT NULL, opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0, status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); } catch (Exception $e) {}
try { $db->query("
    CREATE TABLE IF NOT EXISTS sales_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        journal_entry_id INT NOT NULL,
        customer_id INT NOT NULL,
        invoice_no VARCHAR(60) NOT NULL,
        invoice_date DATE NOT NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
        status ENUM('Open','Partially Paid','Paid') NOT NULL DEFAULT 'Open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company (company_id),
        INDEX idx_customer (customer_id),
        INDEX idx_journal (journal_entry_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE journal_entries ADD COLUMN invoice_id INT NULL DEFAULT NULL AFTER entity_type"); } catch (Exception $e) {}

$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to view the subsidiary ledger.</div>';
    require_once '../includes/footer.php';
    exit;
}

// ── Backfill / Sync: Ensure any existing credit sales entries have sales_invoices records ──
try {
    $db->query("
        INSERT INTO sales_invoices (company_id, journal_entry_id, customer_id, invoice_no, invoice_date, amount, amount_paid, status)
        SELECT 
            e.company_id,
            e.id,
            e.entity_id,
            COALESCE(NULLIF(e.reference_no, ''), CONCAT('SJ-', e.id)),
            e.date,
            SUM(l.debit),
            0.00,
            'Open'
        FROM journal_entries e
        JOIN journal_entry_lines l ON l.journal_entry_id = e.id
        JOIN accounts a ON l.account_id = a.id
        LEFT JOIN sales_invoices si ON si.journal_entry_id = e.id
        WHERE e.company_id = {$company_id}
          AND e.deleted_at IS NULL
          AND e.journal_id = 'SJ'
          AND e.entity_id IS NOT NULL
          AND e.entity_type = 'customer'
          AND a.name LIKE '%receivable%'
          AND l.debit > 0
          AND si.id IS NULL
        GROUP BY e.id
    ");

    // Sync payments and status
    $db->query("
        UPDATE sales_invoices si
        SET 
            amount_paid = COALESCE((
                SELECT SUM(l.credit) 
                FROM journal_entries e 
                JOIN journal_entry_lines l ON l.journal_entry_id = e.id 
                JOIN accounts a ON l.account_id = a.id
                WHERE e.invoice_id = si.id 
                  AND e.deleted_at IS NULL 
                  AND a.name LIKE '%receivable%'
            ), 0),
            status = CASE 
                WHEN COALESCE((
                    SELECT SUM(l.credit) 
                    FROM journal_entries e 
                    JOIN journal_entry_lines l ON l.journal_entry_id = e.id 
                    JOIN accounts a ON l.account_id = a.id
                    WHERE e.invoice_id = si.id 
                      AND e.deleted_at IS NULL 
                      AND a.name LIKE '%receivable%'
                ), 0) >= si.amount AND si.amount > 0 THEN 'Paid'
                WHEN COALESCE((
                    SELECT SUM(l.credit) 
                    FROM journal_entries e 
                    JOIN journal_entry_lines l ON l.journal_entry_id = e.id 
                    JOIN accounts a ON l.account_id = a.id
                    WHERE e.invoice_id = si.id 
                      AND e.deleted_at IS NULL 
                      AND a.name LIKE '%receivable%'
                ), 0) > 0 THEN 'Partially Paid'
                ELSE 'Open'
            END
        WHERE si.company_id = {$company_id}
    ");
} catch (Exception $e) {}

$type_filter = $_GET['type'] ?? ''; // '', 'receivable', 'payable'

function sl_is_receivable($accountName) {
    return preg_match('/receivable/i', $accountName) === 1;
}
function sl_is_payable($accountName) {
    return preg_match('/payable/i', $accountName) === 1;
}

// ------------------------------------------------------------------
// Fetch Open / Unpaid Invoices for AR summary
// ------------------------------------------------------------------
$openInvoices = [];
if ($type_filter !== 'payable') {
    $stmtOpen = $db->prepare("
        SELECT si.*, c.name as customer_name, c.code as customer_code,
               (si.amount - si.amount_paid) as remaining_balance
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        WHERE si.company_id = ? AND si.status != 'Paid'
        ORDER BY si.invoice_date ASC, si.id ASC
    ");
    $stmtOpen->bind_param('i', $company_id);
    $stmtOpen->execute();
    $openInvoices = $stmtOpen->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ------------------------------------------------------------------
// Find AR / AP control accounts for this company
// ------------------------------------------------------------------
$stmtAcc = $db->prepare("
    SELECT id, code, name, category, opening_balance
    FROM accounts
    WHERE company_id = ? AND (name LIKE '%receivable%' OR name LIKE '%payable%')
    ORDER BY code ASC
");
$stmtAcc->bind_param('i', $company_id);
$stmtAcc->execute();
$controlAccounts = $stmtAcc->get_result()->fetch_all(MYSQLI_ASSOC);

if ($type_filter === 'receivable') {
    $controlAccounts = array_values(array_filter($controlAccounts, fn($a) => sl_is_receivable($a['name'])));
} elseif ($type_filter === 'payable') {
    $controlAccounts = array_values(array_filter($controlAccounts, fn($a) => sl_is_payable($a['name'])));
}

// Master lists of customers / suppliers
$customerBalances = [];
$stmtCust = $db->prepare("SELECT id, code, name, opening_balance FROM customers WHERE company_id = ? ORDER BY name ASC");
$stmtCust->bind_param('i', $company_id);
$stmtCust->execute();
foreach ($stmtCust->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
    $customerBalances[mb_strtolower(trim($c['name']))] = [
        'display_name' => $c['name'],
        'code' => $c['code'] ?? '',
        'opening_balance' => (float)$c['opening_balance'],
        'entity_id' => $c['id'],
        'entity_type' => 'customer'
    ];
}

$supplierBalances = [];
$stmtSupp = $db->prepare("SELECT id, code, name, opening_balance FROM suppliers WHERE company_id = ? ORDER BY name ASC");
$stmtSupp->bind_param('i', $company_id);
$stmtSupp->execute();
foreach ($stmtSupp->get_result()->fetch_all(MYSQLI_ASSOC) as $s) {
    $supplierBalances[mb_strtolower(trim($s['name']))] = [
        'display_name' => $s['name'],
        'code' => $s['code'] ?? '',
        'opening_balance' => (float)$s['opening_balance'],
        'entity_id' => $s['id'],
        'entity_type' => 'supplier'
    ];
}

// Statement for customer AR transaction history (includes sales_invoices data without duplication)
$stmtCustomerARLines = $db->prepare("
    SELECT l.id as line_id, l.debit, l.credit, e.id as entry_id, e.date, e.reference_no,
           e.journal_id, e.invoice_id,
           COALESCE(NULLIF(l.description, ''), NULLIF(e.description, '')) as description,
           si.invoice_no as inv_no, si.status as inv_status,
           si.amount as inv_total, si.amount_paid as inv_paid,
           (si.amount - si.amount_paid) as inv_remaining
    FROM journal_entry_lines l
    JOIN journal_entries e ON l.journal_entry_id = e.id
    LEFT JOIN sales_invoices si ON (
        (e.journal_id = 'SJ' AND si.journal_entry_id = e.id)
        OR (e.invoice_id IS NOT NULL AND si.id = e.invoice_id)
    )
    WHERE e.company_id = ? AND e.deleted_at IS NULL
      AND e.entity_id = ? AND e.entity_type = 'customer'
      AND l.account_id = ?
    ORDER BY e.date ASC, e.id ASC, l.id ASC
");

// Statement for supplier AP transaction history
$stmtSupplierAPLines = $db->prepare("
    SELECT l.id as line_id, l.debit, l.credit, e.date, e.reference_no,
           COALESCE(NULLIF(l.description, ''), NULLIF(e.description, '')) as description
    FROM journal_entry_lines l
    JOIN journal_entries e ON l.journal_entry_id = e.id
    WHERE e.company_id = ? AND e.deleted_at IS NULL
      AND e.entity_id = ? AND e.entity_type = 'supplier'
      AND l.account_id = ?
    ORDER BY e.date ASC, e.id ASC, l.id ASC
");

/**
 * Render Accounts Receivable Subsidiary Ledger Table (Customer & Invoice Focused)
 */
function sl_render_ar_ledger_table($customerName, $customerCode, $controlAccountCode, $openingBal, $lines) {
    $running = $openingBal;
    ob_start();
    ?>
    <div class="table-container" style="margin-bottom: 2rem; background: var(--bg-primary, #fff); border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <!-- Card Header -->
        <div style="padding: 0.85rem 1.25rem; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <div>
                <span style="font-weight: 700; font-size: 1rem; color: var(--text-primary); letter-spacing: 0.01em;">
                    <?= htmlspecialchars($customerName) ?>
                </span>
                <?php if (!empty($customerCode)): ?>
                    <span style="font-family: monospace; font-size: 0.75rem; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; margin-left: 0.5rem; font-weight: 600;">
                        <?= htmlspecialchars($customerCode) ?>
                    </span>
                <?php endif; ?>
                <span style="font-size: 0.75rem; color: var(--text-muted); margin-left: 0.5rem;">
                    (Customer AR Subsidiary Ledger)
                </span>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-secondary);">
                <strong>Control Account:</strong> <?= htmlspecialchars($controlAccountCode) ?>
            </div>
        </div>

        <table class="table compact-table" style="margin: 0; width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border-color); background: var(--bg-tertiary);">
                    <th style="min-width: 100px; width: 12%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem;" class="nowrap">Invoice Date</th>
                    <th style="min-width: 130px; width: 18%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem;">Customer</th>
                    <th style="min-width: 120px; width: 16%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem;" class="nowrap">Invoice / Ref No.</th>
                    <th style="min-width: 150px; width: 20%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem;">Description</th>
                    <th style="min-width: 95px; width: 10%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem; text-align: center;">Status</th>
                    <th class="text-right nowrap" style="min-width: 105px; width: 11%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem;">Debit</th>
                    <th class="text-right nowrap" style="min-width: 105px; width: 11%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem;">Credit</th>
                    <th class="text-right nowrap" style="min-width: 115px; width: 12%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem;">Running Balance</th>
                </tr>
            </thead>
            <tbody>
            <?php if ((float)$openingBal !== 0.0): 
                $running = $openingBal;
            ?>
                <tr style="background: rgba(248,250,252,0.6);">
                    <td colspan="4" style="padding: 0.5rem 0.65rem; font-style: italic; color: var(--text-secondary); font-size: 0.8125rem;">Beginning Balance</td>
                    <td style="text-align: center; color: var(--text-muted);">&mdash;</td>
                    <td class="text-right" style="padding: 0.5rem 0.65rem; font-style: italic; font-size: 0.8125rem; font-variant-numeric: tabular-nums;">&#8369;<?= number_format($openingBal, 2) ?></td>
                    <td class="text-right" style="padding: 0.5rem 0.65rem; color: var(--text-muted);">&mdash;</td>
                    <td class="text-right" style="padding: 0.5rem 0.65rem; font-weight: 600; font-size: 0.8125rem; font-variant-numeric: tabular-nums;">&#8369;<?= number_format($running, 2) ?></td>
                </tr>
            <?php endif; ?>

            <?php if (count($lines) === 0): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted" style="padding: 1.5rem; font-size: 0.8125rem;">
                        No Accounts Receivable transactions recorded for this customer.
                    </td>
                </tr>
            <?php else: 
                foreach ($lines as $line):
                    $running += $line['debit'];
                    $running -= $line['credit'];

                    $isInvoice = ($line['journal_id'] === 'SJ' || (!empty($line['inv_no']) && $line['debit'] > 0));
                    $isPayment = ($line['journal_id'] === 'CRJ' || $line['credit'] > 0);
                    
                    $displayRef = $line['inv_no'] ?: ($line['reference_no'] ?: '&mdash;');
            ?>
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <td style="color: var(--text-secondary); font-size: 0.8125rem; padding: 0.5rem 0.65rem; white-space: nowrap;">
                        <?= date('m/d/Y', strtotime($line['date'])) ?>
                    </td>
                    <td style="padding: 0.5rem 0.65rem; font-size: 0.8125rem; font-weight: 500;">
                        <?= htmlspecialchars($customerName) ?>
                    </td>
                    <td style="font-size: 0.8rem; padding: 0.5rem 0.65rem; font-family: monospace;">
                        <?php if (!empty($line['inv_no'])): ?>
                            <strong style="color: #0369a1;"><?= htmlspecialchars($line['inv_no']) ?></strong>
                            <?php if ($isPayment && !empty($line['reference_no'])): ?>
                                <br><span style="font-size: 0.7rem; color: var(--text-muted);">Ref: <?= htmlspecialchars($line['reference_no']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= htmlspecialchars($line['reference_no'] ?: '&mdash;') ?>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 0.5rem 0.65rem; font-size: 0.8125rem;">
                        <?php if ($isInvoice): ?>
                            <span style="font-weight: 600; color: var(--text-primary);">Sales Invoice</span>
                            <?php if (!empty($line['description'])): ?>
                                <span style="color: var(--text-secondary); font-size: 0.78rem;"> &mdash; <?= htmlspecialchars($line['description']) ?></span>
                            <?php endif; ?>
                        <?php elseif ($isPayment): ?>
                            <span style="font-weight: 600; color: #15803d;">Payment Received</span>
                            <?php if (!empty($line['inv_no'])): ?>
                                <span style="color: var(--text-secondary); font-size: 0.78rem;"> (Applied to <?= htmlspecialchars($line['inv_no']) ?>)</span>
                            <?php endif; ?>
                            <?php if (!empty($line['description'])): ?>
                                <br><span style="color: var(--text-muted); font-size: 0.75rem;"><?= htmlspecialchars($line['description']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= htmlspecialchars($line['description'] ?: 'Journal Entry') ?>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 0.5rem 0.65rem; text-align: center; white-space: nowrap;">
                        <?php if (!empty($line['inv_status'])): 
                            $status = $line['inv_status'];
                            $bg = match($status) {
                                'Open'           => '#fef9c3',
                                'Partially Paid' => '#dbeafe',
                                'Paid'           => '#dcfce7',
                                default          => '#f1f5f9'
                            };
                            $color = match($status) {
                                'Open'           => '#92400e',
                                'Partially Paid' => '#1d4ed8',
                                'Paid'           => '#15803d',
                                default          => '#475569'
                            };
                        ?>
                            <span style="background: <?= $bg ?>; color: <?= $color ?>; padding: 2px 7px; border-radius: 99px; font-size: 0.68rem; font-weight: 700;">
                                <?= htmlspecialchars($status) ?>
                            </span>
                            <?php if ($status !== 'Paid' && isset($line['inv_remaining']) && (float)$line['inv_remaining'] > 0): ?>
                                <div style="font-size: 0.68rem; color: #b45309; margin-top: 1px;">
                                    &#8369;<?= number_format($line['inv_remaining'], 2) ?> left
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color: var(--text-muted);">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right" style="padding: 0.5rem 0.65rem; font-size: 0.8125rem; font-variant-numeric: tabular-nums;">
                        <?= $line['debit'] > 0 ? '&#8369;' . number_format($line['debit'], 2) : '&mdash;' ?>
                    </td>
                    <td class="text-right" style="padding: 0.5rem 0.65rem; font-size: 0.8125rem; font-variant-numeric: tabular-nums; color: #16a34a;">
                        <?= $line['credit'] > 0 ? '&#8369;' . number_format($line['credit'], 2) : '&mdash;' ?>
                    </td>
                    <td class="text-right" style="font-weight: 600; padding: 0.5rem 0.65rem; font-size: 0.8125rem; font-variant-numeric: tabular-nums; color: var(--text-primary);">
                        &#8369;<?= number_format($running, 2) ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>

                <tr style="background: var(--bg-secondary);">
                    <td colspan="7" class="text-right" style="padding: 0.6rem 0.65rem; font-weight: 700; font-size: 0.82rem; color: var(--text-primary);">
                        Outstanding AR Balance (<?= htmlspecialchars($customerName) ?>):
                    </td>
                    <td class="text-right" style="padding: 0.6rem 0.65rem; font-weight: 700; font-size: 0.9rem; font-variant-numeric: tabular-nums; border-top: 1px solid var(--border-color); border-bottom: 3px double var(--text-primary); color: #0284c7;">
                        &#8369;<?= number_format($running, 2) ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render Accounts Payable Subsidiary Ledger Table (Standard Format)
 */
function sl_render_ledger_table($title, $code, $openingBal, $lines, $isDebitNormal, $descLabel) {
    $running = $openingBal;
    ob_start();
    ?>
    <div class="table-container" style="margin-bottom: 2rem; background: var(--bg-primary, #fff); border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div style="padding: 0.85rem 1.25rem; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <span style="font-weight: 700; font-size: 1rem; color: var(--text-primary);">
                <?= htmlspecialchars($title) ?>
            </span>
            <span style="font-size: 0.8rem; color: var(--text-secondary);">
                <strong>Account Code:</strong> <?= htmlspecialchars($code) ?>
            </span>
        </div>
        <table class="table compact-table" style="margin: 0; width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border-color); background: var(--bg-tertiary);">
                    <th style="min-width: 95px; width: 12%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem;" class="nowrap">Date</th>
                    <th style="min-width: 180px; width: 30%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem;"><?= htmlspecialchars($descLabel) ?></th>
                    <th style="min-width: 120px; width: 16%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem;" class="nowrap">Reference</th>
                    <th class="text-right nowrap" style="min-width: 110px; width: 14%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem;">Debit</th>
                    <th class="text-right nowrap" style="min-width: 110px; width: 14%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem;">Credit</th>
                    <th class="text-right nowrap" style="min-width: 120px; width: 14%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary); padding: 0.5rem 0.65rem;">Balance</th>
                </tr>
            </thead>
            <tbody>
            <?php if ((float)$openingBal !== 0.0): ?>
                <tr>
                    <td colspan="5" style="padding: 0.45rem 0.65rem; font-style: italic; color: var(--text-secondary); font-size: 0.8125rem;">Beginning Balance</td>
                    <td class="text-right" style="padding: 0.45rem 0.65rem; font-style: italic; font-size: 0.8125rem; font-variant-numeric: tabular-nums;"><?= number_format($openingBal, 2) ?></td>
                </tr>
            <?php endif; ?>

            <?php if (count($lines) === 0): ?>
                <tr><td colspan="6" class="text-center text-muted" style="padding: 1.5rem; font-size: 0.8125rem;">No transactions recorded.</td></tr>
            <?php else: foreach ($lines as $line):
                if ($isDebitNormal) {
                    $running += $line['debit'];
                    $running -= $line['credit'];
                } else {
                    $running += $line['credit'];
                    $running -= $line['debit'];
                }
            ?>
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <td style="color: var(--text-secondary); font-size: 0.8125rem; padding: 0.45rem 0.65rem; white-space: nowrap;">
                        <?= date('m/d/Y', strtotime($line['date'])) ?>
                    </td>
                    <td style="padding: 0.45rem 0.65rem; font-size: 0.8125rem;">
                        <?= htmlspecialchars($line['description'] ?? '') ?>
                    </td>
                    <td style="font-size: 0.78rem; color: var(--text-secondary); padding: 0.45rem 0.65rem;">
                        <?= htmlspecialchars($line['reference_no'] ?? '') ?>
                    </td>
                    <td class="text-right" style="padding: 0.45rem 0.65rem; font-size: 0.8125rem; font-variant-numeric: tabular-nums;">
                        <?= $line['debit'] > 0 ? number_format($line['debit'], 2) : '' ?>
                    </td>
                    <td class="text-right" style="padding: 0.45rem 0.65rem; font-size: 0.8125rem; font-variant-numeric: tabular-nums;">
                        <?= $line['credit'] > 0 ? number_format($line['credit'], 2) : '' ?>
                    </td>
                    <td class="text-right" style="font-weight: 500; padding: 0.45rem 0.65rem; font-size: 0.8125rem; font-variant-numeric: tabular-nums;">
                        <?= number_format($running, 2) ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>

                <tr style="background: var(--bg-secondary);">
                    <td colspan="5" class="text-right" style="padding: 0.5rem 0.65rem; font-weight: 600; font-size: 0.8125rem;">
                        Ending Balance
                    </td>
                    <td class="text-right" style="padding: 0.5rem 0.65rem; font-weight: 700; font-size: 0.85rem; font-variant-numeric: tabular-nums; border-top: 1px solid var(--border-color); border-bottom: 3px double var(--text-primary);">
                        <?= number_format($running, 2) ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}
?>

<div class="page-header no-print" style="justify-content: flex-end; margin-bottom: 1rem; background: transparent; border: none; box-shadow: none; padding: 0.5rem 0;">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <form method="GET" style="margin: 0;">
            <select name="type" class="form-control" style="width: 220px; background: var(--bg-secondary); border: 1px solid var(--border-color); color: var(--text-primary);" onchange="this.form.submit()">
                <option value="">Receivable &amp; Payable</option>
                <option value="receivable" <?= $type_filter === 'receivable' ? 'selected' : '' ?>>Receivable (Customers)</option>
                <option value="payable" <?= $type_filter === 'payable' ? 'selected' : '' ?>>Payable (Suppliers)</option>
            </select>
        </form>
        <button class="btn btn-secondary" onclick="window.print()">
            <i data-lucide="printer" style="width:15px;height:15px;"></i> Print
        </button>
    </div>
</div>

<div id="printable-area" style="width: 100%; margin-bottom: 2rem;">

    <!-- Report Header (Standard Accounting Format) -->
    <div style="padding: 1rem 0 1.5rem 0; text-align: center;">
        <h2 style="font-size: 1.5rem; margin-bottom: 0.35rem; font-weight: 600; color: var(--text-primary); letter-spacing: 0.5px;">
            <?= htmlspecialchars($activeCompanyName ?? 'Company') ?>
        </h2>
        <h3 style="font-size: 1.05rem; color: var(--text-secondary); margin-bottom: 0.25rem; font-weight: 600;">
            Subsidiary Ledger
        </h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); font-weight: 400; margin: 0;">
            As of <?= date('F j, Y') ?>
        </p>
    </div>

    <!-- ===== Open Invoices Section (Shown for Receivable & All) ===== -->
    <?php if ($type_filter !== 'payable'): ?>
        <?php if (!empty($openInvoices)): ?>
        <div style="background: var(--bg-primary, #ffffff); border: 1px solid #fed7aa; border-radius: 10px; padding: 1.25rem; margin-bottom: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 1rem; flex-wrap:wrap; gap:0.5rem;">
                <div>
                    <h4 style="margin:0; font-size:1.05rem; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:0.5rem;">
                        <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:#f59e0b;"></span>
                        Open Accounts Receivable Invoices
                    </h4>
                    <p style="margin:2px 0 0; font-size:0.78rem; color:var(--text-muted);">
                        Outstanding customer sales invoices with remaining unpaid balances.
                    </p>
                </div>
                <div style="display:flex; gap:0.75rem; align-items:center;">
                    <span style="font-size:0.8rem; background:#fef3c7; color:#92400e; padding:3px 10px; border-radius:99px; font-weight:700;">
                        <?= count($openInvoices) ?> Open Invoice<?= count($openInvoices) > 1 ? 's' : '' ?>
                    </span>
                    <span style="font-size:0.85rem; font-weight:700; color:#b45309;">
                        Total Unpaid: &#8369;<?= number_format(array_sum(array_column($openInvoices, 'remaining_balance')), 2) ?>
                    </span>
                </div>
            </div>
            
            <div class="table-container" style="margin:0; overflow-x:auto;">
                <table class="table" style="width:100%; border-collapse:collapse; font-size:0.82rem;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border-color); background: var(--bg-tertiary);">
                            <th style="padding:0.5rem 0.65rem; text-align:left; font-size:0.72rem; text-transform:uppercase;">Invoice Date</th>
                            <th style="padding:0.5rem 0.65rem; text-align:left; font-size:0.72rem; text-transform:uppercase;">Customer</th>
                            <th style="padding:0.5rem 0.65rem; text-align:left; font-size:0.72rem; text-transform:uppercase;">Invoice / Ref No.</th>
                            <th style="padding:0.5rem 0.65rem; text-align:right; font-size:0.72rem; text-transform:uppercase;">Invoice Total (Debit)</th>
                            <th style="padding:0.5rem 0.65rem; text-align:right; font-size:0.72rem; text-transform:uppercase;">Amount Paid (Credit)</th>
                            <th style="padding:0.5rem 0.65rem; text-align:right; font-size:0.72rem; text-transform:uppercase;">Remaining Balance</th>
                            <th style="padding:0.5rem 0.65rem; text-align:center; font-size:0.72rem; text-transform:uppercase;">Status</th>
                            <th style="padding:0.5rem 0.65rem; text-align:center; font-size:0.72rem; text-transform:uppercase;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($openInvoices as $inv): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding:0.5rem 0.65rem; white-space:nowrap;"><?= date('M d, Y', strtotime($inv['invoice_date'])) ?></td>
                            <td style="padding:0.5rem 0.65rem; font-weight:600; color:var(--text-primary);">
                                <?= htmlspecialchars($inv['customer_name']) ?>
                                <?php if (!empty($inv['customer_code'])): ?>
                                    <span style="font-size:0.7rem; color:var(--text-muted); font-family:monospace;">[<?= htmlspecialchars($inv['customer_code']) ?>]</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:0.5rem 0.65rem; font-family:monospace; font-weight:700; color:#0284c7;"><?= htmlspecialchars($inv['invoice_no']) ?></td>
                            <td style="padding:0.5rem 0.65rem; text-align:right; font-variant-numeric:tabular-nums;">&#8369;<?= number_format($inv['amount'], 2) ?></td>
                            <td style="padding:0.5rem 0.65rem; text-align:right; font-variant-numeric:tabular-nums; color:#16a34a;">&#8369;<?= number_format($inv['amount_paid'], 2) ?></td>
                            <td style="padding:0.5rem 0.65rem; text-align:right; font-weight:700; font-variant-numeric:tabular-nums; color:#b45309;">&#8369;<?= number_format($inv['remaining_balance'], 2) ?></td>
                            <td style="padding:0.5rem 0.65rem; text-align:center;">
                                <span style="background:<?= $inv['status']==='Partially Paid'?'#dbeafe':'#fef9c3' ?>; color:<?= $inv['status']==='Partially Paid'?'#1e40af':'#92400e' ?>; padding:2px 8px; border-radius:99px; font-size:0.68rem; font-weight:700;">
                                    <?= htmlspecialchars($inv['status']) ?>
                                </span>
                            </td>
                            <td style="padding:0.5rem 0.65rem; text-align:center;">
                                <a href="cash_receipts_journal.php?customer_id=<?= $inv['customer_id'] ?>&invoice_id=<?= $inv['id'] ?>" class="btn btn-sm" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; font-size:0.72rem; padding:3px 8px; border-radius:5px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:3px;">
                                    <i data-lucide="hand-coins" style="width:12px;height:12px;"></i> Collect
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="border-top: 2px solid var(--border-color); font-weight:700; background:var(--bg-secondary);">
                            <td colspan="3" style="padding:0.55rem 0.65rem; text-align:right;">Total Outstanding AR:</td>
                            <td style="padding:0.55rem 0.65rem; text-align:right; font-variant-numeric:tabular-nums;">&#8369;<?= number_format(array_sum(array_column($openInvoices, 'amount')), 2) ?></td>
                            <td style="padding:0.55rem 0.65rem; text-align:right; font-variant-numeric:tabular-nums; color:#16a34a;">&#8369;<?= number_format(array_sum(array_column($openInvoices, 'amount_paid')), 2) ?></td>
                            <td style="padding:0.55rem 0.65rem; text-align:right; font-variant-numeric:tabular-nums; color:#b45309;">&#8369;<?= number_format(array_sum(array_column($openInvoices, 'remaining_balance')), 2) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php elseif ($type_filter === 'receivable'): ?>
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 2rem; color: #166534; font-size: 0.85rem; display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="check-circle" style="width:16px;height:16px;"></i>
            <span>All customer sales invoices are fully paid. No open invoices at this time.</span>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <div>
<?php
$displayedAny = false;

foreach ($controlAccounts as $acc) {
    $isAR = sl_is_receivable($acc['name']);
    $isDebitNormal = in_array($acc['category'], ['Assets', 'Expenses']);
    
    // STRICT ENTITY FILTERING:
    // AR accounts ONLY contain customers
    // AP accounts ONLY contain suppliers
    if ($isAR) {
        $entityType = 'customer';
        $masterList = $customerBalances;
    } else {
        $entityType = 'supplier';
        $masterList = $supplierBalances;
    }

    $groups = [];
    $isMainControl = (stripos($acc['name'], 'accounts receivable') !== false || stripos($acc['name'], 'accounts payable') !== false);
    
    if ($isMainControl) {
        foreach ($masterList as $key => $info) {
            $groups[$key] = [
                'display_name'    => $info['display_name'],
                'code'            => $info['code'] ?? '',
                'opening_balance' => $info['opening_balance'],
                'entity_id'       => $info['entity_id'],
                'entity_type'     => $entityType,
            ];
        }
    }

    // Pull posted lines strictly matching the expected entity_type for this control account
    $stmtPosted = $db->prepare("
        SELECT DISTINCT e.entity_id,
               CASE 
                   WHEN ? = 'customer' THEN (SELECT name FROM customers WHERE id = e.entity_id)
                   ELSE (SELECT name FROM suppliers WHERE id = e.entity_id)
               END as entity_name,
               CASE 
                   WHEN ? = 'customer' THEN (SELECT code FROM customers WHERE id = e.entity_id)
                   ELSE (SELECT code FROM suppliers WHERE id = e.entity_id)
               END as entity_code
        FROM journal_entry_lines l
        JOIN journal_entries e ON l.journal_entry_id = e.id
        WHERE l.account_id = ? 
          AND e.deleted_at IS NULL
          AND e.entity_type = ?
          AND e.entity_id IS NOT NULL
    ");
    $stmtPosted->bind_param('ssis', $entityType, $entityType, $acc['id'], $entityType);
    $stmtPosted->execute();
    $postedEntities = $stmtPosted->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($postedEntities as $pe) {
        if (empty($pe['entity_name'])) continue;
        $key = mb_strtolower(trim($pe['entity_name']));
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'display_name'    => $pe['entity_name'],
                'code'            => $pe['entity_code'] ?? '',
                'opening_balance' => 0.0,
                'entity_id'       => $pe['entity_id'],
                'entity_type'     => $entityType,
            ];
        }
    }

    $finalGroups = [];
    foreach ($groups as $key => $g) {
        if (!$g['entity_id']) continue;

        if ($isAR) {
            // Customer AR lines
            $stmtCustomerARLines->bind_param('iii', $company_id, $g['entity_id'], $acc['id']);
            $stmtCustomerARLines->execute();
            $vLines = $stmtCustomerARLines->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            // Supplier AP lines
            $stmtSupplierAPLines->bind_param('iii', $company_id, $g['entity_id'], $acc['id']);
            $stmtSupplierAPLines->execute();
            $vLines = $stmtSupplierAPLines->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        // Avoid empty cards if there's no opening balance and no transaction history
        if ((float)$g['opening_balance'] === 0.0 && count($vLines) === 0) {
            continue;
        }

        $g['native_lines'] = $vLines;
        $finalGroups[$key] = $g;
    }
    $groups = $finalGroups;

    if (count($groups) === 0) {
        continue;
    }

    $displayedAny = true;
    uasort($groups, function ($a, $b) {
        return strnatcasecmp($a['display_name'], $b['display_name']);
    });

    echo '<h4 style="font-size: 0.95rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.05em; margin: 2rem 0 1rem 0; padding-bottom: 0.5rem; border-bottom: 2px solid var(--border-color);">'
        . htmlspecialchars($acc['name']) . '</h4>';

    $n = 1;
    foreach ($groups as $g) {
        $subCode = $acc['code'] . '-' . $n;
        if ($isAR) {
            // Specialized AR Subsidiary Ledger Table
            echo sl_render_ar_ledger_table(
                $g['display_name'],
                $g['code'],
                $subCode,
                $g['opening_balance'],
                $g['native_lines']
            );
        } else {
            // AP Subsidiary Ledger Table
            echo sl_render_ledger_table(
                $acc['name'] . ' - ' . $g['display_name'],
                $subCode,
                $g['opening_balance'],
                $g['native_lines'],
                $isDebitNormal,
                'Description'
            );
        }
        $n++;
    }
}

if (!$displayedAny):
?>
    <div class="text-center text-muted" style="padding: 3rem 1rem;">
        No customer or supplier activity found.
    </div>
<?php endif; ?>
    </div>
</div>

<!-- Print Styles -->
<style>
@media print {
    body * { visibility: hidden; }
    .page-header, .no-print { display: none !important; }
    #printable-area, #printable-area * {
        visibility: visible;
    }
    #printable-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0 !important;
        padding: 0 !important;
    }
    .table th { border-bottom-color: #000 !important; color: #000 !important; }
    .table td, .table th { padding: 0.4rem !important; }
}
</style>

<?php require_once '../includes/footer.php'; ?>