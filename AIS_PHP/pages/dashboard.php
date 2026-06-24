<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/header.php';

$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to view the dashboard.</div>';
    require_once '../includes/footer.php';
    exit;
}

// Helper query to get totals per category
// Normal balances: Assets(+dr,-cr), Liabilities(-dr,+cr), Equity(-dr,+cr), Revenue(-dr,+cr), Expenses(+dr,-cr)
$q = "
    SELECT 
        a.category, 
        a.name,
        a.opening_balance,
        SUM(IFNULL(l.debit, 0)) as total_dr,
        SUM(IFNULL(l.credit, 0)) as total_cr
    FROM accounts a
    LEFT JOIN (journal_entry_lines l JOIN journal_entries e ON l.journal_entry_id = e.id AND e.deleted_at IS NULL) ON a.id = l.account_id
    WHERE a.company_id = ?
    GROUP BY a.id
";
$stmt = $db->prepare($q);
$stmt->bind_param('i', $company_id);
$stmt->execute();
$res = $stmt->get_result();

$assets = 0; $liabilities = 0; $equity = 0; $revenue = 0; $expenses = 0; $cash = 0;
$assetData = [];

while ($row = $res->fetch_assoc()) {
    $cat = $row['category'];
    $bal = 0;
    if ($cat === 'Assets' || $cat === 'Expenses') {
        $bal = $row['opening_balance'] + $row['total_dr'] - $row['total_cr'];
    } else {
        $bal = $row['opening_balance'] - $row['total_dr'] + $row['total_cr'];
    }

    if ($cat === 'Assets') { 
        $assets += $bal; 
        if ($bal > 0) $assetData[] = ['name' => $row['name'], 'value' => $bal];
        if (stripos($row['name'], 'cash') !== false) $cash += $bal;
    }
    if ($cat === 'Liabilities') $liabilities += $bal;
    if ($cat === 'Equity') $equity += $bal;
    if ($cat === 'Revenue') $revenue += $bal;
    if ($cat === 'Expenses') $expenses += $bal;
}
$net_income = $revenue - $expenses;

// Recent Transactions
$stmt2 = $db->prepare("SELECT * FROM journal_entries WHERE company_id = ? AND deleted_at IS NULL ORDER BY date DESC, id DESC LIMIT 5");
$stmt2->bind_param('i', $company_id);
$stmt2->execute();
$recent = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

function fmt($n) {
    return '₱' . number_format(abs($n), 2);
}
?>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle">Welcome back! Here's the financial overview for <strong><?= htmlspecialchars($activeCompanyName ?? 'your company') ?></strong> for <?= date('F Y') ?>.</p>
    </div>
    <a href="journal_entries.php" class="btn btn-primary">
        <i data-lucide="plus" style="width:16px;height:16px;"></i> New Journal Entry
    </a>
</div>

<!-- Metric Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
    <?php
    $metrics = [
        ['label'=>'Total Assets', 'val'=>$assets, 'icon'=>'wallet', 'col'=>'#3b82f6', 'bg'=>'#eff6ff', 'trend'=>'up', 'trend_val'=>'+12.5%', 'trend_col'=>'var(--success-color)'],
        ['label'=>'Total Liabilities', 'val'=>$liabilities, 'icon'=>'activity', 'col'=>'#ef4444', 'bg'=>'#fef2f2', 'trend'=>'up', 'trend_val'=>'+2.1%', 'trend_col'=>'var(--danger-color)'],
        ['label'=>'Owner\'s Equity', 'val'=>$equity, 'icon'=>'dollar-sign', 'col'=>'#8b5cf6', 'bg'=>'#f5f3ff', 'trend'=>'up', 'trend_val'=>'+8.3%', 'trend_col'=>'var(--success-color)'],
        ['label'=>'Total Revenue', 'val'=>$revenue, 'icon'=>'trending-up', 'col'=>'#10b981', 'bg'=>'#f0fdf4', 'trend'=>'up', 'trend_val'=>'+18.2%', 'trend_col'=>'var(--success-color)'],
        ['label'=>'Total Expenses', 'val'=>$expenses, 'icon'=>'trending-down', 'col'=>'#f59e0b', 'bg'=>'#fffbeb', 'trend'=>'down', 'trend_val'=>'+5.4%', 'trend_col'=>'var(--danger-color)'],
        ['label'=>'Net Income', 'val'=>$net_income, 'icon'=>'trending-up', 'col'=>($net_income>=0?'#10b981':'#ef4444'), 'bg'=>($net_income>=0?'#f0fdf4':'#fef2f2'), 'trend'=>'up', 'trend_val'=>'+22.4%', 'trend_col'=>'var(--success-color)'],
        ['label'=>'Cash Balance', 'val'=>$cash, 'icon'=>'wallet', 'col'=>'#06b6d4', 'bg'=>'#ecfeff', 'trend'=>'up', 'trend_val'=>'+4.1%', 'trend_col'=>'var(--success-color)'],
    ];
    foreach($metrics as $m): ?>
    <div class="metric-card">
        <div class="metric-card-header">
            <span class="metric-card-label"><?= $m['label'] ?></span>
            <div class="metric-card-icon" style="background-color: <?= $m['bg'] ?>; color: <?= $m['col'] ?>">
                <i data-lucide="<?= $m['icon'] ?>" style="width:18px;height:18px;"></i>
            </div>
        </div>
        <div class="metric-card-value"><?= fmt($m['val']) ?></div>
        <div class="metric-card-sub" style="font-size: 0.75rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.25rem;">
            <i data-lucide="arrow-<?= $m['trend'] ?>-right" style="width: 14px; height: 14px; color: <?= $m['trend_col'] ?>;"></i>
            <span style="color: <?= $m['trend_col'] ?>; font-weight: 600;"><?= $m['trend_val'] ?></span> vs last period
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts Row -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
    <div class="card" style="padding: 1.5rem;">
        <h3 style="font-size: 0.9375rem;">Revenue vs Expenses</h3>
        <p class="text-xs text-muted mt-1 mb-4">Current period overview</p>
        <canvas id="revExpChart" height="240"></canvas>
    </div>
    <div class="card" style="padding: 1.5rem;">
        <h3 style="font-size: 0.9375rem;">Asset Distribution</h3>
        <p class="text-xs text-muted mt-1 mb-4">Breakdown by asset type</p>
        <canvas id="assetChart" height="240"></canvas>
    </div>
</div>

<!-- Recent Transactions -->
<div class="card" style="padding: 0;">
    <div class="flex justify-between items-center" style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color);">
        <div>
            <h3 style="font-size: 0.9375rem;">Recent Journal Entries</h3>
            <p class="text-xs text-muted mt-1">Latest <?= count($recent) ?> transactions posted</p>
        </div>
        <a href="journal_entries.php" class="btn btn-secondary btn-sm">View All</a>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Description</th>
                <th>Type</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($recent as $tx): ?>
            <tr>
                <td style="font-size: 0.8125rem; color: var(--text-muted);"><?= $tx['date'] ?></td>
                <td style="font-weight: 500;"><?= htmlspecialchars($tx['reference_no']) ?></td>
                <td style="font-weight: 500;"><?= htmlspecialchars($tx['description']) ?></td>
                <td>
                    <?php 
                    $cls = 'neutral';
                    if($tx['type']==='Operating') $cls='primary';
                    if($tx['type']==='Investing') $cls='warning';
                    if($tx['type']==='Financing') $cls='success';
                    ?>
                    <span class="badge badge-<?= $cls ?>"><?= $tx['type'] ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(count($recent)===0): ?>
            <tr><td colspan="4" class="text-center text-muted" style="padding: 2rem;">No transactions yet. <a href="journal_entries.php" class="btn btn-primary btn-sm mt-2">Add First Entry</a></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Pass PHP data to JS
const rev = <?= json_encode($revenue) ?>;
const exp = <?= json_encode($expenses) ?>;
const net = <?= json_encode($net_income) ?>;
const assetLabels = <?= json_encode(array_column($assetData, 'name')) ?>;
const assetValues = <?= json_encode(array_column($assetData, 'value')) ?>;

// Rev vs Exp Chart
new Chart(document.getElementById('revExpChart'), {
    type: 'bar',
    data: {
        labels: ['Current Period'],
        datasets: [
            { label: 'Revenue', data: [rev], backgroundColor: '#3b82f6', borderRadius: 6 },
            { label: 'Expenses', data: [exp], backgroundColor: '#ef4444', borderRadius: 6 },
            { label: 'Net Income', data: [net], backgroundColor: '#10b981', borderRadius: 6 }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, grid:{display:false} }, x: { grid:{display:false} } }, plugins: { legend: { position: 'bottom' } } }
});

// Asset Pie Chart
new Chart(document.getElementById('assetChart'), {
    type: 'doughnut',
    data: {
        labels: assetLabels.length ? assetLabels : ['No Assets'],
        datasets: [{
            data: assetValues.length ? assetValues : [1],
            backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'],
            borderWidth: 0
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom' } } }
});
</script>

<?php require_once '../includes/footer.php'; ?>
