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

// Fetch all accounts for filter dropdown
$stmt = $db->prepare("SELECT id, code, name FROM accounts WHERE company_id = ? ORDER BY code ASC");
$stmt->bind_param('i', $company_id);
$stmt->execute();
$allAccounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$selected_account_id = $_GET['account_id'] ?? '';

// Build query for accounts to display
$accQuery = "SELECT * FROM accounts WHERE company_id = ?";
$params = [$company_id];
$types = "i";

if ($selected_account_id) {
    $accQuery .= " AND id = ?";
    $params[] = $selected_account_id;
    $types .= "i";
}
$accQuery .= " ORDER BY code ASC";

$stmtAcc = $db->prepare($accQuery);
$stmtAcc->bind_param($types, ...$params);
$stmtAcc->execute();
$accounts = $stmtAcc->get_result()->fetch_all(MYSQLI_ASSOC);

// Prepare statement for fetching entries for a specific account
$stmtLines = $db->prepare("
    SELECT l.debit, l.credit, e.date, e.description, e.reference_no
    FROM journal_entry_lines l
    JOIN journal_entries e ON l.journal_entry_id = e.id
    WHERE l.account_id = ? AND e.deleted_at IS NULL
    ORDER BY e.date ASC, e.id ASC
");

?>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">General Ledger</h1>
        <p class="page-subtitle">Track running balances for all accounts.</p>
    </div>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <form method="GET" style="max-width: 400px;">
        <label class="form-label">Filter by Account</label>
        <select name="account_id" class="form-control" onchange="this.form.submit()">
            <option value="">-- All Accounts with Entries --</option>
            <?php foreach($allAccounts as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $selected_account_id == $a['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($a['code'] . ' - ' . $a['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php 
$displayedAny = false;

foreach ($accounts as $acc): 
    $stmtLines->bind_param('i', $acc['id']);
    $stmtLines->execute();
    $lines = $stmtLines->get_result()->fetch_all(MYSQLI_ASSOC);

    // Only show accounts with activity OR non-zero opening balance (if no specific account selected)
    if (!$selected_account_id && count($lines) === 0 && $acc['opening_balance'] == 0) {
        continue;
    }
    
    $displayedAny = true;
    $runningBalance = $acc['opening_balance'];
?>
<div class="card" style="padding: 0; margin-bottom: 1.5rem; overflow: hidden;">
    <div style="padding: 1.5rem; border-bottom: 1px solid var(--border-color); background-color: var(--bg-secondary);">
        <h2 style="font-size: 1.25rem; margin-bottom: 0.25rem;">
            <?= htmlspecialchars($acc['code'] . ' - ' . $acc['name']) ?>
        </h2>
        <div class="text-muted" style="font-size: 0.875rem;">
            Category: <?= htmlspecialchars($acc['category']) ?> <?= $acc['sub_category'] ? ' > ' . htmlspecialchars($acc['sub_category']) : '' ?>
        </div>
    </div>
    
    <div class="table-container">
        <table class="table" style="margin: 0;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Ref (General Journal Page)</th>
                    <th class="text-right">Debit</th>
                    <th class="text-right">Credit</th>
                    <th class="text-right">Balance</th>
                </tr>
            </thead>
            <tbody>
                <!-- Opening Balance Row -->
                <tr style="background-color: var(--bg-tertiary); font-weight: 600;">
                    <td>-</td>
                    <td>Opening Balance</td>
                    <td style="font-family: monospace; font-size: 0.8rem;">-</td>
                    <td class="text-right"></td>
                    <td class="text-right"></td>
                    <td class="text-right">₱<?= number_format($runningBalance, 2) ?></td>
                </tr>
                
                <!-- Transaction Lines -->
                <?php foreach ($lines as $line): 
                    if ($acc['category'] === 'Assets' || $acc['category'] === 'Expenses') {
                        $runningBalance += $line['debit'];
                        $runningBalance -= $line['credit'];
                    } else {
                        $runningBalance += $line['credit'];
                        $runningBalance -= $line['debit'];
                    }
                ?>
                <tr>
                    <td><?= $line['date'] ?></td>
                    <td><?= htmlspecialchars($line['description']) ?></td>
                    <td style="font-family: monospace; font-size: 0.8rem;"><?= htmlspecialchars($line['reference_no']) ?></td>
                    <td class="text-right"><?= $line['debit'] > 0 ? '₱'.number_format($line['debit'], 2) : '' ?></td>
                    <td class="text-right"><?= $line['credit'] > 0 ? '₱'.number_format($line['credit'], 2) : '' ?></td>
                    <td class="text-right" style="font-weight: 500;">₱<?= number_format($runningBalance, 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php if (!$displayedAny): ?>
<div class="card" style="text-align: center; padding: 3rem;">
    <p class="text-muted">No journal entries found in the ledger yet.</p>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
