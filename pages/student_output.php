<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';
require_once '../includes/access_control.php';

// Enforce instructor access
require_instructor();

$db = get_db();
$instructorId = (int)$_SESSION['user_id'];
$isAdmin = ($_SESSION['user_role'] === 'Admin');

$studentId = (int)($_GET['student_id'] ?? 0);
$companyId = (int)($_GET['company_id'] ?? 0);

// Verify student exists and has role = 'Student'
$stmtStudent = $db->prepare("SELECT id, name, email, created_at FROM users WHERE id = ? AND role = 'Student'");
$stmtStudent->bind_param('i', $studentId);
$stmtStudent->execute();
$student = $stmtStudent->get_result()->fetch_assoc();

if (!$student) {
    echo '<div style="padding:3rem; text-align:center; color:#1e293b; font-family:sans-serif;"><h2>Student Not Found</h2><p style="color:#94a3b8; margin-top:0.5rem;">The requested student record does not exist.</p><a href="dashboard.php" style="display:inline-block; margin-top:1rem; color:#3b82f6;">&larr; Back to Dashboard</a></div>';
    exit;
}

// Verify instructor assignment (unless Admin)
if (!$isAdmin) {
    $stmtCheck = $db->prepare("SELECT section FROM instructor_students WHERE instructor_id = ? AND student_id = ?");
    $stmtCheck->bind_param('ii', $instructorId, $studentId);
    $stmtCheck->execute();
    $assignedRow = $stmtCheck->get_result()->fetch_assoc();
    if (!$assignedRow) {
        echo '<div style="padding:3rem; text-align:center; color:#1e293b; font-family:sans-serif;"><h2>Access Denied</h2><p style="color:#94a3b8; margin-top:0.5rem;">This student is not assigned to your instructor roster.</p><a href="dashboard.php" style="display:inline-block; margin-top:1rem; color:#3b82f6;">&larr; Back to Dashboard</a></div>';
        exit;
    }
    $studentSection = $assignedRow['section'];
} else {
    $stmtCheck = $db->prepare("SELECT section FROM instructor_students WHERE student_id = ? LIMIT 1");
    $stmtCheck->bind_param('i', $studentId);
    $stmtCheck->execute();
    $assignedRow = $stmtCheck->get_result()->fetch_assoc();
    $studentSection = $assignedRow['section'] ?? 'General';
}

// Fetch all simulation companies belonging to this student
$stmtCompanies = $db->prepare("SELECT * FROM companies WHERE user_id = ? ORDER BY created_at DESC");
$stmtCompanies->bind_param('i', $studentId);
$stmtCompanies->execute();
$companies = $stmtCompanies->get_result()->fetch_all(MYSQLI_ASSOC);

// If no company selected, default to first company
$selectedCompany = null;
if ($companyId > 0) {
    foreach ($companies as $c) {
        if ((int)$c['id'] === $companyId) {
            $selectedCompany = $c;
            break;
        }
    }
}
if (!$selectedCompany && count($companies) > 0) {
    $selectedCompany = $companies[0];
    $companyId = (int)$selectedCompany['id'];
}

$activeTab = $_GET['tab'] ?? 'overview';

// Helper format function
function fmtMoney($n) {
    return '₱' . number_format((float)$n, 2);
}

// If student has an active company, load simulation details
$journalEntries = [];
$accounts = [];
$customers = [];
$suppliers = [];
$employees = [];
$activityLogs = [];
$notes = [];

$totalDr = 0;
$totalCr = 0;
$netIncome = 0;
$currentAssets = 0;
$nonCurrentAssets = 0;
$totalAssets = 0;
$currentLiabilities = 0;
$nonCurrentLiabilities = 0;
$totalLiabilities = 0;
$endingEquity = 0;
$totalRevenue = 0;
$totalExpenses = 0;
$cfData = ['Operating' => 0, 'Investing' => 0, 'Financing' => 0];

if ($selectedCompany) {
    $cid = (int)$selectedCompany['id'];

    // 1. Fetch Journal Entries & Lines
    $stmtJE = $db->prepare("
        SELECT e.*, 
            (SELECT IFNULL(SUM(debit),0) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_debit,
            (SELECT IFNULL(SUM(credit),0) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_credit
        FROM journal_entries e 
        WHERE e.company_id = ? AND e.deleted_at IS NULL 
        ORDER BY e.date DESC, e.id DESC
    ");
    $stmtJE->bind_param('i', $cid);
    $stmtJE->execute();
    $journalEntries = $stmtJE->get_result()->fetch_all(MYSQLI_ASSOC);

    // Fetch lines for all journal entries
    foreach ($journalEntries as &$je) {
        $stmtLines = $db->prepare("
            SELECT l.*, a.name as account_name, a.code as account_code, a.category as account_category
            FROM journal_entry_lines l
            JOIN accounts a ON l.account_id = a.id
            WHERE l.journal_entry_id = ?
            ORDER BY l.id ASC
        ");
        $stmtLines->bind_param('i', $je['id']);
        $stmtLines->execute();
        $je['lines'] = $stmtLines->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $totalDr += (float)$je['total_debit'];
        $totalCr += (float)$je['total_credit'];
    }
    unset($je);

    // 2. Fetch Accounts with balances
    $stmtAcc = $db->prepare("
        SELECT 
            a.id, a.code, a.name, a.category, a.sub_category, a.opening_balance,
            SUM(IFNULL(l.debit, 0)) as total_dr,
            SUM(IFNULL(l.credit, 0)) as total_cr
        FROM accounts a
        LEFT JOIN (journal_entry_lines l JOIN journal_entries e ON l.journal_entry_id = e.id AND e.deleted_at IS NULL) ON a.id = l.account_id
        WHERE a.company_id = ?
        GROUP BY a.id
        ORDER BY a.code ASC
    ");
    $stmtAcc->bind_param('i', $cid);
    $stmtAcc->execute();
    $accountsRaw = $stmtAcc->get_result()->fetch_all(MYSQLI_ASSOC);

    function calcBal($cat, $ob, $dr, $cr) {
        if ($cat === 'Assets' || $cat === 'Expenses') {
            return (float)$ob + (float)$dr - (float)$cr;
        } else {
            return (float)$ob - (float)$dr + (float)$cr;
        }
    }

    $catTotals = [
        'Current Assets' => 0, 'Non-Current Assets' => 0,
        'Current Liabilities' => 0, 'Non-Current Liabilities' => 0,
        "Owner's Capital" => 0, 'Withdrawals' => 0, 'Retained Earnings' => 0,
        'Revenue' => 0, 'Expenses' => 0
    ];

    foreach ($accountsRaw as $a) {
        $bal = calcBal($a['category'], $a['opening_balance'], $a['total_dr'], $a['total_cr']);
        $a['balance'] = $bal;
        $accounts[] = $a;

        $sub = $a['sub_category'];
        if ($sub && isset($catTotals[$sub])) {
            $catTotals[$sub] += $bal;
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
    $endingEquity = $ownersCapital + $netIncome - $withdrawals + $retainedEarnings;

    // 3. Cash Flow calculation
    $stmtCF = $db->prepare("
        SELECT e.type, SUM(l.debit) as total_dr, SUM(l.credit) as total_cr
        FROM journal_entries e
        JOIN journal_entry_lines l ON e.id = l.journal_entry_id
        JOIN accounts a ON l.account_id = a.id
        WHERE e.company_id = ? AND e.deleted_at IS NULL AND a.name LIKE '%cash%'
        GROUP BY e.type
    ");
    $stmtCF->bind_param('i', $cid);
    $stmtCF->execute();
    $cfs = $stmtCF->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($cfs as $cf) {
        $t = $cf['type'];
        if (isset($cfData[$t])) {
            $cfData[$t] = (float)$cf['total_dr'] - (float)$cf['total_cr'];
        }
    }

    // 4. Card lists
    $stmtCust = $db->prepare("SELECT * FROM customers WHERE company_id = ? ORDER BY name ASC");
    $stmtCust->bind_param('i', $cid);
    $stmtCust->execute();
    $customers = $stmtCust->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmtSupp = $db->prepare("SELECT * FROM suppliers WHERE company_id = ? ORDER BY name ASC");
    $stmtSupp->bind_param('i', $cid);
    $stmtSupp->execute();
    $suppliers = $stmtSupp->get_result()->fetch_all(MYSQLI_ASSOC);

    // 5. Activity Logs for this student
    $stmtLogs = $db->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
    $stmtLogs->bind_param('i', $studentId);
    $stmtLogs->execute();
    $activityLogs = $stmtLogs->get_result()->fetch_all(MYSQLI_ASSOC);
}

$isBalanced = (count($journalEntries) > 0 && abs($totalDr - $totalCr) < 0.001);

require_once '../includes/header.php';
?>

<div class="mb-4">
    <a href="dashboard.php" class="btn btn-secondary btn-sm">
        <i data-lucide="arrow-left" style="width: 14px; height: 14px;"></i> Back
    </a>
</div>

<!-- STUDENT HEADER BANNER -->
<div class="card mb-4" style="background: linear-gradient(135deg, rgba(15, 23, 42, 0.95), rgba(30, 41, 59, 0.95)); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 16px; padding: 1.5rem;">
    
    <div class="flex justify-between items-center flex-wrap gap-4">
        <!-- Student Info -->
        <div class="flex items-center gap-3">
            <div style="width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.1rem; color: #fff; box-shadow: 0 4px 12px rgba(59,130,246,0.4);">
                <?= strtoupper(substr($student['name'], 0, 1)) ?>
            </div>
            <div>
                <div class="flex items-center gap-2 flex-wrap">
                    <h1 style="font-size: 1.35rem; font-weight: 800; color: #fff; margin: 0;"><?= htmlspecialchars($student['name']) ?></h1>
                    <span class="badge" style="background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); font-size: 0.75rem; padding: 2px 8px; border-radius: 99px;">
                        Section: <?= htmlspecialchars($studentSection) ?>
                    </span>
                    <span class="badge badge-neutral" style="font-size: 0.75rem;">Student ID #<?= $student['id'] ?></span>
                </div>
                <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 2px;">
                    <?= htmlspecialchars($student['email']) ?> · Registered on <?= date('M j, Y', strtotime($student['created_at'])) ?>
                </div>
            </div>
        </div>

        <!-- Simulation Switcher & Actions -->
        <div class="flex items-center gap-2 flex-wrap">
            <?php if (count($companies) > 0): ?>
            <form method="GET" style="display: flex; align-items: center; gap: 0.5rem;">
                <input type="hidden" name="student_id" value="<?= $studentId ?>">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                <select name="company_id" onchange="this.form.submit()" class="form-control" style="font-size: 0.85rem; padding: 0.4rem 0.75rem; border-radius: 8px; background: var(--bg-tertiary); color: var(--text-primary); max-width: 220px;">
                    <?php foreach ($companies as $co): ?>
                    <option value="<?= $co['id'] ?>" <?= ((int)$co['id'] === $companyId) ? 'selected' : '' ?>>
                        🏢 <?= htmlspecialchars($co['name']) ?> (<?= htmlspecialchars($co['business_type']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>

            <?php if ($selectedCompany): ?>
            <a href="dashboard.php?action=start_view&company_id=<?= $selectedCompany['id'] ?>&student_id=<?= $studentId ?>" 
               class="btn btn-secondary btn-sm" title="Browse student's full system in live view-only mode">
                <i data-lucide="external-link" style="width: 14px; height: 14px;"></i> Live View
            </a>
            <?php endif; ?>

            <button onclick="window.print()" class="btn btn-secondary btn-sm">
                <i data-lucide="printer" style="width: 14px; height: 14px;"></i> Print / PDF
            </button>
        </div>
    </div>

    <?php if ($selectedCompany): ?>
    <!-- Simulation Quick Summary Pill Bar -->
    <div style="margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;">
        <div>
            <span style="font-size: 0.7rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Simulation</span>
            <div style="font-weight: 700; color: #38bdf8; font-size: 0.95rem;"><?= htmlspecialchars($selectedCompany['name']) ?></div>
        </div>
        <div>
            <span style="font-size: 0.7rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Journal Entries</span>
            <div style="font-weight: 700; color: #fff; font-size: 0.95rem;"><?= count($journalEntries) ?> transactions</div>
        </div>
        <div>
            <span style="font-size: 0.7rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Total Debits / Credits</span>
            <div style="font-weight: 700; color: #fff; font-size: 0.95rem;"><?= fmtMoney($totalDr) ?></div>
        </div>
        <div>
            <span style="font-size: 0.7rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Balance Status</span>
            <div>
                <?php if (count($journalEntries) === 0): ?>
                    <span class="badge badge-neutral" style="font-size: 0.72rem;">No Entries</span>
                <?php elseif ($isBalanced): ?>
                    <span class="badge" style="background: rgba(16,185,129,0.15); color: #34d399; font-size: 0.72rem; font-weight: 700; border: 1px solid rgba(16,185,129,0.3); padding: 2px 8px; border-radius: 99px;">
                        ✓ Perfectly Balanced
                    </span>
                <?php else: ?>
                    <span class="badge" style="background: rgba(239,68,68,0.15); color: #f87171; font-size: 0.72rem; font-weight: 700; border: 1px solid rgba(239,68,68,0.3); padding: 2px 8px; border-radius: 99px;">
                        ⚠ Out of Balance (Diff: <?= fmtMoney(abs($totalDr - $totalCr)) ?>)
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <span style="font-size: 0.7rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Net Income (Loss)</span>
            <div style="font-weight: 700; color: <?= $netIncome >= 0 ? '#10b981' : '#ef4444' ?>; font-size: 0.95rem;">
                <?= fmtMoney($netIncome) ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php if (!$selectedCompany): ?>
<div class="card text-center" style="padding: 4rem 1rem; background: var(--bg-secondary);">
    <i data-lucide="folder-x" style="width: 56px; height: 56px; color: #64748b; margin-bottom: 1rem;"></i>
    <h3 style="font-size: 1.15rem; color: var(--text-primary);">No Accounting Simulation Created</h3>
    <p class="text-muted" style="margin-top: 0.5rem; max-width: 450px; margin-left: auto; margin-right: auto; font-size: 0.9rem;">
        This student has not yet created a simulation company in the system. Once they initialize a company and post journal entries, their complete ledger outputs will be displayed here.
    </p>
    <a href="dashboard.php" class="btn btn-primary btn-sm mt-3">
        &larr; Return to Roster
    </a>
</div>
<?php else: ?>

<!-- TABS NAVIGATION -->
<div class="flex gap-1 mb-4 flex-wrap" style="background: var(--bg-secondary); padding: 0.35rem; border-radius: 12px; border: 1px solid var(--border-color);">
    <?php
    $tabs = [
        'overview'  => ['icon' => 'pie-chart',     'label' => 'Overview'],
        'journals'  => ['icon' => 'book-open',     'label' => 'Journal Entries (' . count($journalEntries) . ')'],
        'ledger'    => ['icon' => 'file-text',     'label' => 'General Ledger'],
        'trial_bal' => ['icon' => 'scale',         'label' => 'Trial Balance'],
        'fin_stmt'  => ['icon' => 'trending-up',   'label' => 'Financial Statements'],
        'accounts'  => ['icon' => 'list-tree',     'label' => 'Chart of Accounts'],
        'audit'     => ['icon' => 'history',       'label' => 'Audit Trail (' . count($activityLogs) . ')']
    ];
    foreach ($tabs as $key => $tabInfo):
        $isActive = ($activeTab === $key);
    ?>
    <a href="?student_id=<?= $studentId ?>&company_id=<?= $companyId ?>&tab=<?= $key ?>" 
       class="btn btn-sm <?= $isActive ? 'btn-primary' : '' ?>"
       style="<?= $isActive ? 'border-radius: 8px;' : 'background: transparent; border: none; color: var(--text-muted); border-radius: 8px;' ?> display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
        <i data-lucide="<?= $tabInfo['icon'] ?>" style="width: 14px; height: 14px;"></i>
        <span><?= $tabInfo['label'] ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- ============================================================
     TAB CONTENT
     ============================================================ -->

<!-- TAB 1: OVERVIEW -->
<?php if ($activeTab === 'overview'): ?>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.25rem;">
    
    <!-- Left Column: Health Check -->
    <div class="card" style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; padding: 1.5rem;">
        <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="shield-check" style="width: 18px; height: 18px; color: #34d399;"></i>
            Simulation Health &amp; Equality Check
        </h3>

        <div style="display: flex; flex-direction: column; gap: 0.85rem;">
            <div style="padding: 0.85rem; border-radius: 10px; background: var(--bg-tertiary); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-weight: 600; font-size: 0.85rem; color: var(--text-primary);">Double-Entry Equality</div>
                    <div style="font-size: 0.75rem; color: #94a3b8;">Total debits must equal total credits</div>
                </div>
                <div>
                    <?php if (count($journalEntries) === 0): ?>
                        <span class="badge badge-neutral">No Data</span>
                    <?php elseif ($isBalanced): ?>
                        <span class="badge" style="background: rgba(16,185,129,0.15); color: #34d399; font-weight: 700; border: 1px solid rgba(16,185,129,0.3);">✓ Balanced</span>
                    <?php else: ?>
                        <span class="badge" style="background: rgba(239,68,68,0.15); color: #f87171; font-weight: 700; border: 1px solid rgba(239,68,68,0.3);">⚠ Out of Balance</span>
                    <?php endif; ?>
                </div>
            </div>

            <div style="padding: 0.85rem; border-radius: 10px; background: var(--bg-tertiary); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-weight: 600; font-size: 0.85rem; color: var(--text-primary);">Accounting Equation Check</div>
                    <div style="font-size: 0.75rem; color: #94a3b8;">Assets = Liabilities + Equity</div>
                </div>
                <div>
                    <?php 
                    $eqDiff = abs($totalAssets - ($totalLiabilities + $endingEquity));
                    if ($totalAssets > 0 && $eqDiff < 0.01): ?>
                        <span class="badge" style="background: rgba(16,185,129,0.15); color: #34d399; font-weight: 700; border: 1px solid rgba(16,185,129,0.3);">✓ Holds</span>
                    <?php else: ?>
                        <span class="badge" style="background: rgba(245,158,11,0.15); color: #fbbf24; font-weight: 700; border: 1px solid rgba(245,158,11,0.3);">Diff: <?= fmtMoney($eqDiff) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div style="padding: 0.85rem; border-radius: 10px; background: var(--bg-tertiary); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-weight: 600; font-size: 0.85rem; color: var(--text-primary);">Active Accounts Configured</div>
                    <div style="font-size: 0.75rem; color: #94a3b8;">Chart of accounts defined</div>
                </div>
                <div style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem;">
                    <?= count($accounts) ?> accounts
                </div>
            </div>

            <div style="padding: 0.85rem; border-radius: 10px; background: var(--bg-tertiary); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-weight: 600; font-size: 0.85rem; color: var(--text-primary);">Customer &amp; Supplier Master Lists</div>
                    <div style="font-size: 0.75rem; color: #94a3b8;">Subsidiary records count</div>
                </div>
                <div style="font-weight: 700; color: #60a5fa; font-size: 0.95rem;">
                    <?= count($customers) ?> AR / <?= count($suppliers) ?> AP
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Financial Snapshot -->
    <div class="card" style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; padding: 1.5rem;">
        <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="bar-chart-3" style="width: 18px; height: 18px; color: #60a5fa;"></i>
            Financial Position Summary
        </h3>

        <table class="table" style="width: 100%;">
            <tbody>
                <tr>
                    <td style="color: #94a3b8; font-size: 0.875rem;">Total Assets</td>
                    <td class="text-right" style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem;"><?= fmtMoney($totalAssets) ?></td>
                </tr>
                <tr>
                    <td style="color: #94a3b8; font-size: 0.875rem;">Total Liabilities</td>
                    <td class="text-right" style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem;"><?= fmtMoney($totalLiabilities) ?></td>
                </tr>
                <tr>
                    <td style="color: #94a3b8; font-size: 0.875rem;">Ending Owner's Equity</td>
                    <td class="text-right" style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem;"><?= fmtMoney($endingEquity) ?></td>
                </tr>
                <tr>
                    <td style="color: #94a3b8; font-size: 0.875rem;">Total Revenue</td>
                    <td class="text-right" style="font-weight: 700; color: #34d399; font-size: 0.95rem;"><?= fmtMoney($totalRevenue) ?></td>
                </tr>
                <tr>
                    <td style="color: #94a3b8; font-size: 0.875rem;">Total Expenses</td>
                    <td class="text-right" style="font-weight: 700; color: #f87171; font-size: 0.95rem;"><?= fmtMoney($totalExpenses) ?></td>
                </tr>
                <tr style="border-top: 2px solid var(--border-color);">
                    <td style="color: var(--text-primary); font-weight: 700; font-size: 0.95rem;">Net Income / (Loss)</td>
                    <td class="text-right" style="font-weight: 800; font-size: 1.1rem; color: <?= $netIncome >= 0 ? '#10b981' : '#ef4444' ?>;">
                        <?= fmtMoney($netIncome) ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

</div>
<?php endif; ?>

<!-- TAB 2: JOURNAL ENTRIES -->
<?php if ($activeTab === 'journals'): ?>
<div class="card" style="padding: 0; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary);">Student Journal Entries</h3>
            <p class="text-xs text-muted">All General &amp; Special Journal entries posted in this simulation.</p>
        </div>
        <div style="font-size: 0.85rem; color: #94a3b8;">
            Total Debit: <strong style="color:#34d399;"><?= fmtMoney($totalDr) ?></strong> · Total Credit: <strong style="color:#f87171;"><?= fmtMoney($totalCr) ?></strong>
        </div>
    </div>

    <div class="table-container" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
        <table class="table" style="width: 100%; min-width: 820px;">
            <thead>
                <tr>
                    <th style="min-width: 105px;" class="nowrap">Date</th>
                    <th style="min-width: 75px;" class="nowrap">Journal</th>
                    <th style="min-width: 110px;" class="nowrap">Ref No.</th>
                    <th style="min-width: 180px;">Account Title &amp; Description</th>
                    <th style="min-width: 110px;" class="nowrap text-right">Debit</th>
                    <th style="min-width: 110px;" class="nowrap text-right">Credit</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($journalEntries) === 0): ?>
                <tr><td colspan="6" class="text-center text-muted" style="padding: 3rem;">No journal entries posted yet.</td></tr>
                <?php endif; ?>

                <?php foreach ($journalEntries as $entry): ?>
                <tr style="background: rgba(255,255,255,0.02); border-top: 1px solid var(--border-color);">
                    <td class="nowrap" style="font-weight: 600; color: #e2e8f0; vertical-align: top;">
                        <?= date('M d, Y', strtotime($entry['date'])) ?>
                    </td>
                    <td class="nowrap" style="vertical-align: top;">
                        <span class="badge badge-primary" style="font-size: 0.7rem;"><?= htmlspecialchars($entry['journal_id'] ?? 'GJ') ?></span>
                    </td>
                    <td class="nowrap" style="font-size: 0.8rem; color: #94a3b8; vertical-align: top;">
                        <?= htmlspecialchars($entry['reference_no'] ?? '—') ?>
                    </td>
                    <td colspan="3" style="padding: 0;">
                        <!-- Line items table -->
                        <table style="width: 100%; border-collapse: collapse;">
                            <tbody>
                                <?php foreach ($entry['lines'] as $line): ?>
                                <tr>
                                    <td style="padding: 0.5rem 0.75rem; <?= (float)$line['credit'] > 0 ? 'padding-left: 2rem;' : '' ?> font-size: 0.85rem; color: var(--text-primary);">
                                        <?= htmlspecialchars($line['account_name']) ?>
                                        <span style="font-size: 0.7rem; color: #64748b; margin-left: 4px;">(<?= htmlspecialchars($line['account_code']) ?>)</span>
                                        <?php if ($line['description']): ?>
                                        <div style="font-size: 0.75rem; color: #94a3b8;"><?= htmlspecialchars($line['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right nowrap" style="width: 110px; padding: 0.5rem 0.75rem; font-size: 0.85rem; font-weight: 600; color: #10b981;">
                                        <?= (float)$line['debit'] > 0 ? fmtMoney($line['debit']) : '' ?>
                                    </td>
                                    <td class="text-right nowrap" style="width: 110px; padding: 0.5rem 0.75rem; font-size: 0.85rem; font-weight: 600; color: #ef4444;">
                                        <?= (float)$line['credit'] > 0 ? fmtMoney($line['credit']) : '' ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if ($entry['description']): ?>
                                <tr>
                                    <td colspan="3" style="padding: 0.35rem 0.75rem 0.6rem; font-size: 0.75rem; color: #94a3b8; font-style: italic;">
                                        <em>Explanation: <?= htmlspecialchars($entry['description']) ?></em>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- TAB 3: GENERAL LEDGER -->
<?php if ($activeTab === 'ledger'): ?>
<div style="display: flex; flex-direction: column; gap: 1.5rem;">
    <?php foreach ($accounts as $acc): 
        // Fetch ledger transactions for this account
        $stmtLines = $db->prepare("
            SELECT l.*, e.date, e.reference_no, e.description as entry_desc, e.journal_id
            FROM journal_entry_lines l
            JOIN journal_entries e ON l.journal_entry_id = e.id
            WHERE l.account_id = ? AND e.company_id = ? AND e.deleted_at IS NULL
            ORDER BY e.date ASC, l.id ASC
        ");
        $stmtLines->bind_param('ii', $acc['id'], $companyId);
        $stmtLines->execute();
        $ledgerLines = $stmtLines->get_result()->fetch_all(MYSQLI_ASSOC);

        if (count($ledgerLines) === 0 && (float)$acc['opening_balance'] == 0) continue;
    ?>
    <div class="card" style="padding: 0; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden;">
        <div style="padding: 1rem 1.25rem; background: var(--bg-tertiary); border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div>
                <span style="font-size: 0.75rem; font-weight: 700; color: #60a5fa;"><?= htmlspecialchars($acc['code']) ?></span>
                <strong style="font-size: 1rem; color: var(--text-primary); margin-left: 0.5rem;"><?= htmlspecialchars($acc['name']) ?></strong>
                <span class="badge badge-neutral" style="font-size: 0.7rem; margin-left: 0.5rem;"><?= htmlspecialchars($acc['category']) ?></span>
            </div>
            <div style="font-weight: 700; color: #34d399; font-size: 0.95rem;">
                Balance: <?= fmtMoney($acc['balance']) ?>
            </div>
        </div>

        <div class="table-container" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
            <table class="table" style="width: 100%; min-width: 680px; margin-bottom: 0;">
                <thead>
                    <tr>
                        <th style="min-width: 95px;" class="nowrap">Date</th>
                        <th style="min-width: 180px;">Explanation</th>
                        <th style="min-width: 100px;" class="nowrap">Ref / Jrnl</th>
                        <th style="min-width: 110px;" class="nowrap text-right">Debit</th>
                        <th style="min-width: 110px;" class="nowrap text-right">Credit</th>
                        <th style="min-width: 120px;" class="nowrap text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ((float)$acc['opening_balance'] != 0): ?>
                    <tr>
                        <td class="nowrap" style="color: #94a3b8;">—</td>
                        <td><em>Beginning Balance</em></td>
                        <td class="nowrap">—</td>
                        <td class="text-right nowrap">—</td>
                        <td class="text-right nowrap">—</td>
                        <td class="text-right nowrap" style="font-weight: 700;"><?= fmtMoney($acc['opening_balance']) ?></td>
                    </tr>
                    <?php endif; ?>

                    <?php 
                    $runningBal = (float)$acc['opening_balance'];
                    foreach ($ledgerLines as $line): 
                        $dr = (float)$line['debit'];
                        $cr = (float)$line['credit'];
                        if ($acc['category'] === 'Assets' || $acc['category'] === 'Expenses') {
                            $runningBal += ($dr - $cr);
                        } else {
                            $runningBal += ($cr - $dr);
                        }
                    ?>
                    <tr>
                        <td class="nowrap" style="color: #94a3b8;"><?= date('M d, Y', strtotime($line['date'])) ?></td>
                        <td><?= htmlspecialchars(($line['entry_desc'] ?: $line['description']) ?? '') ?></td>
                        <td class="nowrap">
                            <span class="badge badge-neutral" style="font-size: 0.65rem;"><?= htmlspecialchars($line['journal_id'] ?? '') ?></span>
                            <?= htmlspecialchars($line['reference_no'] ?? '') ?>
                        </td>
                        <td class="text-right nowrap" style="color: #10b981;"><?= $dr > 0 ? fmtMoney($dr) : '—' ?></td>
                        <td class="text-right nowrap" style="color: #ef4444;"><?= $cr > 0 ? fmtMoney($cr) : '—' ?></td>
                        <td class="text-right nowrap" style="font-weight: 600; color: var(--text-primary);"><?= fmtMoney($runningBal) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- TAB 4: TRIAL BALANCE -->
<?php if ($activeTab === 'trial_bal'): ?>
<div class="card" style="padding: 0; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); text-align: center;">
        <h3 style="font-size: 1.2rem; font-weight: 800; color: var(--text-primary);"><?= htmlspecialchars($selectedCompany['name']) ?></h3>
        <h4 style="font-size: 1rem; color: #94a3b8; margin-top: 2px;">Trial Balance</h4>
        <p class="text-xs text-muted" style="margin-top: 2px;">As of <?= date('F j, Y') ?></p>
    </div>

    <div class="table-container" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
        <table class="table" style="width: 100%; min-width: 680px;">
            <thead>
                <tr>
                    <th style="min-width: 130px;" class="nowrap">Account Code</th>
                    <th style="min-width: 240px;">Account Title</th>
                    <th style="min-width: 130px;" class="nowrap text-right">Debit (₱)</th>
                    <th style="min-width: 130px;" class="nowrap text-right">Credit (₱)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $tbDrSum = 0;
                $tbCrSum = 0;
                foreach ($accounts as $a):
                    $b = $a['balance'];
                    if ($b == 0) continue;
                    
                    $drVal = 0;
                    $crVal = 0;
                    if ($a['category'] === 'Assets' || $a['category'] === 'Expenses' || $a['sub_category'] === 'Withdrawals') {
                        if ($b >= 0) { $drVal = $b; } else { $crVal = abs($b); }
                    } else {
                        if ($b >= 0) { $crVal = $b; } else { $drVal = abs($b); }
                    }
                    $tbDrSum += $drVal;
                    $tbCrSum += $crVal;
                ?>
                <tr>
                    <td class="nowrap" style="color: #60a5fa; font-weight: 600;"><?= htmlspecialchars($a['code']) ?></td>
                    <td style="color: var(--text-primary); font-weight: 500;"><?= htmlspecialchars($a['name']) ?></td>
                    <td class="text-right nowrap" style="font-weight: 600;"><?= $drVal > 0 ? fmtMoney($drVal) : '—' ?></td>
                    <td class="text-right nowrap" style="font-weight: 600;"><?= $crVal > 0 ? fmtMoney($crVal) : '—' ?></td>
                </tr>
                <?php endforeach; ?>

                <!-- Totals Row -->
                <tr style="border-top: 2px solid var(--border-color); background: var(--bg-tertiary);">
                    <td colspan="2" style="font-weight: 800; color: var(--text-primary); font-size: 0.95rem;">TOTALS</td>
                    <td class="text-right nowrap" style="font-weight: 800; font-size: 1rem; color: <?= abs($tbDrSum - $tbCrSum) < 0.01 ? '#34d399' : '#f87171' ?>;">
                        <?= fmtMoney($tbDrSum) ?>
                    </td>
                    <td class="text-right nowrap" style="font-weight: 800; font-size: 1rem; color: <?= abs($tbDrSum - $tbCrSum) < 0.01 ? '#34d399' : '#f87171' ?>;">
                        <?= fmtMoney($tbCrSum) ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="padding: 1rem 1.5rem; background: var(--bg-tertiary); border-top: 1px solid var(--border-color); text-align: center;">
        <?php if (abs($tbDrSum - $tbCrSum) < 0.01 && $tbDrSum > 0): ?>
            <span style="color: #34d399; font-weight: 700; font-size: 0.9rem;">
                ✓ Trial Balance is in balance (Total Debits = Total Credits).
            </span>
        <?php else: ?>
            <span style="color: #f87171; font-weight: 700; font-size: 0.9rem;">
                ⚠ Trial Balance is out of balance. Discrepancy: <?= fmtMoney(abs($tbDrSum - $tbCrSum)) ?>
            </span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- TAB 5: FINANCIAL STATEMENTS -->
<?php if ($activeTab === 'fin_stmt'): ?>
<div style="display: flex; flex-direction: column; gap: 2rem;">
    
    <!-- Balance Sheet -->
    <div class="card" style="padding: 2rem; background: white; color: black; border-radius: 16px; max-width: 860px; margin: 0 auto; width: 100%;">
        <div class="text-center mb-4">
            <h2 style="font-size: 1.35rem; font-weight: 800; margin-bottom: 0.25rem;"><?= htmlspecialchars($selectedCompany['name']) ?></h2>
            <h3 style="font-size: 1.1rem; color: #4b5563; margin-bottom: 0.25rem;">Statement of Financial Position (Balance Sheet)</h3>
            <p style="color: #6b7280; font-size: 0.85rem; font-weight: 500;">As of <?= date('F j, Y') ?></p>
        </div>

        <table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
            <tbody>
                <tr><td colspan="2" style="padding-top: 0.75rem;"><strong>ASSETS</strong></td></tr>
                <tr><td colspan="2" style="padding-left: 1.5rem;"><strong>Current Assets</strong></td></tr>
                <?php foreach($accounts as $a): if($a['sub_category']==='Current Assets' && $a['balance']!=0): ?>
                    <tr><td style="padding-left: 2.5rem;"><?= htmlspecialchars($a['name']) ?></td><td class="text-right"><?= fmtMoney($a['balance']) ?></td></tr>
                <?php endif; endforeach; ?>
                <tr><td style="padding-left: 1.5rem;"><strong>Total Current Assets</strong></td><td class="text-right"><strong><?= fmtMoney($currentAssets) ?></strong></td></tr>
                
                <tr><td colspan="2" style="padding-left: 1.5rem; padding-top: 0.75rem;"><strong>Non-Current Assets</strong></td></tr>
                <?php foreach($accounts as $a): if($a['sub_category']==='Non-Current Assets' && $a['balance']!=0): ?>
                    <tr><td style="padding-left: 2.5rem;"><?= htmlspecialchars($a['name']) ?></td><td class="text-right"><?= fmtMoney($a['balance']) ?></td></tr>
                <?php endif; endforeach; ?>
                <tr><td style="padding-left: 1.5rem;"><strong>Total Non-Current Assets</strong></td><td class="text-right"><strong><?= fmtMoney($nonCurrentAssets) ?></strong></td></tr>
                
                <tr style="border-top: 1px solid black; border-bottom: 3px double black;">
                    <td style="padding: 0.75rem 0;"><strong>TOTAL ASSETS</strong></td>
                    <td class="text-right" style="padding: 0.75rem 0;"><strong><?= fmtMoney($totalAssets) ?></strong></td>
                </tr>

                <tr><td colspan="2" style="padding-top: 1.5rem;"><strong>LIABILITIES AND EQUITY</strong></td></tr>
                <tr><td colspan="2" style="padding-left: 1.5rem;"><strong>Current Liabilities</strong></td></tr>
                <?php foreach($accounts as $a): if($a['sub_category']==='Current Liabilities' && $a['balance']!=0): ?>
                    <tr><td style="padding-left: 2.5rem;"><?= htmlspecialchars($a['name']) ?></td><td class="text-right"><?= fmtMoney($a['balance']) ?></td></tr>
                <?php endif; endforeach; ?>
                <tr><td style="padding-left: 1.5rem;"><strong>Total Current Liabilities</strong></td><td class="text-right"><strong><?= fmtMoney($currentLiabilities) ?></strong></td></tr>

                <tr><td colspan="2" style="padding-left: 1.5rem; padding-top: 0.75rem;"><strong>Non-Current Liabilities</strong></td></tr>
                <?php foreach($accounts as $a): if($a['sub_category']==='Non-Current Liabilities' && $a['balance']!=0): ?>
                    <tr><td style="padding-left: 2.5rem;"><?= htmlspecialchars($a['name']) ?></td><td class="text-right"><?= fmtMoney($a['balance']) ?></td></tr>
                <?php endif; endforeach; ?>
                <tr><td style="padding-left: 1.5rem;"><strong>Total Non-Current Liabilities</strong></td><td class="text-right"><strong><?= fmtMoney($nonCurrentLiabilities) ?></strong></td></tr>
                <tr><td style="padding-left: 1rem; padding-top: 0.4rem;"><strong>Total Liabilities</strong></td><td class="text-right" style="padding-top: 0.4rem;"><strong><?= fmtMoney($totalLiabilities) ?></strong></td></tr>

                <tr><td colspan="2" style="padding-left: 1.5rem; padding-top: 0.75rem;"><strong>Owner's Equity</strong></td></tr>
                <tr><td style="padding-left: 2.5rem;">Ending Equity</td><td class="text-right"><?= fmtMoney($endingEquity) ?></td></tr>
                
                <tr style="border-top: 1px solid black; border-bottom: 3px double black;">
                    <td style="padding: 0.75rem 0;"><strong>TOTAL LIABILITIES AND EQUITY</strong></td>
                    <td class="text-right" style="padding: 0.75rem 0;"><strong><?= fmtMoney($totalLiabilities + $endingEquity) ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Income Statement -->
    <div class="card" style="padding: 2rem; background: white; color: black; border-radius: 16px; max-width: 860px; margin: 0 auto; width: 100%;">
        <div class="text-center mb-4">
            <h2 style="font-size: 1.35rem; font-weight: 800; margin-bottom: 0.25rem;"><?= htmlspecialchars($selectedCompany['name']) ?></h2>
            <h3 style="font-size: 1.1rem; color: #4b5563; margin-bottom: 0.25rem;">Statement of Comprehensive Income</h3>
            <p style="color: #6b7280; font-size: 0.85rem; font-weight: 500;">For the period ended <?= date('F j, Y') ?></p>
        </div>

        <table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
            <tbody>
                <tr><td colspan="2" style="padding-top: 0.75rem;"><strong>REVENUE</strong></td></tr>
                <?php foreach($accounts as $a): if($a['category']==='Revenue' && $a['balance']!=0): ?>
                    <tr><td style="padding-left: 2rem;"><?= htmlspecialchars($a['name']) ?></td><td class="text-right"><?= fmtMoney($a['balance']) ?></td></tr>
                <?php endif; endforeach; ?>
                <tr><td><strong>Total Revenue</strong></td><td class="text-right"><strong><?= fmtMoney($totalRevenue) ?></strong></td></tr>

                <tr><td colspan="2" style="padding-top: 1.25rem;"><strong>EXPENSES</strong></td></tr>
                <?php foreach($accounts as $a): if($a['category']==='Expenses' && $a['balance']!=0): ?>
                    <tr><td style="padding-left: 2rem;"><?= htmlspecialchars($a['name']) ?></td><td class="text-right"><?= fmtMoney($a['balance']) ?></td></tr>
                <?php endif; endforeach; ?>
                <tr><td><strong>Total Expenses</strong></td><td class="text-right" style="border-bottom: 1px solid black;"><strong><?= fmtMoney($totalExpenses) ?></strong></td></tr>

                <tr style="border-bottom: 3px double black;">
                    <td style="padding: 0.75rem 0;"><strong>NET INCOME (LOSS)</strong></td>
                    <td class="text-right" style="padding: 0.75rem 0; font-weight: 800; color: <?= $netIncome>=0?'inherit':'#ef4444' ?>;">
                        <strong><?= fmtMoney($netIncome) ?></strong>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

</div>
<?php endif; ?>

<!-- TAB 6: CHART OF ACCOUNTS -->
<?php if ($activeTab === 'accounts'): ?>
<div class="card" style="padding: 0; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color);">
        <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary);">Chart of Accounts &amp; Master Directory</h3>
        <p class="text-xs text-muted">Configured general ledger accounts for this simulation.</p>
    </div>

    <div class="table-container" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
        <table class="table" style="width: 100%; min-width: 680px;">
            <thead>
                <tr>
                    <th style="min-width: 120px;" class="nowrap">Account Code</th>
                    <th style="min-width: 200px;">Account Title</th>
                    <th style="min-width: 130px;" class="nowrap">Category</th>
                    <th style="min-width: 150px;" class="nowrap">Sub-Category</th>
                    <th style="min-width: 120px;" class="nowrap text-right">Current Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $acc): ?>
                <tr>
                    <td class="nowrap" style="font-weight: 600; color: #60a5fa;"><?= htmlspecialchars($acc['code']) ?></td>
                    <td style="color: var(--text-primary); font-weight: 500;"><?= htmlspecialchars($acc['name']) ?></td>
                    <td class="nowrap"><span class="badge badge-neutral" style="font-size: 0.7rem;"><?= htmlspecialchars($acc['category']) ?></span></td>
                    <td class="nowrap" style="color: #94a3b8; font-size: 0.8rem;"><?= htmlspecialchars($acc['sub_category'] ?: '—') ?></td>
                    <td class="text-right nowrap" style="font-weight: 600; color: <?= (float)$acc['balance'] >= 0 ? 'var(--text-primary)' : '#f87171' ?>;">
                        <?= fmtMoney($acc['balance']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- TAB 7: AUDIT TRAIL -->
<?php if ($activeTab === 'audit'): ?>
<div class="card" style="padding: 0; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color);">
        <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary);">Student Activity &amp; Audit Logs</h3>
        <p class="text-xs text-muted">Chronological history of operations performed by the student.</p>
    </div>

    <div class="table-container" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
        <table class="table" style="width: 100%; min-width: 680px;">
            <thead>
                <tr>
                    <th style="min-width: 150px;" class="nowrap">Timestamp</th>
                    <th style="min-width: 110px;" class="nowrap">Action</th>
                    <th style="min-width: 140px;" class="nowrap">Module</th>
                    <th style="min-width: 280px;">Description</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($activityLogs) === 0): ?>
                <tr><td colspan="4" class="text-center text-muted" style="padding: 2.5rem;">No activity logs recorded.</td></tr>
                <?php endif; ?>

                <?php foreach ($activityLogs as $log): ?>
                <tr>
                    <td class="nowrap" style="color: #94a3b8; font-size: 0.8rem;">
                        <?= date('M d, Y h:i A', strtotime($log['created_at'])) ?>
                    </td>
                    <td class="nowrap">
                        <span class="badge badge-primary" style="font-size: 0.7rem;"><?= htmlspecialchars($log['action']) ?></span>
                    </td>
                    <td class="nowrap" style="color: #60a5fa; font-size: 0.85rem; font-weight: 500;">
                        <?= htmlspecialchars($log['module'] ?? 'System') ?>
                    </td>
                    <td style="color: #e2e8f0; font-size: 0.85rem;">
                        <?= htmlspecialchars($log['description'] ?? '—') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<style>
@media print {
    body * { visibility: hidden; }
    .card, .card * { visibility: visible; }
    .page-header, .sidebar, .topbar, .btn, .no-print, .flex.gap-1 { display: none !important; }
    .main-wrapper { padding: 0 !important; margin: 0 !important; width: 100% !important; }
    .card { position: absolute; left: 0; top: 0; width: 100% !important; box-shadow: none !important; border: none !important; color: black !important; background: white !important; }
}
</style>

<?php require_once '../includes/footer.php'; ?>