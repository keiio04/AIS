<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/header.php';

$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to view the trial balance.</div>';
    require_once '../includes/footer.php';
    exit;
}

// Fetch all accounts with their sum of debits and credits
$query = "
    SELECT 
        a.id, a.code, a.name, a.category, a.opening_balance,
        SUM(IFNULL(l.debit, 0)) as total_dr,
        SUM(IFNULL(l.credit, 0)) as total_cr
    FROM accounts a
    LEFT JOIN (journal_entry_lines l JOIN journal_entries e ON l.journal_entry_id = e.id AND e.deleted_at IS NULL) ON a.id = l.account_id
    WHERE a.company_id = ?
    GROUP BY a.id
    ORDER BY a.code ASC
";
$stmt = $db->prepare($query);
$stmt->bind_param('i', $company_id);
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$total_debit_balance = 0;
$total_credit_balance = 0;
$has_activity = false;

$tb_rows = [];

foreach ($accounts as $acc) {
    $dr = $acc['total_dr'];
    $cr = $acc['total_cr'];
    
    // Calculate final balance based on normal balances
    $balance = 0;
    $is_debit = true; // Does this account normally have a debit balance?

    if ($acc['category'] === 'Assets' || $acc['category'] === 'Expenses') {
        $balance = $acc['opening_balance'] + $dr - $cr;
        $is_debit = true;
    } else {
        $balance = $acc['opening_balance'] - $dr + $cr;
        $is_debit = false;
    }

    if ($balance != 0) {
        $has_activity = true;
        
        // If an asset goes negative, it effectively becomes a credit balance, etc.
        $actual_is_debit = ($balance > 0) ? $is_debit : !$is_debit;
        $abs_balance = abs($balance);

        if ($actual_is_debit) {
            $total_debit_balance += $abs_balance;
        } else {
            $total_credit_balance += $abs_balance;
        }

        $tb_rows[] = [
            'code' => $acc['code'],
            'name' => $acc['name'],
            'debit' => $actual_is_debit ? $abs_balance : 0,
            'credit' => !$actual_is_debit ? $abs_balance : 0,
        ];
    }
}

// Check if balanced
$isBalanced = abs($total_debit_balance - $total_credit_balance) < 0.01;

?>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Trial Balance</h1>
        <p class="page-subtitle">Verify the equality of debits and credits for all accounts.</p>
    </div>
    <button class="btn btn-secondary" onclick="window.print()">
        <i data-lucide="printer" style="width:15px;height:15px;"></i> Print
    </button>
</div>

<div class="card" style="padding: 0; margin-bottom: 2rem; overflow: hidden; box-shadow: none; border: 1px solid var(--border-color); background: transparent;">
    <div style="padding: 2rem; border-bottom: 1px solid var(--border-color); text-align: center;">
        <h2 style="font-size: 1.5rem; margin-bottom: 0.5rem;"><?= htmlspecialchars($activeCompanyName ?? 'Company') ?></h2>
        <h3 style="font-size: 1.125rem; color: var(--text-secondary); margin-bottom: 0.25rem;">Trial Balance</h3>
        <p class="text-muted" style="font-size: 0.875rem;">As of <?= date('F j, Y') ?></p>
    </div>

    <?php if ($has_activity): ?>
        <table class="table" style="margin: 0;">
            <thead>
                <tr>
                    <th style="width: 15%;">Account Code</th>
                    <th style="width: 45%;">Account Name</th>
                    <th class="text-right" style="width: 20%;">Debit</th>
                    <th class="text-right" style="width: 20%;">Credit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tb_rows as $row): ?>
                <tr>
                    <td style="font-family: monospace; font-size: 0.85rem; color: var(--primary-color);"><?= htmlspecialchars($row['code']) ?></td>
                    <td style="font-weight: 500;"><?= htmlspecialchars($row['name']) ?></td>
                    <td class="text-right"><?= $row['debit'] > 0 ? '₱'.number_format($row['debit'], 2) : '' ?></td>
                    <td class="text-right"><?= $row['credit'] > 0 ? '₱'.number_format($row['credit'], 2) : '' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2" class="text-right" style="font-weight: 700; font-size: 1rem;">Totals:</td>
                    <td class="text-right" style="font-weight: 700; font-size: 1rem; border-top: 2px solid var(--border-color); border-bottom: 4px double var(--border-color); color: <?= $isBalanced ? 'var(--text-primary)' : 'var(--danger-color)' ?>;">
                        ₱<?= number_format($total_debit_balance, 2) ?>
                    </td>
                    <td class="text-right" style="font-weight: 700; font-size: 1rem; border-top: 2px solid var(--border-color); border-bottom: 4px double var(--border-color); color: <?= $isBalanced ? 'var(--text-primary)' : 'var(--danger-color)' ?>;">
                        ₱<?= number_format($total_credit_balance, 2) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
        
        <?php if (!$isBalanced): ?>
        <div style="padding: 1.5rem; background-color: var(--bg-secondary); border-top: 1px solid var(--border-color); text-align: center;">
            <div style="color: var(--danger-color); font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; background: #fef2f2; padding: 0.75rem 1.5rem; border-radius: 8px;">
                <i data-lucide="alert-triangle" style="width:18px;height:18px;"></i>
                Warning: The trial balance is out of balance by ₱<?= number_format(abs($total_debit_balance - $total_credit_balance), 2) ?>
            </div>
        </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div style="text-align: center; padding: 3rem;">
            <p class="text-muted">No accounts have balances yet.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Print Styles -->
<style>
@media print {
    body * { visibility: hidden; }
    .card, .card * { visibility: visible; }
    .card { position: absolute; left: 0; top: 0; width: 100%; box-shadow: none !important; border: none !important; }
    .page-header, .sidebar, .topbar { display: none !important; }
    .main-wrapper { padding: 0 !important; margin: 0 !important; }
}
</style>

<?php require_once '../includes/footer.php'; ?>
