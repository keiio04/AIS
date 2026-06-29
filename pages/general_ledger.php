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

// Build list of categories to display
$display_categories = [];
if ($selected_category && in_array($selected_category, $elements)) {
    $display_categories[] = $selected_category;
} else {
    $display_categories = $elements;
}

// Prepare statement for fetching entries for a specific category
$stmtLines = $db->prepare("
    SELECT l.debit, l.credit, e.date, e.description, e.reference_no, e.vendor_name, a.code, a.name as account_name
    FROM journal_entry_lines l
    JOIN journal_entries e ON l.journal_entry_id = e.id
    JOIN accounts a ON l.account_id = a.id
    WHERE a.company_id = ? AND a.category = ? AND e.deleted_at IS NULL
    ORDER BY e.date ASC, e.id ASC
");

// Prepare statement for opening balances
$stmtOB = $db->prepare("
    SELECT SUM(opening_balance) as total_ob 
    FROM accounts 
    WHERE company_id = ? AND category = ?
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

?>

<div class="page-header" style="justify-content: flex-end; margin-bottom: 0;">
    <!-- Print button -->
    <button class="btn btn-secondary" onclick="window.print()">
        <i data-lucide="printer" style="width:15px;height:15px;"></i> Print
    </button>
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

    <!-- Filter (Hidden when printing) -->
    <div class="no-print" style="padding: 0 2rem 1rem 2rem; text-align: center;">
        <form method="GET" style="display: inline-block;">
            <select name="category" class="form-control" style="width: 250px; display: inline-block;" onchange="this.form.submit()">
                <option value="">-- All Elements --</option>
                <?php foreach($elements as $el): ?>
                    <option value="<?= $el ?>" <?= $selected_category == $el ? 'selected' : '' ?>>
                        <?= htmlspecialchars($el) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div style="padding: 0 2rem 2rem 2rem;">
        <table class="table" style="margin: 0; border-bottom: 1px solid var(--border-color);">
            <thead style="border-bottom: 2px solid var(--text-primary);">
                <tr>
                    <th style="width: 10%; text-transform: uppercase; font-size: 0.75rem; font-weight: 700; color: var(--text-secondary);">Date</th>
                    <th style="width: 20%; text-transform: uppercase; font-size: 0.75rem; font-weight: 700; color: var(--text-secondary);">Account Title</th>
                    <th style="width: 15%; text-transform: uppercase; font-size: 0.75rem; font-weight: 700; color: var(--text-secondary);">Name</th>
                    <th style="width: 15%; text-transform: uppercase; font-size: 0.75rem; font-weight: 700; color: var(--text-secondary);">Description</th>
                    <th style="width: 10%; text-transform: uppercase; font-size: 0.75rem; font-weight: 700; color: var(--text-secondary);">Ref</th>
                    <th class="text-right" style="width: 10%; text-transform: uppercase; font-size: 0.75rem; font-weight: 700; color: var(--text-secondary);">Debit</th>
                    <th class="text-right" style="width: 10%; text-transform: uppercase; font-size: 0.75rem; font-weight: 700; color: var(--text-secondary);">Credit</th>
                    <th class="text-right" style="width: 10%; text-transform: uppercase; font-size: 0.75rem; font-weight: 700; color: var(--text-secondary);">Balance</th>
                </tr>
            </thead>
            <tbody>
<?php 
$displayedAny = false;

foreach ($display_categories as $category): 
    // Get Opening Balance
    $stmtOB->bind_param('is', $company_id, $category);
    $stmtOB->execute();
    $obRow = $stmtOB->get_result()->fetch_assoc();
    $runningBalance = (float)($obRow['total_ob'] ?? 0);

    // Get Lines
    $stmtLines->bind_param('is', $company_id, $category);
    $stmtLines->execute();
    $lines = $stmtLines->get_result()->fetch_all(MYSQLI_ASSOC);

    // Only show if there's activity or non-zero opening balance
    if (!$selected_category && count($lines) === 0 && $runningBalance == 0) {
        continue;
    }
    
    $displayedAny = true;
?>
                <!-- Category Header -->
                <tr>
                    <td colspan="8" style="padding: 1.5rem 0.5rem 0.5rem 0.5rem; font-weight: 700; font-size: 1rem; color: var(--text-primary); text-transform: uppercase; border-bottom: 1px solid var(--border-color);">
                        ELEMENT: <?= htmlspecialchars($category) ?>
                    </td>
                </tr>

                <!-- Opening Balance Row -->
                <tr>
                    <td colspan="7" class="text-right" style="color: var(--text-secondary); font-size: 0.85rem; padding: 0.75rem 0.5rem;">
                        Opening Balance
                    </td>
                    <td class="text-right" style="font-weight: 600; padding: 0.75rem 0.5rem;">
                        <?= $runningBalance != 0 ? number_format($runningBalance, 2) : '-' ?>
                    </td>
                </tr>
                
                <!-- Transaction Lines -->
                <?php foreach ($lines as $line): 
                    if ($category === 'Assets' || $category === 'Expenses') {
                        $runningBalance += $line['debit'];
                        $runningBalance -= $line['credit'];
                    } else {
                        $runningBalance += $line['credit'];
                        $runningBalance -= $line['debit'];
                    }
                ?>
                <tr>
                    <td style="color: var(--text-secondary); font-size: 0.85rem; padding: 0.75rem 0.5rem; white-space: nowrap;">
                        <?= date('m/d/Y', strtotime($line['date'])) ?>
                    </td>
                    <td style="padding: 0.75rem 0.5rem;">
                        <span style="color: var(--text-secondary); font-size: 0.8rem; margin-right: 0.25rem;"><?= htmlspecialchars($line['code']) ?></span>
                        <?= htmlspecialchars($line['account_name']) ?>
                    </td>
                    <td style="color: var(--text-secondary); font-size: 0.85rem; padding: 0.75rem 0.5rem;">
                        <?= htmlspecialchars($line['vendor_name'] ?? '') ?>
                    </td>
                    <td style="color: var(--text-secondary); font-size: 0.85rem; padding: 0.75rem 0.5rem;">
                        <?= htmlspecialchars($line['description'] ?? '') ?>
                    </td>
                    <td style="font-size: 0.8rem; color: var(--text-secondary); padding: 0.75rem 0.5rem;">
                        <?= htmlspecialchars($line['reference_no'] ?? '') ?>
                    </td>
                    <td class="text-right" style="padding: 0.75rem 0.5rem;">
                        <?= $line['debit'] > 0 ? number_format($line['debit'], 2) : '' ?>
                    </td>
                    <td class="text-right" style="padding: 0.75rem 0.5rem;">
                        <?= $line['credit'] > 0 ? number_format($line['credit'], 2) : '' ?>
                    </td>
                    <td class="text-right" style="font-weight: 500; padding: 0.75rem 0.5rem;">
                        <?= number_format($runningBalance, 2) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <!-- Ending Balance Row -->
                <tr>
                    <td colspan="7" class="text-right" style="padding: 0.75rem 0.5rem; font-weight: 600; font-size: 0.85rem;">
                        Ending Balance
                    </td>
                    <td class="text-right" style="padding: 0.75rem 0.5rem; font-weight: 700; font-size: 0.95rem; border-top: 1px solid var(--border-color); border-bottom: 3px double var(--text-primary);">
                        <?= number_format($runningBalance, 2) ?>
                    </td>
                </tr>
                
                <!-- Spacer -->
                <tr><td colspan="8" style="height: 1.5rem; border: none;"></td></tr>
                
<?php endforeach; ?>

            <?php if (!$displayedAny): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted" style="padding: 3rem 1rem;">
                        No transactions found for the selected element.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
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
