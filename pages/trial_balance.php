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

<div class="no-print" style="display: flex; justify-content: flex-end; margin-bottom: 0.75rem;">
    <button class="btn btn-secondary" onclick="window.print()">
        <i data-lucide="printer" style="width:15px;height:15px;"></i> Print
    </button>
</div>

<div id="printable-area" style="width: 100%; margin-bottom: 2rem;">
    <div style="padding: 1rem 0 1.5rem 0; text-align: center;">
        <h2 style="font-size: 1.5rem; margin-bottom: 0.35rem; font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($activeCompanyName ?? 'Company') ?></h2>
        <h3 style="font-size: 1.05rem; color: var(--text-secondary); margin-bottom: 0.25rem; font-weight: 600;">Trial Balance</h3>
        <p class="text-muted" style="font-size: 0.85rem; margin: 0;">As of <?= date('F j, Y') ?></p>
    </div>

    <?php if ($has_activity): ?>
        <div class="table-container">
            <table class="table compact-table" style="margin: 0; width: 100%; border: none; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--text-primary); background: var(--bg-tertiary);">
                        <th style="min-width: 110px; width: 20%; padding-left: 0.5rem; border: none; border-bottom: 1.5px solid var(--border-color);" class="nowrap">Account Code</th>
                        <th style="min-width: 200px; width: 50%; border: none; border-bottom: 1.5px solid var(--border-color);">Account Title</th>
                        <th class="text-right nowrap" style="min-width: 110px; width: 15%; border: none; border-bottom: 1.5px solid var(--border-color);">Debit</th>
                        <th class="text-right nowrap" style="min-width: 110px; width: 15%; padding-right: 0.5rem; border: none; border-bottom: 1.5px solid var(--border-color);">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tb_rows as $row): ?>
                    <tr>
                        <td style="font-family: monospace; font-weight: 600; font-size: 0.78rem; padding-left: 0.5rem; color: var(--primary-color);"><?= htmlspecialchars($row['code']) ?></td>
                        <td style="font-weight: 500; font-size: 0.8125rem;"><?= htmlspecialchars($row['name']) ?></td>
                        <td class="text-right" style="font-size: 0.8125rem; font-variant-numeric: tabular-nums;"><?= $row['debit'] > 0 ? number_format($row['debit'], 2) : '—' ?></td>
                        <td class="text-right" style="padding-right: 0.5rem; font-size: 0.8125rem; font-variant-numeric: tabular-nums;"><?= $row['credit'] > 0 ? number_format($row['credit'], 2) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: 700; border-top: 1.5px solid var(--border-color);">
                        <td colspan="2" style="padding-left: 0.5rem; font-size: 0.8125rem;">Total</td>
                        <td class="text-right" style="border-bottom: 3px double var(--text-primary); font-size: 0.85rem; font-variant-numeric: tabular-nums;"><?= number_format($total_debit_balance, 2) ?></td>
                        <td class="text-right" style="padding-right: 0.5rem; border-bottom: 3px double var(--text-primary); font-size: 0.85rem; font-variant-numeric: tabular-nums;"><?= number_format($total_credit_balance, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        
        <?php if (!$isBalanced): ?>
        <div style="padding: 1.25rem 0; text-align: center;">
            <div style="color: var(--danger-color); font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; background: #fef2f2; padding: 0.65rem 1.25rem; border-radius: 8px; font-size: 0.8125rem;">
                <i data-lucide="alert-triangle" style="width:16px;height:16px;"></i>
                Warning: The trial balance is out of balance by ₱<?= number_format(abs($total_debit_balance - $total_credit_balance), 2) ?>
            </div>
        </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div style="text-align: center; padding: 3rem 1rem;">
            <p class="text-muted" style="font-size: 0.8125rem;">No accounts have balances yet.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Print Styles -->
<style>
@media print {
    body * { visibility: hidden; }
    #printable-area, #printable-area * { visibility: visible; }
    #printable-area { position: absolute; left: 0; top: 0; width: 100%; box-shadow: none !important; border: none !important; padding: 0 !important; }
    .page-header, .sidebar, .topbar { display: none !important; }
    .main-wrapper { padding: 0 !important; margin: 0 !important; }
}
</style>

<?php require_once '../includes/footer.php'; ?>
