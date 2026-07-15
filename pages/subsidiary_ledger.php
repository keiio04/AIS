<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/header.php';

$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to view the subsidiary ledger.</div>';
    require_once '../includes/footer.php';
    exit;
}

$type_filter = $_GET['type'] ?? ''; // '', 'receivable', 'payable'

function sl_is_receivable($accountName) {
    return preg_match('/receivable/i', $accountName) === 1;
}
function sl_is_payable($accountName) {
    return preg_match('/payable/i', $accountName) === 1;
}

// ------------------------------------------------------------------
// Find every AR / AP control account for this company. Each one gets
// its own set of per-customer / per-supplier cards below.
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

// Prepared statement for pulling all posted lines that belong to one account
// (used to find names tagged on the control account itself that might not
// be in the Customers/Suppliers master list).
$stmtLines = $db->prepare("
    SELECT COALESCE(NULLIF(l.vendor_name, ''), NULLIF(e.vendor_name, '')) as vendor_name
    FROM journal_entry_lines l
    JOIN journal_entries e ON l.journal_entry_id = e.id
    WHERE l.account_id = ? AND e.deleted_at IS NULL
");

// Prepared statement for a customer/supplier's full, cross-account
// transaction history -- pulls EVERY line company-wide tagged with that
// person's name, not just lines that hit the AR/AP account itself. e.g.
// an "Other Income" line entered against a customer shows up as its own
// table nested under that same customer, not merged or floating apart.
$stmtVendorLines = $db->prepare("
    SELECT l.debit, l.credit, e.date, e.reference_no,
           a.name as account_name, a.code as account_code, a.category as account_category,
           COALESCE(NULLIF(l.description, ''), NULLIF(e.description, '')) as description
    FROM journal_entry_lines l
    JOIN journal_entries e ON l.journal_entry_id = e.id
    JOIN accounts a ON l.account_id = a.id
    WHERE e.company_id = ? AND e.deleted_at IS NULL
      AND LOWER(COALESCE(NULLIF(l.vendor_name, ''), NULLIF(e.vendor_name, ''))) = LOWER(?)
    ORDER BY e.date ASC, e.id ASC
");
$vendorNameParam = '';
$stmtVendorLines->bind_param('is', $company_id, $vendorNameParam);

// Master lists of customers / suppliers with their OWN opening balances.
// This is what makes each card reconcile correctly instead of always
// starting at zero.
$customerBalances = [];
$stmtCust = $db->prepare("SELECT name, opening_balance FROM customers WHERE company_id = ?");
$stmtCust->bind_param('i', $company_id);
$stmtCust->execute();
foreach ($stmtCust->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
    $customerBalances[mb_strtolower(trim($c['name']))] = [
        'display_name' => $c['name'],
        'opening_balance' => (float)$c['opening_balance'],
    ];
}

$supplierBalances = [];
$stmtSupp = $db->prepare("SELECT name, opening_balance FROM suppliers WHERE company_id = ?");
$stmtSupp->bind_param('i', $company_id);
$stmtSupp->execute();
foreach ($stmtSupp->get_result()->fetch_all(MYSQLI_ASSOC) as $s) {
    $supplierBalances[mb_strtolower(trim($s['name']))] = [
        'display_name' => $s['name'],
        'opening_balance' => (float)$s['opening_balance'],
    ];
}

// Renders one standalone ledger table (control account style, reused
// here for every customer/supplier card and every cross-account card).
function sl_render_ledger_table($title, $code, $openingBal, $lines, $isDebitNormal, $descLabel) {
    $running = $openingBal;
    ob_start();
    ?>
    <table class="table" style="margin: 0 0 1.75rem 0; border: 1px solid var(--border-color);">
        <thead>
            <tr style="background-color: #f1f5f9;">
                <td colspan="6" style="padding: 0.85rem 1rem; border-bottom: 1px solid var(--border-color);">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
                        <span style="font-weight:700; font-size:0.95rem; color:#334155; letter-spacing:0.03em;">
                            <?= htmlspecialchars($title) ?>
                        </span>
                        <span style="font-size:0.8rem; color:#334155;">
                            <strong>Account Code:</strong> <?= htmlspecialchars($code) ?>
                        </span>
                    </div>
                </td>
            </tr>
            <tr style="border-bottom: 2px solid var(--text-primary);">
                <th style="width: 10%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary);">Date</th>
                <th style="width: 32%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary);"><?= htmlspecialchars($descLabel) ?></th>
                <th style="width: 15%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary);">Reference</th>
                <th class="text-right" style="width: 14%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary);">Debit</th>
                <th class="text-right" style="width: 14%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary);">Credit</th>
                <th class="text-right" style="width: 15%; text-transform: uppercase; font-size: 0.72rem; font-weight: 700; color: var(--text-secondary);">Balance</th>
            </tr>
        </thead>
        <tbody>
        <?php if ((float)$openingBal !== 0.0): ?>
            <tr>
                <td colspan="5" style="padding: 0.65rem 0.5rem; font-style: italic; color: var(--text-secondary); font-size: 0.85rem;">Beginning Balance</td>
                <td class="text-right" style="padding: 0.65rem 0.5rem; font-style: italic; font-size: 0.85rem;"><?= number_format($openingBal, 2) ?></td>
            </tr>
        <?php endif; ?>

        <?php if (count($lines) === 0): ?>
            <tr><td colspan="6" class="text-center text-muted" style="padding: 1.25rem;">No transactions recorded.</td></tr>
        <?php else: foreach ($lines as $line):
            if ($isDebitNormal) {
                $running += $line['debit'];
                $running -= $line['credit'];
            } else {
                $running += $line['credit'];
                $running -= $line['debit'];
            }
        ?>
            <tr>
                <td style="color: var(--text-secondary); font-size: 0.85rem; padding: 0.65rem 0.5rem; white-space: nowrap;">
                    <?= date('m/d/Y', strtotime($line['date'])) ?>
                </td>
                <td style="padding: 0.65rem 0.5rem; font-size: 0.85rem;">
                    <?= htmlspecialchars($line['description'] ?? '') ?>
                </td>
                <td style="font-size: 0.8rem; color: var(--text-secondary); padding: 0.65rem 0.5rem;">
                    <?= htmlspecialchars($line['reference_no'] ?? '') ?>
                </td>
                <td class="text-right" style="padding: 0.65rem 0.5rem;">
                    <?= $line['debit'] > 0 ? number_format($line['debit'], 2) : '' ?>
                </td>
                <td class="text-right" style="padding: 0.65rem 0.5rem;">
                    <?= $line['credit'] > 0 ? number_format($line['credit'], 2) : '' ?>
                </td>
                <td class="text-right" style="font-weight: 500; padding: 0.65rem 0.5rem;">
                    <?= number_format($running, 2) ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>

            <tr>
                <td colspan="5" class="text-right" style="padding: 0.65rem 0.5rem; font-weight: 600; font-size: 0.85rem;">
                    Ending Balance
                </td>
                <td class="text-right" style="padding: 0.65rem 0.5rem; font-weight: 700; font-size: 0.9rem; border-top: 1px solid var(--border-color); border-bottom: 3px double var(--text-primary);">
                    <?= number_format($running, 2) ?>
                </td>
            </tr>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}
?>

<div class="page-header no-print" style="justify-content: flex-end; margin-bottom: 1rem; background: transparent; border: none; box-shadow: none; padding: 0.5rem 0;">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <a href="<?= BASE_URL ?>pages/general_ledger.php" class="btn btn-secondary">
            <i data-lucide="book-open" style="width:15px;height:15px;"></i> General Ledger
        </a>
        <form method="GET" style="margin: 0;">
            <select name="type" class="form-control" style="width: 200px; background: var(--bg-secondary); border: 1px solid var(--border-color); color: var(--text-primary);" onchange="this.form.submit()">
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

<div class="card" style="padding: 0; margin-bottom: 2rem; overflow: hidden; box-shadow: none; border: 1px solid var(--border-color); background: transparent;">

    <!-- Report Header (Standard Accounting Format) -->
    <div style="padding: 2.5rem 2rem 1.5rem 2rem; text-align: center;">
        <h2 style="font-size: 1.75rem; margin-bottom: 0.5rem; font-weight: 400; color: #374151; letter-spacing: 0.5px;">
            <?= htmlspecialchars($activeCompanyName ?? 'Company') ?>
        </h2>
        <h3 style="font-size: 1.05rem; color: #4b5563; margin-bottom: 0.25rem; font-weight: 600;">
            Subsidiary Ledger
        </h3>
        <p style="font-size: 0.95rem; color: #6b7280; font-weight: 400; margin: 0;">
            As of <?= date('F j, Y') ?>
        </p>
    </div>

    <div style="padding: 0 2rem 2rem 2rem;">
<?php
$displayedAny = false;

foreach ($controlAccounts as $acc) {
    $isDebitNormal = in_array($acc['category'], ['Assets', 'Expenses']);
    $masterList = sl_is_receivable($acc['name']) ? $customerBalances : $supplierBalances;

    // Seed groups from the master list so accounts with only an
    // opening balance (no activity yet) still appear.
    $groups = [];
    foreach ($masterList as $key => $info) {
        $groups[$key] = [
            'display_name' => $info['display_name'],
            'opening_balance' => $info['opening_balance'],
        ];
    }

    // Any name tagged on this control account's own lines but not found
    // in the master list (e.g. later renamed/deleted record) still
    // needs its own card, starting at 0.
    $stmtLines->bind_param('i', $acc['id']);
    $stmtLines->execute();
    $ownLines = $stmtLines->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($ownLines as $ln) {
        if ($ln['vendor_name'] === null || $ln['vendor_name'] === '') {
            continue;
        }
        $key = mb_strtolower(trim($ln['vendor_name']));
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'display_name' => $ln['vendor_name'],
                'opening_balance' => 0.0,
            ];
        }
    }

    // Pull the full, cross-account transaction history for each name,
    // then split it BY source account -- each account this person has
    // activity in gets its own standalone table under their name.
    $finalGroups = [];
    foreach ($groups as $key => $g) {
        $vendorNameParam = $g['display_name'];
        $stmtVendorLines->execute();
        $vLines = $stmtVendorLines->get_result()->fetch_all(MYSQLI_ASSOC);

        if ((float)$g['opening_balance'] === 0.0 && count($vLines) === 0) {
            continue; // nothing to show for this name
        }

        $byAccount = [];
        foreach ($vLines as $vl) {
            $byAccount[$vl['account_name']][] = $vl;
        }

        // The native AR/AP card (this control account) always shows
        // first, even if empty, since it carries the opening balance.
        $g['native_lines'] = $byAccount[$acc['name']] ?? [];
        unset($byAccount[$acc['name']]);
        $g['other_accounts'] = $byAccount; // account_name => lines[]
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
        // 1) Native AR/AP table for this customer/supplier.
        echo sl_render_ledger_table(
            $acc['name'] . ' - ' . $g['display_name'],
            $acc['code'] . '-' . $n,
            $g['opening_balance'],
            $g['native_lines'],
            $isDebitNormal,
            'Description'
        );

        // 2) A separate table per OTHER account this same person shows
        // up in (e.g. "Other Income - alexa"), each with its own
        // header, own code, and its own normal-balance rules.
        foreach ($g['other_accounts'] as $otherAccName => $otherLines) {
            $otherCode = $otherLines[0]['account_code'] ?? ($acc['code'] . '-' . $n);
            $otherCategory = $otherLines[0]['account_category'] ?? $acc['category'];
            $otherIsDebitNormal = in_array($otherCategory, ['Assets', 'Expenses']);
            echo sl_render_ledger_table(
                $otherAccName . ' - ' . $g['display_name'],
                $otherCode,
                0,
                $otherLines,
                $otherIsDebitNormal,
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
    .card {
        visibility: visible;
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        border: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .card * { visibility: visible; }
    .table th { border-bottom-color: #000 !important; color: #000 !important; }
    .table td, .table th { padding: 0.5rem !important; }
}
</style>

<?php require_once '../includes/footer.php'; ?>