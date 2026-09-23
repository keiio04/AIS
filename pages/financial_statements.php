<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/header.php';

$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to view financial statements.</div>';
    require_once '../includes/footer.php';
    exit;
}

// Handle Note Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_note') {
        $num = $_POST['note_number'];
        $title = $_POST['title'];
        $desc = $_POST['description'];
        $stmt = $db->prepare("INSERT INTO notes_to_fs (company_id, note_number, title, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isss', $company_id, $num, $title, $desc);
        $stmt->execute();
    } elseif ($_POST['action'] === 'delete_note') {
        $id = $_POST['id'];
        $stmt = $db->prepare("DELETE FROM notes_to_fs WHERE id = ? AND company_id = ?");
        $stmt->bind_param('ii', $id, $company_id);
        $stmt->execute();
    }
    header("Location: financial_statements.php?tab=Notes");
    exit;
}

// Fetch Notes
$stmtNotes = $db->prepare("SELECT * FROM notes_to_fs WHERE company_id = ? ORDER BY id ASC");
$stmtNotes->bind_param('i', $company_id);
$stmtNotes->execute();
$notes = $stmtNotes->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch Accounts and calculate balances
$query = "
    SELECT 
        a.id, a.name, a.category, a.sub_category, a.opening_balance,
        SUM(IFNULL(l.debit, 0)) as total_dr,
        SUM(IFNULL(l.credit, 0)) as total_cr
    FROM accounts a
    LEFT JOIN (journal_entry_lines l JOIN journal_entries e ON l.journal_entry_id = e.id AND e.deleted_at IS NULL) ON a.id = l.account_id
    WHERE a.company_id = ?
    GROUP BY a.id
";
$stmt = $db->prepare($query);
$stmt->bind_param('i', $company_id);
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function getBal($cat, $ob, $dr, $cr) {
    if ($cat === 'Assets' || $cat === 'Expenses') {
        return $ob + $dr - $cr;
    } else {
        return $ob - $dr + $cr;
    }
}

$accs = [];
$ownersCapitalBeginning = 0;
$additionalInvestment = 0;

$catTotals = [
    'Current Assets' => 0, 'Non-Current Assets' => 0,
    'Current Liabilities' => 0, 'Non-Current Liabilities' => 0,
    "Owner's Capital" => 0, 'Withdrawals' => 0, 'Retained Earnings' => 0,
    'Revenue' => 0, 'Expenses' => 0
];

foreach ($accounts as $a) {
    $bal = getBal($a['category'], $a['opening_balance'], $a['total_dr'], $a['total_cr']);
    $a['balance'] = $bal;
    $accs[] = $a;
    
    $sub = $a['sub_category'];
    if ($sub && isset($catTotals[$sub])) {
        $catTotals[$sub] += $bal;
        if ($sub === "Owner's Capital") {
            $ownersCapitalBeginning += $a['opening_balance'];
            $additionalInvestment += ($a['total_cr'] - $a['total_dr']);
        }
    } elseif ($a['category'] === 'Revenue') {
        $catTotals['Revenue'] += $bal;
    } elseif ($a['category'] === 'Expenses') {
        $catTotals['Expenses'] += $bal;
    }
}

$currentAssets = $catTotals['Current Assets'];
$nonCurrentAssets = $catTotals['Non-Current Assets'];
$totalAssets = $currentAssets + $nonCurrentAssets;

$currentLiabilities = $catTotals['Current Liabilities'];
$nonCurrentLiabilities = $catTotals['Non-Current Liabilities'];
$totalLiabilities = $currentLiabilities + $nonCurrentLiabilities;

$totalRevenue = $catTotals['Revenue'];
$totalExpenses = $catTotals['Expenses'];
$netIncome = $totalRevenue - $totalExpenses;

$ownersCapital = $catTotals["Owner's Capital"];
$withdrawals = $catTotals['Withdrawals'];
$retainedEarnings = $catTotals['Retained Earnings'];
$endingEquity = $ownersCapital + $netIncome - $withdrawals + $retainedEarnings; // Note: withdrawals is deducted

$totalLiabilitiesAndEquity = $totalLiabilities + $endingEquity;

// Cash Flows
$queryCF = "
    SELECT e.type, SUM(l.debit) as total_dr, SUM(l.credit) as total_cr
    FROM journal_entries e
    JOIN journal_entry_lines l ON e.id = l.journal_entry_id
    JOIN accounts a ON l.account_id = a.id
    WHERE e.company_id = ? AND e.deleted_at IS NULL AND a.name LIKE '%cash%'
    GROUP BY e.type
";
$stmtCF = $db->prepare($queryCF);
$stmtCF->bind_param('i', $company_id);
$stmtCF->execute();
$cfs = $stmtCF->get_result()->fetch_all(MYSQLI_ASSOC);

$cfData = ['Operating' => 0, 'Investing' => 0, 'Financing' => 0];
foreach ($cfs as $cf) {
    $t = $cf['type'];
    if (isset($cfData[$t])) {
        // Net Cash Flow = Cash In (Debit) - Cash Out (Credit)
        $cfData[$t] = $cf['total_dr'] - $cf['total_cr'];
    }
}
$netCashFlow = $cfData['Operating'] + $cfData['Investing'] + $cfData['Financing'];

$activeTab = $_GET['tab'] ?? 'BS';

function fmt($n) { return '₱' . number_format($n, 2); }
?>

<!-- Statement Tabs & Actions Toolbar (Option 1) -->
<div class="no-print" style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-top: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.85rem; flex-wrap: wrap;">
    <div style="display: inline-flex; background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 3px; border-radius: 8px; gap: 3px; max-width: 100%; overflow-x: auto;">
        <?php 
        $tabs = [
            'BS' => 'Balance Sheet',
            'IS' => 'Income Statement',
            'EQ' => 'Changes in Equity',
            'CF' => 'Cash Flows'
        ];
        foreach($tabs as $k => $v): 
            $isActive = ($activeTab === $k);
        ?>
        <a href="?tab=<?= $k ?>" style="padding: 0.35rem 0.85rem; font-size: 0.8rem; font-weight: <?= $isActive ? '600' : '500' ?>; border-radius: 6px; text-decoration: none; white-space: nowrap; transition: all 0.15s ease; background: <?= $isActive ? 'var(--primary-color)' : 'transparent' ?>; color: <?= $isActive ? '#ffffff' : 'var(--text-secondary)' ?>; box-shadow: <?= $isActive ? '0 1px 3px rgba(0,0,0,0.1)' : 'none' ?>;">
            <?= $v ?>
        </a>
        <?php endforeach; ?>
    </div>
    <div>
        <button class="btn btn-secondary" onclick="window.print()" style="font-size: 0.8rem; padding: 0.35rem 0.8rem; display: inline-flex; align-items: center; gap: 0.4rem;">
            <i data-lucide="printer" style="width:14px;height:14px;"></i> Print / Export PDF
        </button>
    </div>
</div>

<div class="fs-container" id="printable-area">
    <?php if ($activeTab !== 'Notes'): ?>
    <div style="text-align: center; margin-bottom: 1rem;">
        <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.2rem;"><?= htmlspecialchars($activeCompanyName ?? 'Company') ?></h2>
        <h3 style="font-size: 0.925rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.2rem;">
            <?php 
            if($activeTab==='BS') echo 'Statement of Financial Position';
            if($activeTab==='IS') echo 'Statement of Comprehensive Income';
            if($activeTab==='EQ') echo 'Statement of Changes in Equity';
            if($activeTab==='CF') echo 'Statement of Cash Flows';
            ?>
        </h3>
        <p style="color: var(--text-muted); font-size: 0.78rem; font-weight: 500; margin: 0;">
            <?= $activeTab==='BS' ? 'As of ' : 'For the period ended ' ?> <?= date('F j, Y') ?>
        </p>
        <div style="width: 60px; height: 2px; background: var(--primary-color); opacity: 0.7; margin: 0.75rem auto 0; border-radius: 2px;"></div>
    </div>
    <?php endif; ?>

    <!-- BALANCE SHEET -->
    <?php if ($activeTab === 'BS'): ?>
    <table class="fs-table">
        <tbody>
            <tr class="fs-cat-header"><td colspan="2">ASSETS</td></tr>
            <tr class="fs-subcat-header"><td colspan="2">Current Assets</td></tr>
            <?php foreach($accs as $a): if($a['sub_category']==='Current Assets' && $a['balance']!=0): ?>
                <tr class="fs-item-row"><td class="fs-item-name"><?= htmlspecialchars($a['name']) ?></td><td class="fs-amount"><?= fmt($a['balance']) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr class="fs-subtotal-row"><td>Total Current Assets</td><td class="fs-amount"><?= fmt($currentAssets) ?></td></tr>
            
            <tr class="fs-subcat-header"><td colspan="2" style="padding-top: 0.65rem;">Non-Current Assets</td></tr>
            <?php foreach($accs as $a): if($a['sub_category']==='Non-Current Assets' && $a['balance']!=0): ?>
                <tr class="fs-item-row"><td class="fs-item-name"><?= htmlspecialchars($a['name']) ?></td><td class="fs-amount"><?= fmt($a['balance']) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr class="fs-subtotal-row"><td>Total Non-Current Assets</td><td class="fs-amount"><?= fmt($nonCurrentAssets) ?></td></tr>
            
            <tr class="fs-grand-total">
                <td>TOTAL ASSETS</td>
                <td class="fs-amount fs-double-underline"><?= fmt($totalAssets) ?></td>
            </tr>

            <tr class="fs-cat-header" style="padding-top: 1.25rem;"><td colspan="2">LIABILITIES AND OWNER'S EQUITY</td></tr>
            <tr class="fs-subcat-header"><td colspan="2">Current Liabilities</td></tr>
            <?php foreach($accs as $a): if($a['sub_category']==='Current Liabilities' && $a['balance']!=0): ?>
                <tr class="fs-item-row"><td class="fs-item-name"><?= htmlspecialchars($a['name']) ?></td><td class="fs-amount"><?= fmt($a['balance']) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr class="fs-subtotal-row"><td>Total Current Liabilities</td><td class="fs-amount"><?= fmt($currentLiabilities) ?></td></tr>

            <tr class="fs-subcat-header"><td colspan="2" style="padding-top: 0.65rem;">Non-Current Liabilities</td></tr>
            <?php foreach($accs as $a): if($a['sub_category']==='Non-Current Liabilities' && $a['balance']!=0): ?>
                <tr class="fs-item-row"><td class="fs-item-name"><?= htmlspecialchars($a['name']) ?></td><td class="fs-amount"><?= fmt($a['balance']) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr class="fs-subtotal-row"><td>Total Non-Current Liabilities</td><td class="fs-amount"><?= fmt($nonCurrentLiabilities) ?></td></tr>
            <tr class="fs-subtotal-row" style="font-weight: 700;"><td>Total Liabilities</td><td class="fs-amount"><?= fmt($totalLiabilities) ?></td></tr>

            <tr class="fs-subcat-header" style="padding-top: 0.65rem;"><td colspan="2">Owner's Equity</td></tr>
            <tr class="fs-item-row"><td class="fs-item-name">Owner's Capital, Ending</td><td class="fs-amount"><?= fmt($endingEquity) ?></td></tr>
            
            <tr class="fs-grand-total">
                <td>TOTAL LIABILITIES AND EQUITY</td>
                <td class="fs-amount fs-double-underline"><?= fmt($totalLiabilitiesAndEquity) ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- INCOME STATEMENT -->
    <?php if ($activeTab === 'IS'): ?>
    <table class="fs-table">
        <tbody>
            <tr class="fs-cat-header"><td colspan="2">REVENUE</td></tr>
            <?php foreach($accs as $a): if($a['category']==='Revenue' && $a['balance']!=0): ?>
                <tr class="fs-item-row"><td class="fs-item-name"><?= htmlspecialchars($a['name']) ?></td><td class="fs-amount"><?= fmt($a['balance']) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr class="fs-subtotal-row"><td>Total Revenue</td><td class="fs-amount"><?= fmt($totalRevenue) ?></td></tr>

            <tr class="fs-cat-header" style="padding-top: 1rem;"><td colspan="2">EXPENSES</td></tr>
            <?php foreach($accs as $a): if($a['category']==='Expenses' && $a['balance']!=0): ?>
                <tr class="fs-item-row"><td class="fs-item-name"><?= htmlspecialchars($a['name']) ?></td><td class="fs-amount"><?= fmt($a['balance']) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr class="fs-subtotal-row"><td>Total Expenses</td><td class="fs-amount"><?= fmt($totalExpenses) ?></td></tr>

            <tr class="fs-grand-total">
                <td>NET INCOME (LOSS)</td>
                <td class="fs-amount fs-double-underline" style="color: <?= $netIncome >= 0 ? 'var(--text-primary)' : '#dc2626' ?>;">
                    <?= fmt($netIncome) ?>
                </td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- EQUITY -->
    <?php if ($activeTab === 'EQ'): ?>
    <table class="fs-table">
        <tbody>
            <tr class="fs-item-row" style="font-weight: 500;"><td>Owner's Capital, Beginning</td><td class="fs-amount"><?= fmt($ownersCapitalBeginning) ?></td></tr>
            <?php if($additionalInvestment > 0): ?>
            <tr class="fs-item-row"><td class="fs-item-name">Add: Additional Investment</td><td class="fs-amount"><?= fmt($additionalInvestment) ?></td></tr>
            <?php elseif($additionalInvestment < 0): ?>
            <tr class="fs-item-row"><td class="fs-item-name">Less: Capital Reductions</td><td class="fs-amount">(<?= fmt(abs($additionalInvestment)) ?>)</td></tr>
            <?php endif; ?>
            <tr class="fs-item-row"><td class="fs-item-name"><?= $netIncome >= 0 ? 'Add: Net Income' : 'Less: Net Loss' ?></td><td class="fs-amount" style="color: <?= $netIncome >= 0 ? 'inherit' : '#dc2626' ?>;"><?= $netIncome >= 0 ? fmt($netIncome) : '('.fmt(abs($netIncome)).')' ?></td></tr>
            <tr class="fs-item-row"><td class="fs-item-name">Less: Withdrawals</td><td class="fs-amount">(<?= fmt($withdrawals) ?>)</td></tr>
            <tr class="fs-grand-total">
                <td>Owner's Capital, Ending</td>
                <td class="fs-amount fs-double-underline"><?= fmt($endingEquity) ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- CASH FLOWS -->
    <?php if ($activeTab === 'CF'): ?>
    <table class="fs-table">
        <tbody>
            <tr class="fs-cat-header"><td colspan="2">Cash Flows from Operating Activities</td></tr>
            <tr class="fs-item-row"><td class="fs-item-name">Net Cash provided by (used in) Operating Activities</td><td class="fs-amount"><?= fmt($cfData['Operating']) ?></td></tr>

            <tr class="fs-cat-header" style="padding-top: 0.75rem;"><td colspan="2">Cash Flows from Investing Activities</td></tr>
            <tr class="fs-item-row"><td class="fs-item-name">Net Cash provided by (used in) Investing Activities</td><td class="fs-amount"><?= fmt($cfData['Investing']) ?></td></tr>

            <tr class="fs-cat-header" style="padding-top: 0.75rem;"><td colspan="2">Cash Flows from Financing Activities</td></tr>
            <tr class="fs-item-row"><td class="fs-item-name">Net Cash provided by (used in) Financing Activities</td><td class="fs-amount"><?= fmt($cfData['Financing']) ?></td></tr>

            <tr class="fs-grand-total">
                <td>Net Increase (Decrease) in Cash</td>
                <td class="fs-amount fs-double-underline" style="color: <?= $netCashFlow >= 0 ? 'var(--text-primary)' : '#dc2626' ?>;"><?= fmt($netCashFlow) ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- NOTES -->
    <?php if ($activeTab === 'Notes'): ?>
    <div>
        <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="file-text" style="width:18px;height:18px;color:var(--primary-color);"></i>
            Notes to Financial Statements
        </h2>
        
        <?php foreach($notes as $n): ?>
        <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 0.75rem;">
            <div class="flex justify-between items-center" style="margin-bottom: 0.35rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span class="badge badge-primary" style="font-size: 0.7rem; padding: 2px 7px;">Note <?= htmlspecialchars($n['note_number']) ?></span>
                    <h3 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0;"><?= htmlspecialchars($n['title']) ?></h3>
                </div>
                <form method="POST" class="no-print" onsubmit="return confirm('Delete this note?');">
                    <input type="hidden" name="action" value="delete_note">
                    <input type="hidden" name="id" value="<?= $n['id'] ?>">
                    <button type="submit" class="icon-btn text-danger" title="Delete Note"><i data-lucide="trash-2" style="width:14px;height:14px;"></i></button>
                </form>
            </div>
            <p style="margin: 0; white-space: pre-wrap; font-size: 0.8125rem; line-height: 1.5; color: var(--text-secondary);"><?= htmlspecialchars($n['description']) ?></p>
        </div>
        <?php endforeach; ?>

        <?php if(count($notes)===0): ?>
        <p class="text-muted text-center" style="margin: 2rem 0; font-size: 0.85rem;">No notes added yet.</p>
        <?php endif; ?>

        <div class="card no-print" style="margin-top: 1.5rem; background: var(--bg-secondary); border: 1px dashed var(--border-color); padding: 1rem 1.25rem;">
            <h4 style="font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--text-primary);">Add New Note</h4>
            <form method="POST">
                <input type="hidden" name="action" value="add_note">
                <div class="flex gap-3" style="margin-bottom: 0.65rem;">
                    <div class="form-group" style="width: 100px; margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.75rem;">Note No.</label>
                        <input type="text" name="note_number" class="form-control" required placeholder="e.g. 1" style="height: 32px; font-size: 0.8rem; padding: 0.35rem 0.55rem;">
                    </div>
                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.75rem;">Title</label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g. Summary of Significant Accounting Policies" style="height: 32px; font-size: 0.8rem; padding: 0.35rem 0.55rem;">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 0.75rem;">
                    <label class="form-label" style="font-size: 0.75rem;">Description</label>
                    <textarea name="description" class="form-control" rows="3" required placeholder="Enter note details..." style="font-size: 0.8rem; padding: 0.5rem;"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="font-size: 0.8rem; padding: 0.35rem 0.85rem;">
                    <i data-lucide="plus" style="width:14px;height:14px;"></i> Save Note
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

</div>

<style>
.fs-container {
    background: transparent;
    color: var(--text-primary);
    width: 100%;
    margin: 0 0 2rem;
    padding: 0;
    border: none;
    box-shadow: none;
}
.fs-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
}
.fs-table td {
    padding: 0.28rem 0.5rem;
    vertical-align: middle;
}
.fs-item-row:hover td, .fs-subtotal-row:hover td {
    background-color: var(--bg-tertiary);
}
.fs-cat-header td {
    font-weight: 700;
    font-size: 0.78rem;
    color: var(--text-primary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding-top: 1rem;
    padding-bottom: 0.25rem;
    padding-left: 0.5rem;
}
.fs-subcat-header td {
    font-weight: 600;
    font-size: 0.8rem;
    color: var(--text-secondary);
    padding-left: 1.25rem;
    padding-top: 0.45rem;
    padding-bottom: 0.2rem;
}
.fs-item-row td.fs-item-name {
    padding-left: 2.25rem;
    color: var(--text-primary);
    font-weight: 400;
}
.fs-amount {
    text-align: right;
    font-family: monospace;
    font-size: 0.8125rem;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
    width: 200px;
    padding-right: 0.5rem !important;
}
.fs-subtotal-row td {
    font-weight: 600;
    font-size: 0.8125rem;
    padding-left: 1.25rem;
    padding-top: 0.4rem;
    padding-bottom: 0.4rem;
    border-top: 1px solid var(--border-color);
}
.fs-grand-total td {
    font-weight: 700;
    font-size: 0.835rem;
    color: var(--text-primary);
    padding-top: 0.65rem;
    padding-bottom: 0.65rem;
    padding-left: 0.5rem;
    border-top: 1px solid var(--border-color);
}
.fs-double-underline {
    border-bottom: 3px double var(--text-primary) !important;
}

@media (max-width: 768px) {
    .fs-spacer { display: none !important; }
}

@media print {
    body * { visibility: hidden; }
    #printable-area, #printable-area * { visibility: visible; }
    #printable-area {
        position: absolute; left: 0; top: 0; width: 100% !important;
        box-shadow: none !important; border: none !important; padding: 0 !important;
        background: #ffffff !important; color: #000000 !important;
    }
    #printable-area * { color: #000000 !important; border-color: #000000 !important; }
    .page-header, .sidebar, .topbar, .btn, .no-print { display: none !important; }
    .main-wrapper { padding: 0 !important; margin: 0 !important; }
}
</style>

<?php require_once '../includes/footer.php'; ?>
