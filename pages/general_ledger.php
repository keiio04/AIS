<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/header.php';

$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to view the general ledger.</div>';
    require_once '../includes/footer.php';
    exit;
}

$elements = ['Assets', 'Liabilities', 'Equity', 'Revenue', 'Expenses'];
$selected_category = $_GET['category'] ?? '';

// ------------------------------------------------------------------
// Fetch the accounts to display. Each account gets its own
// standalone control-account ledger (no more mixing multiple
// accounts together under one category-wide running balance).
// ------------------------------------------------------------------
$accWhere = "a.company_id = ?";
$accParams = [$company_id];
$accTypes = "i";

if ($selected_category && in_array($selected_category, $elements)) {
    $accWhere .= " AND a.category = ?";
    $accParams[] = $selected_category;
    $accTypes .= "s";
}

$stmtAcc = $db->prepare("
    SELECT id, code, name, category, opening_balance
    FROM accounts a
    WHERE $accWhere
    ORDER BY FIELD(category,'Assets','Liabilities','Equity','Revenue','Expenses'), code ASC
");
$stmtAcc->bind_param($accTypes, ...$accParams);
$stmtAcc->execute();
$allAccounts = $stmtAcc->get_result()->fetch_all(MYSQLI_ASSOC);

// Prepared statement for pulling all posted lines that belong to one account.
// COALESCE covers both styles used across the app: some journals (Sales,
// Purchases, Cash Receipts/Disbursements) save the customer/supplier name
// on the line itself, while the General Journal saves it on the entry header.
$stmtLines = $db->prepare("
    SELECT l.debit, l.credit, e.date, e.description, e.reference_no,
           COALESCE(NULLIF(l.vendor_name, ''), NULLIF(e.vendor_name, '')) as vendor_name
    FROM journal_entry_lines l
    JOIN journal_entries e ON l.journal_entry_id = e.id
    WHERE l.account_id = ? AND e.deleted_at IS NULL
    ORDER BY e.date ASC, e.id ASC
");

// Get Date Range
$stmtDate = $db->prepare("SELECT MIN(date) as min_date, MAX(date) as max_date FROM journal_entries WHERE company_id = ? AND deleted_at IS NULL");
$stmtDate->bind_param('i', $company_id);
$stmtDate->execute();
$dateRow = $stmtDate->get_result()->fetch_assoc();
$min_date = $dateRow['min_date'];
$max_date = $dateRow['max_date'];

$date_display = 'As of ' . date('F j, Y');
if ($min_date && $max_date) {
    $min_time = strtotime($min_date);
    $max_time = strtotime($max_date);
    if (date('Y-m', $min_time) === date('Y-m', $max_time)) {
        if (date('d', $min_time) === date('d', $max_time)) {
             $date_display = date('F j, Y', $min_time);
        } else {
             $date_display = date('F j', $min_time) . '-' . date('j, Y', $max_time);
        }
    } else {
        $date_display = date('M j, Y', $min_time) . ' - ' . date('M j, Y', $max_time);
    }
}

// An account is treated as a control account needing its own
// per-customer / per-supplier Subsidiary Ledger when its name
// mentions Receivable or Payable (mirrors the detection already
// used elsewhere in the app, e.g. journal_entries.php's _isReceivable/_isPayable).
function gl_needs_subsidiary($accountName) {
    return preg_match('/receivable|payable/i', $accountName) === 1;
}

// Renders one standalone ledger table (used for both the control
// account itself and each Subsidiary Ledger card underneath it).
function gl_render_ledger_table($title, $code, $openingBal, $lines, $isDebitNormal, $descLabel) {
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
        <form method="GET" style="margin: 0;">
            <select name="category" class="form-control" style="width: 200px; background: var(--bg-secondary); border: 1px solid var(--border-color); color: var(--text-primary);" onchange="this.form.submit()">
                <option value="">All Accounts</option>
                <?php foreach($elements as $el): ?>
                    <option value="<?= $el ?>" <?= $selected_category == $el ? 'selected' : '' ?>>
                        <?= htmlspecialchars($el) ?>
                    </option>
                <?php endforeach; ?>
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
            General Ledger<?= $selected_category ? ' - ' . htmlspecialchars($selected_category) : '' ?>
        </h3>
        <p style="font-size: 0.95rem; color: #6b7280; font-weight: 400; margin: 0;">
            <?= $date_display ?>
        </p>
    </div>

    <div style="padding: 0 2rem 2rem 2rem;">
<?php
$displayedAny = false;

foreach ($allAccounts as $acc) {
    $stmtLines->bind_param('i', $acc['id']);
    $stmtLines->execute();
    $lines = $stmtLines->get_result()->fetch_all(MYSQLI_ASSOC);

    $openingBal = (float)$acc['opening_balance'];
    if (count($lines) === 0 && $openingBal == 0.0) {
        continue; // nothing to show for this account
    }

    $displayedAny = true;
    $isDebitNormal = in_array($acc['category'], ['Assets', 'Expenses']);

    // ---- Control Account (main GL) ----
    echo gl_render_ledger_table(
        $acc['name'],
        $acc['code'],
        $openingBal,
        $lines,
        $isDebitNormal,
        'Description/Particulars'
    );

    // ---- Subsidiary Ledger (per customer / supplier) ----
    if (gl_needs_subsidiary($acc['name']) && count($lines) > 0) {
        $groups = [];
        foreach ($lines as $ln) {
            $key = ($ln['vendor_name'] !== null && $ln['vendor_name'] !== '')
                ? $ln['vendor_name']
                : '(No Customer/Supplier Specified)';
            $groups[$key][] = $ln;
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        echo '<div style="padding-left: 1.5rem; border-left: 3px solid var(--border-color); margin-bottom: 1rem;">';
        echo '<p class="text-xs text-muted" style="text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; margin-bottom: 0.75rem;">Subsidiary Ledger</p>';

        $n = 1;
        foreach ($groups as $subName => $subLines) {
            echo gl_render_ledger_table(
                $acc['name'] . ' - ' . $subName,
                $acc['code'] . '-' . $n,
                0,
                $subLines,
                $isDebitNormal,
                'Description'
            );
            $n++;
        }
        echo '</div>';
    }
}

if (!$displayedAny):
?>
        <div class="text-center text-muted" style="padding: 3rem 1rem;">
            No transactions found for the selected element.
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