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

// === PERIOD FILTER ===
date_default_timezone_set('Asia/Manila');
$allowed_periods = ['this_month', 'last_month', 'last_30', 'last_90', 'this_year', 'all'];
$period = in_array($_GET['period'] ?? '', $allowed_periods) ? $_GET['period'] : 'this_month';
$today = date('Y-m-d');
switch ($period) {
    case 'last_month':
        $date_from = date('Y-m-01', strtotime('first day of last month'));
        $date_to   = date('Y-m-t',  strtotime('last day of last month'));
        $period_label = 'Last month';
        break;
    case 'last_30':
        $date_from = date('Y-m-d', strtotime('-30 days'));
        $date_to   = $today;
        $period_label = 'Last 30 days';
        break;
    case 'last_90':
        $date_from = date('Y-m-d', strtotime('-90 days'));
        $date_to   = $today;
        $period_label = 'Last 90 days';
        break;
    case 'this_year':
        $date_from = date('Y-01-01');
        $date_to   = $today;
        $period_label = 'This year';
        break;
    case 'all':
        $date_from = '1900-01-01';
        $date_to   = $today;
        $period_label = 'All time';
        break;
    default: // this_month
        $date_from = date('Y-m-01');
        $date_to   = $today;
        $period_label = date('F Y');
        break;
}

// Helper query to get totals per category
// Normal balances: Assets(+dr,-cr), Liabilities(-dr,+cr), Equity(-dr,+cr), Revenue(-dr,+cr), Expenses(+dr,-cr)
$q = "
    SELECT 
        a.category, 
        a.name,
        a.opening_balance,
        SUM(CASE WHEN e.date BETWEEN 
            IFNULL('$date_from', '1900-01-01') AND IFNULL('$date_to', '$today') 
            THEN IFNULL(l.debit, 0) ELSE 0 END) as total_dr,
        SUM(CASE WHEN e.date BETWEEN 
            IFNULL('$date_from', '1900-01-01') AND IFNULL('$date_to', '$today') 
            THEN IFNULL(l.credit, 0) ELSE 0 END) as total_cr
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

<?php
$hour = date('H');
if ($hour < 12) {
    $greeting = "Good morning";
} elseif ($hour < 18) {
    $greeting = "Good afternoon";
} else {
    $greeting = "Good evening";
}
$userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
?>
<div style="text-align: center; margin-bottom: 2.5rem; margin-top: 1rem;">
    <h2 style="font-size: 1.75rem; font-weight: 500; color: var(--text-primary); letter-spacing: -0.01em;"><?= $greeting ?>, <?= $userName ?>!</h2>
</div>

<!-- Metric Cards -->
<div id="metric-cards-grid" class="metric-cards-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
    <?php
    $periods_map = [
        'this_month' => date('F Y'),
        'last_month' => 'Last month',
        'last_30'    => 'Last 30 days',
        'last_90'    => 'Last 90 days',
        'this_year'  => 'This year',
        'all'        => 'All time',
    ];
    $metrics = [
        ['label'=>'Total Revenue',  'sub'=>$period_label, 'val'=>$revenue,    'icon'=>'trending-up',   'col'=>'#10b981', 'bg'=>'#f0fdf4', 'has_period'=>true],
        ['label'=>'Total Expenses', 'sub'=>$period_label, 'val'=>$expenses,   'icon'=>'trending-down', 'col'=>'#f59e0b', 'bg'=>'#fffbeb', 'has_period'=>true],
        ['label'=>'Net Income',     'sub'=>$period_label, 'val'=>$net_income, 'icon'=>'pie-chart',     'col'=>($net_income>=0?'#3b82f6':'#ef4444'), 'bg'=>($net_income>=0?'#eff6ff':'#fef2f2'), 'has_period'=>true],
        ['label'=>'Cash Balance',   'sub'=>'As of today', 'val'=>$cash,       'icon'=>'wallet',        'col'=>'#06b6d4', 'bg'=>'#ecfeff', 'has_period'=>false],
    ];
    foreach($metrics as $idx => $m): ?>
    <div class="metric-card" style="padding: 1.25rem; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); background: white; display: flex; flex-direction: column; gap: 0.75rem; position: relative;">
        <div class="metric-card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <span class="metric-card-label" style="text-transform: uppercase; font-size: 0.75rem; font-weight: 700; color: #64748b; letter-spacing: 0.05em;"><?= $m['label'] ?></span>
            <?php if ($m['has_period']): ?>
            <div style="position: relative;">
                <button onclick="togglePeriodDropdown(<?= $idx ?>)" style="background: none; border: 1px solid #e2e8f0; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 4px; font-size: 0.75rem; color: #374151; font-weight: 500; padding: 3px 8px; white-space: nowrap;">
                    <?= $m['sub'] ?> <i data-lucide="chevron-down" style="width:11px;height:11px;"></i>
                </button>
                <div id="period-dd-<?= $idx ?>" style="display:none; position:absolute; top: calc(100% + 4px); right: 0; z-index: 200; background: white; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); min-width: 150px; overflow: hidden;">
                    <?php foreach ($periods_map as $key => $lbl): ?>
                    <a href="?period=<?= $key ?>" style="display: block; padding: 0.5rem 0.9rem; font-size: 0.8rem; color: <?= $period === $key ? '#3b82f6' : '#374151' ?>; font-weight: <?= $period === $key ? '600' : '400' ?>; text-decoration: none; background: <?= $period === $key ? '#eff6ff' : 'transparent' ?>;"
                       onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='<?= $period === $key ? '#eff6ff' : 'transparent' ?>'">
                        <?= $lbl ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <span style="font-size: 0.72rem; color: #94a3b8; font-weight: 500;">As of today</span>
            <?php endif; ?>
        </div>
        <div class="metric-card-value" style="font-size: 1.75rem; font-weight: 700; color: #1e293b;"><?= fmt($m['val']) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Backdrop -->
<div id="widgetBackdrop" class="widget-backdrop" onclick="closeAllWidgets()"></div>

<!-- Widgets Container -->
<div id="dashboard-widgets" style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
    <!-- Chart 1 -->
    <div class="card widget" style="padding: 1.5rem; display: flex; flex-direction: column;">
        <div style="margin-bottom: 1rem;">
            <h3 style="font-size: 0.9375rem;">Revenue vs Expenses</h3>
            <p class="text-xs text-muted mt-1">Current period overview</p>
        </div>
        <div class="chart-container" style="position: relative; height: 240px; width: 100%; overflow: hidden;">
            <canvas id="revExpChart"></canvas>
        </div>
    </div>
    
    <!-- Chart 2 -->
    <div class="card widget" style="padding: 1.5rem; display: flex; flex-direction: column;">
        <div style="margin-bottom: 1rem;">
            <h3 style="font-size: 0.9375rem;">Asset Distribution</h3>
            <p class="text-xs text-muted mt-1">Breakdown by asset type</p>
        </div>
        <div class="chart-container" style="position: relative; height: 240px; width: 100%; overflow: hidden;">
            <canvas id="assetChart"></canvas>
        </div>
    </div>

    <!-- Cash Flow Trend -->
    <div class="card widget" style="padding: 1.5rem; display: flex; flex-direction: column; grid-column: 1 / -1;">
        <div style="margin-bottom: 1rem;">
            <h3 style="font-size: 0.9375rem;">Cash Flow Trend</h3>
            <p class="text-xs text-muted mt-1">Monthly cash inflows vs outflows</p>
        </div>
        <div class="chart-container" style="position: relative; height: 240px; width: 100%; overflow: hidden;">
            <canvas id="cashFlowChart"></canvas>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="card widget" style="padding: 0; display: flex; flex-direction: column; grid-column: 1 / -1;">
        <div class="flex justify-between items-center" style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color);">
            <div>
                <h3 style="font-size: 0.9375rem;">Recent Journal Entries</h3>
                <p class="text-xs text-muted mt-1">Latest <?= count($recent) ?> transactions posted</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="journal_entries.php" class="btn btn-secondary btn-sm">View All</a>
            </div>
        </div>
    <table class="table">
        <thead>
            <tr>
                <th style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Date</th>
                <th style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Description</th>
                <th style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Journal</th>
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
                    $jid = $tx['journal_id'] ?? 'GJ';
                    $j_cls = 'neutral';
                    if($jid === 'SJ') $j_cls = 'primary';
                    if($jid === 'PJ') $j_cls = 'warning';
                    if($jid === 'CRJ') $j_cls = 'success';
                    if($jid === 'CDJ') $j_cls = 'danger';
                    ?>
                    <span class="badge badge-<?= $j_cls ?>" style="border-radius: 4px; font-weight: 700; padding: 0.15rem 0.4rem; font-size: 0.65rem; background-color: var(--<?= $j_cls ?>-color-light, #f1f5f9); color: var(--<?= $j_cls ?>-color, #475569); border: 1px solid rgba(0,0,0,0.05);"><?= $jid ?></span>
                </td>
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
</div> <!-- End Widgets Container -->

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Period dropdown toggle
function togglePeriodDropdown(idx) {
    // Close all other dropdowns first
    document.querySelectorAll('[id^="period-dd-"]').forEach(dd => {
        if (dd.id !== 'period-dd-' + idx) dd.style.display = 'none';
    });
    const dd = document.getElementById('period-dd-' + idx);
    if (dd) dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
}
// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('[id^="period-dd-"]') && !e.target.closest('button[onclick^="togglePeriodDropdown"]')) {
        document.querySelectorAll('[id^="period-dd-"]').forEach(dd => dd.style.display = 'none');
    }
});

// Widget Logic
function toggleWidget(btn) {
    const widget = btn.closest('.widget');
    const backdrop = document.getElementById('widgetBackdrop');
    const isExpanded = widget.classList.contains('widget-expanded');
    
    if(isExpanded) {
        widget.classList.remove('widget-expanded');
        backdrop.classList.remove('show');
        btn.innerHTML = '<i data-lucide="maximize" style="width: 16px; height: 16px;"></i>';
    } else {
        widget.classList.add('widget-expanded');
        backdrop.classList.add('show');
        btn.innerHTML = '<i data-lucide="minimize" style="width: 16px; height: 16px;"></i>';
    }
    lucide.createIcons();
}

function closeAllWidgets() {
    document.querySelectorAll('.widget-expanded').forEach(w => {
        w.classList.remove('widget-expanded');
        const btn = w.querySelector('.expand-btn');
        if(btn) btn.innerHTML = '<i data-lucide="maximize" style="width: 16px; height: 16px;"></i>';
    });
    document.getElementById('widgetBackdrop').classList.remove('show');
    lucide.createIcons();
}

document.addEventListener('DOMContentLoaded', () => {
    const widgetContainer = document.getElementById('dashboard-widgets');
    if(widgetContainer) {
        new Sortable(widgetContainer, {
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function() {
                for (let id in Chart.instances) {
                    Chart.instances[id].resize();
                }
            }
        });
    }
});
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
