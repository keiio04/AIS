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
    SELECT l.debit, l.credit, e.date, e.description, e.reference_no, a.code, a.name as account_name
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

?>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">General Ledger</h1>
        <p class="page-subtitle">Track running balances per accounting element.</p>
    </div>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <form method="GET" style="max-width: 400px;">
        <label class="form-label">Filter by Element</label>
        <select name="category" class="form-control" onchange="this.form.submit()">
            <option value="">-- All Elements --</option>
            <?php foreach($elements as $el): ?>
                <option value="<?= $el ?>" <?= $selected_category == $el ? 'selected' : '' ?>>
                    <?= htmlspecialchars($el) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="card" style="padding: 0; margin-bottom: 1.5rem; overflow: hidden;">
    <div class="table-container">
        <table class="table" style="margin: 0;">
            <thead>
                <tr>
                    <th style="width: 12%">Date</th>
                    <th style="width: 20%">Account</th>
                    <th style="width: 25%">Description</th>
                    <th style="width: 13%">Ref No.</th>
                    <th class="text-right" style="width: 10%">Debit</th>
                    <th class="text-right" style="width: 10%">Credit</th>
                    <th class="text-right" style="width: 10%">Balance</th>
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
                <!-- Category Header Row -->
                <tr>
                    <td colspan="7" style="padding: 2rem 1rem 1rem 1rem; border-bottom: 2px solid var(--primary-color); background: transparent;">
                        <h2 style="font-size: 1.25rem; margin: 0; font-weight: 800; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i data-lucide="folder-open" style="width: 22px; height: 22px;"></i>
                            <?= htmlspecialchars($category) ?>
                        </h2>
                    </td>
                </tr>

                <!-- Opening Balance Row -->
                <tr style="background: rgba(59, 130, 246, 0.04);">
                    <td colspan="6" class="text-right" style="font-weight: 600; color: var(--text-muted); font-size: 0.85rem; padding: 1rem 1.5rem; text-transform: uppercase; letter-spacing: 0.03em;">
                        Opening Balance
                    </td>
                    <td class="text-right" style="font-weight: 700; color: var(--text-primary); padding: 1rem 1.5rem; font-size: 0.95rem;">
                        ₱<?= number_format($runningBalance, 2) ?>
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
                <tr style="transition: all 0.2s ease; border-bottom: 1px solid var(--border-color);">
                    <td style="color: var(--text-muted); font-size: 0.85rem; padding: 1rem 1.5rem;">
                        <?= date('M d, Y', strtotime($line['date'])) ?>
                    </td>
                    <td style="padding: 1rem 1.5rem;">
                        <span style="display: inline-block; background: var(--bg-tertiary); padding: 0.15rem 0.4rem; border-radius: 4px; color: var(--text-muted); font-family: monospace; font-size: 0.75rem; margin-bottom: 0.3rem;">
                            <?= htmlspecialchars($line['code']) ?>
                        </span><br>
                        <strong style="font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">
                            <?= htmlspecialchars($line['account_name']) ?>
                        </strong>
                    </td>
                    <td style="color: var(--text-muted); font-size: 0.85rem; padding: 1rem 1.5rem;">
                        <?= htmlspecialchars($line['description'] ?? '') ?>
                    </td>
                    <td style="font-family: monospace; font-size: 0.8rem; color: var(--text-muted); padding: 1rem 1.5rem;">
                        <?= htmlspecialchars($line['reference_no'] ?? '') ?>
                    </td>
                    <td class="text-right" style="padding: 1rem 1.5rem; color: var(--text-primary); font-weight: 500;">
                        <?= $line['debit'] > 0 ? '₱'.number_format($line['debit'], 2) : '<span style="color: var(--border-color);">-</span>' ?>
                    </td>
                    <td class="text-right" style="padding: 1rem 1.5rem; color: var(--text-primary); font-weight: 500;">
                        <?= $line['credit'] > 0 ? '₱'.number_format($line['credit'], 2) : '<span style="color: var(--border-color);">-</span>' ?>
                    </td>
                    <td class="text-right" style="font-weight: 700; font-size: 0.95rem; color: var(--text-primary); padding: 1rem 1.5rem; background: rgba(0,0,0,0.015);">
                        ₱<?= number_format($runningBalance, 2) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <!-- Ending Balance Row -->
                <tr style="border-top: 2px solid var(--border-color); background: rgba(59, 130, 246, 0.08);">
                    <td colspan="6" class="text-right" style="padding: 1.25rem 1.5rem; font-weight: 700; color: var(--primary-color); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.05em;">
                        Ending Balance
                    </td>
                    <td class="text-right" style="padding: 1.25rem 1.5rem; font-weight: 800; color: var(--primary-color); font-size: 1.1rem;">
                        ₱<?= number_format($runningBalance, 2) ?>
                    </td>
                </tr>
                
                <!-- Spacer -->
                <tr><td colspan="7" style="height: 3rem; border: none; background: transparent;"></td></tr>
                
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

<?php require_once '../includes/footer.php'; ?>
