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
    WHERE e.company_id = ? AND e.deleted_at IS NULL
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

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Financial Statements</h1>
        <p class="page-subtitle">Standard IFRS/GAAP Reports</p>
    </div>
    <button class="btn btn-secondary" onclick="window.print()">
        <i data-lucide="download" style="width:15px;height:15px;"></i> Export PDF
    </button>
</div>

<div class="flex gap-1 mb-4" style="overflow-x: auto; background: var(--bg-tertiary); padding: 0.25rem; border-radius: 99px; display: inline-flex;">
    <?php 
    $tabs = ['BS'=>'Balance Sheet', 'IS'=>'Income Statement', 'EQ'=>'Changes in Equity', 'CF'=>'Cash Flows', 'Notes'=>'Notes'];
    foreach($tabs as $k => $v): 
    ?>
    <a href="?tab=<?= $k ?>" class="btn <?= $activeTab===$k ? 'btn-primary' : '' ?>" style="<?= $activeTab===$k ? 'border-radius: 99px;' : 'border: none; background: transparent; color: var(--text-secondary); border-radius: 99px; box-shadow: none;' ?>"><?= $v ?></a>
    <?php endforeach; ?>
</div>

<div class="card" id="printable-area" style="background-color: white; color: black; max-width: 900px; margin: 0 auto; padding: 2.5rem;">
    
    <?php if ($activeTab !== 'Notes'): ?>
    <div class="text-center mb-4">
        <h2 style="font-size: 1.5rem; margin-bottom: 0.25rem;"><?= htmlspecialchars($activeCompanyName ?? 'Company') ?></h2>
        <h3 style="font-size: 1.125rem; color: #4b5563; margin-bottom: 0.25rem;">
            <?php 
            if($activeTab==='BS') echo 'Statement of Financial Position';
            if($activeTab==='IS') echo 'Statement of Comprehensive Income';
            if($activeTab==='EQ') echo 'Statement of Changes in Equity';
            if($activeTab==='CF') echo 'Statement of Cash Flows';
            ?>
        </h3>
        <p style="color: #6b7280; font-size: 0.875rem; font-weight: 500;">
            <?= $activeTab==='BS' ? 'As of ' : 'For the period ended ' ?> <?= date('F j, Y') ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- BALANCE SHEET -->
    <?php if ($activeTab === 'BS'): ?>
    <table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
        <tbody>
            <tr><td colspan="2" style="padding-top: 1rem;"><strong>ASSETS</strong></td></tr>
            <tr><td colspan="2" style="padding-left: 2rem;"><strong>Current Assets</strong></td></tr>
            <?php foreach($accs as $a): if($a['sub_category']==='Current Assets' && $a['balance']!=0): ?>
                <tr><td style="padding-left: 3rem;"><?= htmlspecialchars($a['name']) ?></td><td class="text-right"><?= fmt($a['balance']) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr><td style="padding-left: 2rem;"><strong>Total Current Assets</strong></td><td class="text-right"><strong><?= fmt($currentAssets) ?></strong></td></tr>
            
            <tr><td colspan="2" style="padding-left: 2rem; padding-top: 1rem;"><strong>Non-Current Assets</strong></td></tr>
            <?php foreach($accs as $a): if($a['sub_category']==='Non-Current Assets' && $a['balance']!=0): ?>
                <tr><td style="padding-left: 3rem;"><?= htmlspecialchars($a['name']) ?></td><td class="text-right"><?= fmt($a['balance']) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr><td style="padding-left: 2rem;"><strong>Total Non-Current Assets</strong></td><td class="text-right"><strong><?= fmt($nonCurrentAssets) ?></strong></td></tr>
            
            <tr>
                <td style="padding-top: 1rem;"><strong>TOTAL ASSETS</strong></td>
                <td class="text-right" style="padding-top: 1rem; border-bottom: 3px double black;"><strong><?= fmt($totalAssets) ?></strong></td>
            </tr>

            <tr><td colspan="2" style="padding-top: 2rem;"><strong>LIABILITIES AND EQUITY</strong></td></tr>
            <tr><td colspan="2" style="padding-left: 2rem;"><strong>Current Liabilities</strong></td></tr>
            <?php foreach($accs as $a): if($a['sub_category']==='Current Liabilities' && $a['balance']!=0): ?>
                <tr><td style="padding-left: 3rem;"><?= htmlspecialchars($a['name']) ?></td><td class="text-right"><?= fmt($a['balance']) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr><td style="padding-left: 2rem;"><strong>Total Current Liabilities</strong></td><td class="text-right"><strong><?= fmt($currentLiabilities) ?></strong></td></tr>

            <tr><td colspan="2" style="padding-left: 2rem; padding-top: 1rem;"><strong>Non-Current Liabilities</strong></td></tr>
            <?php foreach($accs as $a): if($a['sub_category']==='Non-Current Liabilities' && $a['balance']!=0): ?>
                <tr><td style="padding-left: 3rem;"><?= htmlspecialchars($a['name']) ?></td><td class="text-right"><?= fmt($a['balance']) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr><td style="padding-left: 2rem;"><strong>Total Non-Current Liabilities</strong></td><td class="text-right"><strong><?= fmt($nonCurrentLiabilities) ?></strong></td></tr>
            <tr><td style="padding-left: 1rem; padding-top: 0.5rem;"><strong>Total Liabilities</strong></td><td class="text-right" style="padding-top: 0.5rem;"><strong><?= fmt($totalLiabilities) ?></strong></td></tr>

            <tr><td colspan="2" style="padding-left: 2rem; padding-top: 1rem;"><strong>Owner's Equity</strong></td></tr>
            <tr><td style="padding-left: 3rem;">Ending Equity</td><td class="text-right"><?= fmt($endingEquity) ?></td></tr>
            
            <tr>
                <td style="padding-top: 1rem;"><strong>TOTAL LIABILITIES AND EQUITY</strong></td>
                <td class="text-right" style="padding-top: 1rem; border-bottom: 3px double black;"><strong><?= fmt($totalLiabilitiesAndEquity) ?></strong></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- INCOME STATEMENT -->
    <?php if ($activeTab === 'IS'): ?>
    <table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
        <tbody>
            <tr><td colspan="2" style="padding-top: 1rem;"><strong>REVENUE</strong></td></tr>
            <?php foreach($accs as $a): if($a['category']==='Revenue' && $a['balance']!=0): ?>
                <tr><td style="padding-left: 2rem;"><?= htmlspecialchars($a['name']) ?></td><td class="text-right"><?= fmt($a['balance']) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr><td><strong>Total Revenue</strong></td><td class="text-right"><strong><?= fmt($totalRevenue) ?></strong></td></tr>

            <tr><td colspan="2" style="padding-top: 1.5rem;"><strong>EXPENSES</strong></td></tr>
            <?php foreach($accs as $a): if($a['category']==='Expenses' && $a['balance']!=0): ?>
                <tr><td style="padding-left: 2rem;"><?= htmlspecialchars($a['name']) ?></td><td class="text-right"><?= fmt($a['balance']) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr><td><strong>Total Expenses</strong></td><td class="text-right" style="border-bottom: 1px solid black;"><strong><?= fmt($totalExpenses) ?></strong></td></tr>

            <tr>
                <td style="padding-top: 1rem;"><strong>NET INCOME (LOSS)</strong></td>
                <td class="text-right" style="padding-top: 1rem; border-bottom: 3px double black; color: <?= $netIncome>=0?'inherit':'#ef4444' ?>;">
                    <strong><?= fmt($netIncome) ?></strong>
                </td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- EQUITY -->
    <?php if ($activeTab === 'EQ'): ?>
    <table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
        <tbody>
            <tr><td style="padding-top: 1rem;">Owner's Capital, Beginning</td><td class="text-right"><?= fmt($ownersCapitalBeginning) ?></td></tr>
            <?php if($additionalInvestment > 0): ?>
            <tr><td style="padding-left: 2rem;">Add: Additional Investment</td><td class="text-right"><?= fmt($additionalInvestment) ?></td></tr>
            <?php elseif($additionalInvestment < 0): ?>
            <tr><td style="padding-left: 2rem;">Less: Capital Reductions</td><td class="text-right">(<?= fmt(abs($additionalInvestment)) ?>)</td></tr>
            <?php endif; ?>
            <tr><td style="padding-left: 2rem;"><?= $netIncome >= 0 ? 'Add: Net Income' : 'Less: Net Loss' ?></td><td class="text-right"><?= $netIncome >= 0 ? fmt($netIncome) : '('.fmt(abs($netIncome)).')' ?></td></tr>
            <tr><td style="padding-left: 2rem;">Less: Withdrawals</td><td class="text-right border-bottom">(<?= fmt($withdrawals) ?>)</td></tr>
            <tr>
                <td style="padding-top: 1rem;"><strong>Owner's Capital, Ending</strong></td>
                <td class="text-right" style="padding-top: 1rem; border-bottom: 3px double black;"><strong><?= fmt($endingEquity) ?></strong></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- CASH FLOWS -->
    <?php if ($activeTab === 'CF'): ?>
    <table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
        <tbody>
            <tr><td colspan="2" style="padding-top: 1rem;"><strong>Cash Flows from Operating Activities</strong></td></tr>
            <tr><td style="padding-left: 2rem;">Net Cash provided by (used in) Operating Activities</td><td class="text-right"><?= fmt($cfData['Operating']) ?></td></tr>

            <tr><td colspan="2" style="padding-top: 1rem;"><strong>Cash Flows from Investing Activities</strong></td></tr>
            <tr><td style="padding-left: 2rem;">Net Cash provided by (used in) Investing Activities</td><td class="text-right"><?= fmt($cfData['Investing']) ?></td></tr>

            <tr><td colspan="2" style="padding-top: 1rem;"><strong>Cash Flows from Financing Activities</strong></td></tr>
            <tr><td style="padding-left: 2rem;">Net Cash provided by (used in) Financing Activities</td><td class="text-right"><?= fmt($cfData['Financing']) ?></td></tr>

            <tr>
                <td style="padding-top: 1rem;"><strong>Net Increase (Decrease) in Cash</strong></td>
                <td class="text-right" style="padding-top: 1rem; border-bottom: 3px double black;"><strong><?= fmt($netCashFlow) ?></strong></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- NOTES -->
    <?php if ($activeTab === 'Notes'): ?>
    <div>
        <h2 style="font-size: 1.5rem; margin-bottom: 1.5rem;">Notes to Financial Statements</h2>
        
        <?php foreach($notes as $n): ?>
        <div style="margin-bottom: 1.5rem;">
            <div class="flex justify-between items-center">
                <h3 style="font-size: 1.125rem;">Note <?= htmlspecialchars($n['note_number']) ?> - <?= htmlspecialchars($n['title']) ?></h3>
                <form method="POST" class="no-print" onsubmit="return confirm('Delete this note?');">
                    <input type="hidden" name="action" value="delete_note">
                    <input type="hidden" name="id" value="<?= $n['id'] ?>">
                    <button type="submit" class="icon-btn text-danger"><i data-lucide="trash-2" style="width:16px;height:16px;"></i></button>
                </form>
            </div>
            <p style="margin-top: 0.5rem; white-space: pre-wrap; font-size: 0.95rem; line-height: 1.5; color: #374151;"><?= htmlspecialchars($n['description']) ?></p>
        </div>
        <?php endforeach; ?>

        <?php if(count($notes)===0): ?>
        <p class="text-muted text-center" style="margin: 2rem 0;">No notes added yet.</p>
        <?php endif; ?>

        <div class="card no-print" style="margin-top: 2rem; background: var(--bg-tertiary); border: 1px dashed var(--border-color);">
            <h4 style="margin-bottom: 1rem;">Add New Note</h4>
            <form method="POST">
                <input type="hidden" name="action" value="add_note">
                <div class="flex gap-4">
                    <div class="form-group" style="width: 120px;">
                        <label class="form-label">Note No.</label>
                        <input type="text" name="note_number" class="form-control" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary mt-2">Save Note</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

</div>

<style>
@media print {
    body * { visibility: hidden; }
    #printable-area, #printable-area * { visibility: visible; }
    #printable-area { position: absolute; left: 0; top: 0; width: 100%; box-shadow: none !important; border: none !important; padding: 0 !important; }
    .page-header, .sidebar, .topbar, .btn, .no-print { display: none !important; }
    .main-wrapper { padding: 0 !important; margin: 0 !important; }
}
table td { padding: 0.4rem 0; }
</style>

<?php require_once '../includes/footer.php'; ?>
