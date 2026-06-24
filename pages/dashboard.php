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
$stmt2 = $db->prepare("
    SELECT e.*, 
           (SELECT SUM(debit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_debit,
           (SELECT SUM(credit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_credit
    FROM journal_entries e 
    WHERE e.company_id = ? AND e.deleted_at IS NULL 
    ORDER BY date DESC, id DESC 
    LIMIT 3
");
$stmt2->bind_param('i', $company_id);
$stmt2->execute();
$recent = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

// Cash Flow Data
$stmt3 = $db->prepare("
    SELECT 
        DATE_FORMAT(e.date, '%b %d') as day,
        SUM(CASE WHEN l.debit > 0 THEN l.debit ELSE 0 END) as cash_in,
        SUM(CASE WHEN l.credit > 0 THEN l.credit ELSE 0 END) as cash_out
    FROM journal_entries e
    JOIN journal_entry_lines l ON e.id = l.journal_entry_id
    JOIN accounts a ON l.account_id = a.id
    WHERE e.company_id = ? AND e.deleted_at IS NULL AND a.name LIKE '%cash%'
    GROUP BY e.date
    ORDER BY e.date ASC
    LIMIT 14
");
$stmt3->bind_param('i', $company_id);
$stmt3->execute();
$cashFlowRes = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);

$cfLabels = []; $cfIn = []; $cfOut = [];
foreach($cashFlowRes as $cf) {
    $cfLabels[] = $cf['day'];
    $cfIn[] = $cf['cash_in'];
    $cfOut[] = $cf['cash_out'];
}

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
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
    <?php
    $metrics = [
        ['label'=>'Total Revenue', 'val'=>$revenue, 'icon'=>'trending-up', 'col'=>'#10b981', 'bg'=>'#f0fdf4'],
        ['label'=>'Total Expenses', 'val'=>$expenses, 'icon'=>'trending-down', 'col'=>'#f59e0b', 'bg'=>'#fffbeb'],
        ['label'=>'Net Income', 'val'=>$net_income, 'icon'=>'pie-chart', 'col'=>($net_income>=0?'#3b82f6':'#ef4444'), 'bg'=>($net_income>=0?'#eff6ff':'#fef2f2')],
        ['label'=>'Cash Balance', 'val'=>$cash, 'icon'=>'wallet', 'col'=>'#06b6d4', 'bg'=>'#ecfeff'],
    ];
    foreach($metrics as $m): ?>
    <div class="metric-card" style="border-top: 3px solid <?= $m['col'] ?>; padding: 1.25rem; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); background: white; display: flex; flex-direction: column; gap: 0.75rem;">
        <div class="metric-card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <span class="metric-card-label" style="text-transform: uppercase; font-size: 0.75rem; font-weight: 700; color: #64748b; letter-spacing: 0.05em;"><?= $m['label'] ?></span>
            <div class="metric-card-icon" style="background-color: <?= $m['bg'] ?>; color: <?= $m['col'] ?>">
                <i data-lucide="<?= $m['icon'] ?>" style="width:18px;height:18px;"></i>
            </div>
        </div>
        <div class="metric-card-value" style="font-size: 1.75rem; font-weight: 700; color: #1e293b;"><?= fmt($m['val']) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts Row -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
    <div class="card" style="padding: 1.5rem;">
        <h3 style="font-size: 0.9375rem;">Revenue vs Expenses</h3>
        <p class="text-xs text-muted mt-1 mb-4">Current period overview</p>
        <div style="position: relative; height: 240px; width: 100%;">
            <canvas id="revExpChart"></canvas>
        </div>
    </div>
    <div class="card" style="padding: 1.5rem;">
        <h3 style="font-size: 0.9375rem;">Asset Distribution</h3>
        <p class="text-xs text-muted mt-1 mb-4">Breakdown by asset type</p>
        <div style="position: relative; height: 240px; width: 100%;">
            <canvas id="assetChart"></canvas>
        </div>
    </div>
</div>

<!-- Cash Flow Trend -->
<div class="card" style="padding: 1.5rem; margin-bottom: 1.25rem;">
    <h3 style="font-size: 0.9375rem;">Cash Flow Trend</h3>
    <p class="text-xs text-muted mt-1 mb-4">Monthly cash inflows vs outflows</p>
    <div style="position: relative; height: 240px; width: 100%;">
        <canvas id="cashFlowChart"></canvas>
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
                <th style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Date</th>
                <th style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Description</th>
                <th style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Type</th>
                <th style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase; text-align: right;">Debit</th>
                <th style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase; text-align: right;">Credit</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($recent as $tx): ?>
            <tr>
                <td style="font-size: 0.8125rem; color: var(--text-muted);"><?= $tx['date'] ?></td>
                <td style="font-weight: 500; font-size: 0.875rem;"><?= htmlspecialchars($tx['description']) ?></td>
                <td>
                    <?php 
                    $cls = 'neutral';
                    if($tx['type']==='Operating') $cls='primary';
                    if($tx['type']==='Investing') $cls='warning';
                    if($tx['type']==='Financing') $cls='success';
                    ?>
                    <span class="badge badge-<?= $cls ?>" style="border-radius: 9999px; font-weight: 600; padding: 0.25rem 0.6rem; text-transform: uppercase; font-size: 0.65rem; border: 1px solid rgba(0,0,0,0.05); background-color: var(--<?= $cls ?>-color-light, #f1f5f9); color: var(--<?= $cls ?>-color, #475569);"><?= $tx['type'] ?></span>
                </td>
                <td style="text-align: right; color: #10b981; font-weight: 600; font-size: 0.875rem;"><?= fmt($tx['total_debit'] ?? 0) ?></td>
                <td style="text-align: right; color: #ef4444; font-weight: 600; font-size: 0.875rem;"><?= fmt($tx['total_credit'] ?? 0) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(count($recent)===0): ?>
            <tr><td colspan="5" class="text-center text-muted" style="padding: 2rem;">No transactions yet.</td></tr>
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

const cfLabels = <?= json_encode($cfLabels) ?>;
const cfIn = <?= json_encode($cfIn) ?>;
const cfOut = <?= json_encode($cfOut) ?>;

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

// Cash Flow Chart
const ctxCashFlow = document.getElementById('cashFlowChart');
if(ctxCashFlow) {
    new Chart(ctxCashFlow, {
        type: 'line',
        data: {
            labels: cfLabels.length ? cfLabels : ['No Data'],
            datasets: [
                { label: 'Cash In', data: cfIn.length ? cfIn : [0], borderColor: '#3b82f6', backgroundColor: '#3b82f6', fill: false, tension: 0.3, pointRadius: 4 },
                { label: 'Cash Out', data: cfOut.length ? cfOut : [0], borderColor: '#ef4444', backgroundColor: '#ef4444', fill: false, tension: 0.3, pointRadius: 4 }
            ]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            scales: { y: { beginAtZero: true, grid: { borderDash: [2, 4] } }, x: { grid: { display: false } } }, 
            plugins: { legend: { position: 'bottom' } } 
        }
    });
}
</script>

<?php require_once '../includes/footer.php'; ?>
