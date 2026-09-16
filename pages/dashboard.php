<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/access_control.php';

// Handle GET actions for instructors (start_view / exit_view) — BEFORE header
$_userRole_early = $_SESSION['user_role'] ?? 'Student';
$_isInstructor_early = in_array($_userRole_early, ['Instructor', 'Admin']);

if ($_isInstructor_early && isset($_GET['action'])) {
    $db_early = get_db();
    if ($_GET['action'] === 'exit_view') {
        end_instructor_view();
        header('Location: ' . BASE_URL . 'pages/dashboard.php?msg=exited');
        exit;
    } elseif ($_GET['action'] === 'start_view') {
        $companyId = (int)($_GET['company_id'] ?? 0);
        $studentId = (int)($_GET['student_id'] ?? 0);
        if ($companyId > 0 && $studentId > 0) {
            $stmtS = $db_early->prepare("SELECT u.name as student_name, c.name as company_name FROM users u JOIN companies c ON c.user_id = u.id WHERE u.id = ? AND c.id = ?");
            $stmtS->bind_param('ii', $studentId, $companyId);
            $stmtS->execute();
            $res = $stmtS->get_result()->fetch_assoc();
            if ($res) {
                start_instructor_view($companyId, $res['student_name'], $res['company_name']);
                header('Location: ' . BASE_URL . 'pages/dashboard.php');
                exit;
            }
        }
    }
}

require_once '../includes/header.php';

$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;
$userRole = $_SESSION['user_role'] ?? 'Student';
$isInstructorRole = in_array($userRole, ['Instructor', 'Admin']);
$instructorId = (int)$_SESSION['user_id'];

$instructorMsg = '';
$instructorMsgType = 'success';

// Handle instructor assignment POST actions if submitted from dashboard
if ($isInstructorRole && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $instAction = $_POST['action'] ?? '';
    if ($instAction === 'assign_student') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $section = trim($_POST['section'] ?? 'Section 1');
        if ($section === '') $section = 'Section 1';
        if ($studentId > 0) {
            $stmtIns = $db->prepare("INSERT INTO instructor_students (instructor_id, student_id, section) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE section = VALUES(section)");
            $stmtIns->bind_param('iis', $instructorId, $studentId, $section);
            if ($stmtIns->execute()) {
                $instructorMsg = 'Student assigned successfully to ' . htmlspecialchars($section) . '!';
                $instructorMsgType = 'success';
            }
        }
    } elseif ($instAction === 'unassign_student') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        if ($studentId > 0) {
            $stmtDel = $db->prepare("DELETE FROM instructor_students WHERE instructor_id = ? AND student_id = ?");
            $stmtDel->bind_param('ii', $instructorId, $studentId);
            $stmtDel->execute();
            $instructorMsg = 'Student removed from your roster.';
            $instructorMsgType = 'warning';
        }
    } elseif ($instAction === 'bulk_assign_all') {
        $section = trim($_POST['section'] ?? 'BSA 1-A');
        if ($section === '') $section = 'BSA 1-A';
        $resStudents = $db->query("SELECT id FROM users WHERE role = 'Student'");
        $count = 0;
        while ($st = $resStudents->fetch_assoc()) {
            $sid = (int)$st['id'];
            $insStmt = $db->prepare("INSERT IGNORE INTO instructor_students (instructor_id, student_id, section) VALUES (?, ?, ?)");
            $insStmt->bind_param('iis', $instructorId, $sid, $section);
            $insStmt->execute();
            $count++;
        }
        $instructorMsg = "Enrolled {$count} student(s) into {$section}!";
        $instructorMsgType = 'success';
    }
}

// ─────────────────────────────────────────────────────────────
// INSTRUCTOR DASHBOARD DATA
// ─────────────────────────────────────────────────────────────
$instructorStudentsData = [];
$instructorSectionsList = [];
$availableStudentsForAssign = [];
$totalStudents = 0;
$totalSimulations = 0;
$totalTransactions = 0;
$balancedCount = 0;

if ($isInstructorRole) {
    if ($userRole === 'Admin') {
        $qInst = "
            SELECT u.id as student_id, u.name as student_name, u.email as student_email,
                   IFNULL(ins.section, 'General') as section
            FROM users u
            LEFT JOIN instructor_students ins ON u.id = ins.student_id
            WHERE u.role = 'Student'
            GROUP BY u.id
            ORDER BY u.name ASC
        ";
        $instStudentsRes = $db->query($qInst)->fetch_all(MYSQLI_ASSOC);
    } else {
        $stmtInst = $db->prepare("
            SELECT u.id as student_id, u.name as student_name, u.email as student_email, ins.section
            FROM instructor_students ins
            JOIN users u ON ins.student_id = u.id
            WHERE ins.instructor_id = ? AND u.role = 'Student'
            ORDER BY ins.section ASC, u.name ASC
        ");
        $stmtInst->bind_param('i', $instructorId);
        $stmtInst->execute();
        $instStudentsRes = $stmtInst->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    $totalStudents = count($instStudentsRes);

    foreach ($instStudentsRes as $st) {
        $sid = (int)$st['student_id'];
        if (!in_array($st['section'], $instructorSectionsList)) {
            $instructorSectionsList[] = $st['section'];
        }

        $stmtC = $db->prepare("
            SELECT c.*,
                (SELECT COUNT(*) FROM journal_entries e WHERE e.company_id = c.id AND e.deleted_at IS NULL) as entry_count,
                (SELECT MAX(e.date) FROM journal_entries e WHERE e.company_id = c.id AND e.deleted_at IS NULL) as latest_entry_date,
                (SELECT IFNULL(SUM(l.debit), 0) FROM journal_entry_lines l JOIN journal_entries e ON l.journal_entry_id = e.id WHERE e.company_id = c.id AND e.deleted_at IS NULL) as total_dr,
                (SELECT IFNULL(SUM(l.credit), 0) FROM journal_entry_lines l JOIN journal_entries e ON l.journal_entry_id = e.id WHERE e.company_id = c.id AND e.deleted_at IS NULL) as total_cr
            FROM companies c
            WHERE c.user_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmtC->bind_param('i', $sid);
        $stmtC->execute();
        $companies = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC);

        $st['companies'] = $companies;
        $totalSimulations += count($companies);

        foreach ($companies as $co) {
            $totalTransactions += (int)$co['entry_count'];
            if ($co['entry_count'] > 0 && abs((float)$co['total_dr'] - (float)$co['total_cr']) < 0.001) {
                $balancedCount++;
            }
        }

        $instructorStudentsData[] = $st;
    }

    $stmtAvail = $db->prepare("
        SELECT id, name, email 
        FROM users 
        WHERE role = 'Student' 
          AND id NOT IN (SELECT student_id FROM instructor_students WHERE instructor_id = ?)
        ORDER BY name ASC
    ");
    $stmtAvail->bind_param('i', $instructorId);
    $stmtAvail->execute();
    $availableStudentsForAssign = $stmtAvail->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ─────────────────────────────────────────────────────────────
// STUDENT DASHBOARD DATA
// ─────────────────────────────────────────────────────────────
$assets = 0; $liabilities = 0; $equity = 0; $revenue = 0; $expenses = 0; $cash = 0;
$assetData = [];
$recent = [];
$cfLabels = []; $cfIn = []; $cfOut = [];
$period_label = date('F Y');

if (!$isInstructorRole) {
    if (!$company_id) {
        echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to view the dashboard.</div>';
        require_once '../includes/footer.php';
        exit;
    }

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
        default:
            $date_from = date('Y-m-01');
            $date_to   = $today;
            $period_label = date('F Y');
            break;
    }

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

    foreach($cashFlowRes as $cf) {
        $cfLabels[] = $cf['day'];
        $cfIn[] = $cf['cash_in'];
        $cfOut[] = $cf['cash_out'];
    }
}
$net_income = $revenue - $expenses;

function fmt($n) {
    return '₱' . number_format(abs($n), 2);
}

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

<?php if ($isInstructorRole): ?>
<div class="page-header flex justify-between items-center mb-4">
    <div class="page-header-text">
        <h1 class="page-title" style="display: flex; align-items: center; gap: 0.6rem;">
            <i data-lucide="graduation-cap" style="width: 28px; height: 28px; color: #34d399;"></i>
            <span>Instructor Monitoring Hub</span>
        </h1>
        <p class="text-sm text-muted" style="margin-top: 0.25rem;">
            Monitor assigned students, evaluate accounting simulations, and review submitted journals and financial statements.
        </p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <button class="btn btn-secondary btn-sm" onclick="openModal('bulkAssignModalDash')">
            <i data-lucide="users" style="width: 15px; height: 15px;"></i> Enroll All Students
        </button>
        <button class="btn btn-primary btn-sm" onclick="openModal('assignModalDash')">
            <i data-lucide="user-plus" style="width: 15px; height: 15px;"></i> Assign Student
        </button>
    </div>
</div>
<?php else: ?>
<div style="text-align: center; margin-bottom: 2rem; margin-top: 0.5rem;">
    <h2 style="font-size: 1.75rem; font-weight: 600; color: var(--text-primary); letter-spacing: -0.01em;"><?= $greeting ?>, <?= $userName ?>!</h2>
</div>
<?php endif; ?>

<?php if ($instructorMsg): ?>
<div class="alert alert-<?= $instructorMsgType ?> mb-4" style="border-radius: var(--radius-md);">
    <?= htmlspecialchars($instructorMsg) ?>
</div>
<?php endif; ?>

<?php if ($isInstructorRole): ?>
<!-- ============================================================
     INSTRUCTOR DASHBOARD (METRICS + ROSTER)
     ============================================================ -->

<!-- Metric Cards -->
<div class="metric-cards-grid mb-4" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
    
    <div class="metric-card" style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem;">
        <div class="flex justify-between items-center mb-2">
            <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #94a3b8; letter-spacing: 0.05em;">Assigned Students</span>
            <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(59,130,246,0.15); color: #60a5fa; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="users" style="width: 18px; height: 18px;"></i>
            </div>
        </div>
        <div style="font-size: 1.85rem; font-weight: 800; color: var(--text-primary);"><?= $totalStudents ?></div>
        <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">In <?= count($instructorSectionsList) ?> active section(s)</div>
    </div>

    <div class="metric-card" style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem;">
        <div class="flex justify-between items-center mb-2">
            <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #94a3b8; letter-spacing: 0.05em;">Active Simulations</span>
            <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(16,185,129,0.15); color: #34d399; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="building-2" style="width: 18px; height: 18px;"></i>
            </div>
        </div>
        <div style="font-size: 1.85rem; font-weight: 800; color: var(--text-primary);"><?= $totalSimulations ?></div>
        <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">Student practice entities</div>
    </div>

    <div class="metric-card" style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem;">
        <div class="flex justify-between items-center mb-2">
            <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #94a3b8; letter-spacing: 0.05em;">Total Journal Entries</span>
            <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(245,158,11,0.15); color: #fbbf24; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="book-marked" style="width: 18px; height: 18px;"></i>
            </div>
        </div>
        <div style="font-size: 1.85rem; font-weight: 800; color: var(--text-primary);"><?= number_format($totalTransactions) ?></div>
        <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">Recorded transactions</div>
    </div>

    <div class="metric-card" style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem;">
        <div class="flex justify-between items-center mb-2">
            <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #94a3b8; letter-spacing: 0.05em;">Balanced Submissions</span>
            <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(99,102,241,0.15); color: #818cf8; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="scale" style="width: 18px; height: 18px;"></i>
            </div>
        </div>
        <div style="font-size: 1.85rem; font-weight: 800; color: var(--text-primary);"><?= $balancedCount ?></div>
        <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">Equal debits &amp; credits</div>
    </div>

</div>

<!-- Student Activity & Output Monitoring Table -->
<div class="card" style="padding: 0; overflow: hidden; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
    
    <!-- Header with Controls -->
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <p class="text-xs text-muted" style="margin: 0; font-size: 0.85rem;">Select a student to inspect their journals, ledgers, trial balance, and financial statements.</p>
        </div>
        
        <div class="flex items-center gap-2 flex-wrap">
            <div style="position: relative;">
                <input type="text" id="dashRosterSearch" placeholder="Search student or simulation..." oninput="filterDashRoster()"
                       class="form-control" style="padding-left: 2rem; width: 240px; font-size: 0.85rem; border-radius: 99px; height: 36px;">
                <i data-lucide="search" style="width: 14px; height: 14px; position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
            </div>
            
            <select id="dashSectionFilter" onchange="filterDashRoster()" class="form-control" style="width: 140px; font-size: 0.85rem; border-radius: 99px; height: 36px;">
                <option value="">All Sections</option>
                <?php foreach($instructorSectionsList as $sec): ?>
                <option value="<?= htmlspecialchars($sec) ?>"><?= htmlspecialchars($sec) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Table -->
    <div class="table-container" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
        <table class="table" id="dashStudentRosterTable" style="width: 100%; min-width: 860px; margin-bottom: 0;">
            <thead>
                <tr>
                    <th style="min-width: 220px;">Student Information</th>
                    <th style="min-width: 110px;" class="nowrap">Section</th>
                    <th style="min-width: 200px;">Simulation Company</th>
                    <th style="min-width: 100px;" class="nowrap text-center">Entries</th>
                    <th style="min-width: 130px;" class="nowrap text-center">Balance Status</th>
                    <th style="min-width: 110px;" class="nowrap text-center">Progress</th>
                    <th style="min-width: 170px;" class="nowrap text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($instructorStudentsData) === 0): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted" style="padding: 3rem 1rem;">
                        <i data-lucide="graduation-cap" style="width: 44px; height: 44px; margin-bottom: 0.75rem; opacity: 0.4;"></i>
                        <p style="font-size: 0.95rem; font-weight: 600; color: #94a3b8;">No students assigned to your roster yet</p>
                        <p style="font-size: 0.8rem; margin-top: 0.25rem;">Assign registered students or click Enroll All to start checking their simulation outputs.</p>
                        <button class="btn btn-primary btn-sm mt-3" onclick="openModal('assignModalDash')">
                            <i data-lucide="user-plus" style="width: 14px; height: 14px;"></i> Assign First Student
                        </button>
                    </td>
                </tr>
                <?php endif; ?>

                <?php 
                foreach ($instructorStudentsData as $st): 
                    $stName = $st['student_name'];
                    $stEmail = $st['student_email'];
                    $stSection = $st['section'];
                    $stInitials = strtoupper(substr($stName, 0, 1) . (strpos($stName, ' ') !== false ? substr(explode(' ', $stName)[1], 0, 1) : ''));
                    $cos = $st['companies'];
                    $hasCos = count($cos) > 0;
                    
                    if (!$hasCos):
                ?>
                <tr class="dash-roster-row" data-search="<?= strtolower(htmlspecialchars($stName . ' ' . $stEmail . ' ' . $stSection)) ?>" data-section="<?= htmlspecialchars($stSection) ?>">
                    <td>
                        <div class="flex items-center gap-3">
                            <div style="width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; color: #fff; flex-shrink: 0;">
                                <?= htmlspecialchars($stInitials) ?>
                            </div>
                            <div>
                                <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);"><?= htmlspecialchars($stName) ?></div>
                                <div style="font-size: 0.75rem; color: #94a3b8;"><?= htmlspecialchars($stEmail) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge" style="background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); font-size: 0.75rem; padding: 2px 8px; border-radius: 99px;">
                            <?= htmlspecialchars($stSection) ?>
                        </span>
                    </td>
                    <td><span style="font-size: 0.8rem; color: #64748b; font-style: italic;">No simulation created yet</span></td>
                    <td class="text-center"><span class="text-muted" style="font-size: 0.85rem;">0</span></td>
                    <td class="text-center"><span class="badge badge-neutral" style="font-size: 0.7rem;">Empty</span></td>
                    <td class="text-center"><span class="badge" style="background: rgba(100,116,139,0.15); color: #94a3b8; font-size: 0.75rem; padding: 2px 8px; border-radius: 99px;">Not Started</span></td>
                    <td class="text-right">
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this student from your roster?');">
                            <input type="hidden" name="action" value="unassign_student">
                            <input type="hidden" name="student_id" value="<?= $st['student_id'] ?>">
                            <button type="submit" class="icon-btn text-danger" title="Unassign Student">
                                <i data-lucide="user-minus" style="width: 14px; height: 14px;"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php 
                    else:
                        foreach ($cos as $co):
                            $coName = $co['name'];
                            $entries = (int)$co['entry_count'];
                            $totalDr = (float)$co['total_dr'];
                            $totalCr = (float)$co['total_cr'];
                            $isBalanced = ($entries > 0 && abs($totalDr - $totalCr) < 0.001);
                            
                            $status = 'In Progress';
                            $statusBadge = 'background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3);';
                            if ($entries >= 5 && $isBalanced) {
                                $status = 'Completed';
                                $statusBadge = 'background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3);';
                            } elseif ($entries === 0) {
                                $status = 'Not Started';
                                $statusBadge = 'background: rgba(100,116,139,0.15); color: #94a3b8; border: 1px solid rgba(100,116,139,0.3);';
                            }
                ?>
                <tr class="dash-roster-row" data-search="<?= strtolower(htmlspecialchars($stName . ' ' . $stEmail . ' ' . $stSection . ' ' . $coName)) ?>" data-section="<?= htmlspecialchars($stSection) ?>">
                    <td>
                        <div class="flex items-center gap-3">
                            <div style="width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; color: #fff; flex-shrink: 0;">
                                <?= htmlspecialchars($stInitials) ?>
                            </div>
                            <div>
                                <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);"><?= htmlspecialchars($stName) ?></div>
                                <div style="font-size: 0.75rem; color: #94a3b8;"><?= htmlspecialchars($stEmail) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge" style="background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); font-size: 0.75rem; padding: 2px 8px; border-radius: 99px;">
                            <?= htmlspecialchars($stSection) ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight: 600; font-size: 0.85rem; color: #38bdf8;">
                            <?= htmlspecialchars($coName) ?>
                        </div>
                        <div style="font-size: 0.7rem; color: #64748b;">
                            <?= htmlspecialchars($co['business_type'] ?? 'Service') ?> · <?= $co['latest_entry_date'] ? 'Active: ' . date('M j, Y', strtotime($co['latest_entry_date'])) : 'Created: ' . date('M j, Y', strtotime($co['created_at'])) ?>
                        </div>
                    </td>
                    <td class="text-center">
                        <span style="font-weight: 700; font-size: 0.9rem; color: var(--text-primary);"><?= $entries ?></span>
                    </td>
                    <td class="text-center">
                        <?php if ($entries === 0): ?>
                            <span class="badge badge-neutral" style="font-size: 0.7rem;">Empty</span>
                        <?php elseif ($isBalanced): ?>
                            <span class="badge" style="background: rgba(16,185,129,0.15); color: #34d399; font-size: 0.7rem; font-weight: 700; padding: 2px 8px; border-radius: 99px; border: 1px solid rgba(16,185,129,0.3);">
                                <i data-lucide="check" style="width: 11px; height: 11px; display: inline-block; vertical-align: middle;"></i> Balanced
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background: rgba(239,68,68,0.15); color: #f87171; font-size: 0.7rem; font-weight: 700; padding: 2px 8px; border-radius: 99px; border: 1px solid rgba(239,68,68,0.3);">
                                ⚠ Diff: ₱<?= number_format(abs($totalDr - $totalCr), 2) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge" style="<?= $statusBadge ?> font-size: 0.75rem; padding: 2px 8px; border-radius: 99px;">
                            <?= $status ?>
                        </span>
                    </td>
                    <td class="text-right">
                        <div class="flex items-center justify-end gap-1">
                            <a href="<?= BASE_URL ?>pages/student_output.php?student_id=<?= $st['student_id'] ?>&company_id=<?= $co['id'] ?>" 
                               class="btn btn-primary btn-sm" style="padding: 4px 10px; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;" title="Review Student Outputs">
                                <i data-lucide="file-search" style="width: 13px; height: 13px;"></i> Inspect
                            </a>
                            <a href="<?= BASE_URL ?>pages/dashboard.php?action=start_view&company_id=<?= $co['id'] ?>&student_id=<?= $st['student_id'] ?>" 
                               class="btn btn-secondary btn-sm" style="padding: 4px 8px; font-size: 0.75rem;" title="Live View">
                                <i data-lucide="external-link" style="width: 13px; height: 13px;"></i>
                            </a>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this student from your roster?');">
                                <input type="hidden" name="action" value="unassign_student">
                                <input type="hidden" name="student_id" value="<?= $st['student_id'] ?>">
                                <button type="submit" class="icon-btn text-danger" title="Unassign Student" style="padding: 4px;">
                                    <i data-lucide="user-minus" style="width: 14px; height: 14px;"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php 
                        endforeach;
                    endif;
                endforeach; 
                ?>
            </tbody>
        </table>
    </div>
</div>

<!-- DASHBOARD ASSIGN MODALS -->
<div id="assignModalDash" class="modal-backdrop" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 999; align-items: center; justify-content: center;">
    <div class="modal card" style="background: var(--bg-secondary); border: 1px solid var(--border-color); width: 100%; max-width: 480px; padding: 1.75rem; border-radius: 16px;">
        <div class="modal-header flex justify-between items-center mb-4">
            <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="user-plus" style="width: 20px; height: 20px; color: #3b82f6;"></i>
                Assign Student to Section
            </h3>
            <button type="button" class="icon-btn" onclick="closeModal('assignModalDash')"><i data-lucide="x" style="width: 18px; height: 18px;"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="assign_student">
            
            <div class="form-group mb-3">
                <label class="form-label">Select Registered Student</label>
                <?php if (count($availableStudentsForAssign) > 0): ?>
                <select name="student_id" class="form-control" required style="background: var(--bg-tertiary); color: var(--text-primary);">
                    <option value="">-- Choose a Student --</option>
                    <?php foreach ($availableStudentsForAssign as $av): ?>
                    <option value="<?= $av['id'] ?>"><?= htmlspecialchars($av['name']) ?> (<?= htmlspecialchars($av['email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <div class="alert alert-warning" style="font-size: 0.85rem; padding: 0.75rem;">
                    All registered students are already assigned to your roster.
                </div>
                <?php endif; ?>
            </div>

            <div class="form-group mb-4">
                <label class="form-label">Class Section / Course Code</label>
                <input type="text" name="section" class="form-control" placeholder="e.g. BSA 1-A" required value="BSA 1-A" style="background: var(--bg-tertiary); color: var(--text-primary);">
            </div>

            <div class="modal-footer flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" onclick="closeModal('assignModalDash')">Cancel</button>
                <button type="submit" class="btn btn-primary" <?= count($availableStudentsForAssign) === 0 ? 'disabled' : '' ?>>
                    Assign Student
                </button>
            </div>
        </form>
    </div>
</div>

<div id="bulkAssignModalDash" class="modal-backdrop" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 999; align-items: center; justify-content: center;">
    <div class="modal card" style="background: var(--bg-secondary); border: 1px solid var(--border-color); width: 100%; max-width: 480px; padding: 1.75rem; border-radius: 16px;">
        <div class="modal-header flex justify-between items-center mb-4">
            <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="users" style="width: 20px; height: 20px; color: #10b981;"></i>
                Bulk Enroll All Registered Students
            </h3>
            <button type="button" class="icon-btn" onclick="closeModal('bulkAssignModalDash')"><i data-lucide="x" style="width: 18px; height: 18px;"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="bulk_assign_all">
            <div class="form-group mb-4">
                <label class="form-label">Assign to Section</label>
                <input type="text" name="section" class="form-control" placeholder="e.g. BSA 1-A" required value="BSA 1-A" style="background: var(--bg-tertiary); color: var(--text-primary);">
            </div>
            <div class="modal-footer flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" onclick="closeModal('bulkAssignModalDash')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #10b981; border-color: #10b981;">
                    Enroll All Students
                </button>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ============================================================
     STUDENT SIMULATION DASHBOARD
     ============================================================ -->

<!-- Metric Cards -->
<div id="metric-cards-grid" class="metric-cards-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
    <?php
    $periods_map = [
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'last_30'    => 'Last 30 Days',
        'last_90'    => 'Last 90 Days',
        'this_year'  => 'This Year',
        'all'        => 'All Time',
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
<div id="dashboard-widgets" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
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
    <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
    <table class="table" style="min-width: 640px;">
        <thead>
            <tr>
                <th style="min-width: 100px; font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;" class="nowrap">Date</th>
                <th style="min-width: 180px; font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Description</th>
                <th style="min-width: 80px; font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;" class="nowrap">Journal</th>
                <th style="min-width: 90px; font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;" class="nowrap">Type</th>
                <th style="min-width: 110px; font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase; text-align: right;" class="nowrap">Debit</th>
                <th style="min-width: 110px; font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase; text-align: right;" class="nowrap">Credit</th>
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
            <tr><td colspan="6" class="text-center text-muted" style="padding: 2rem;">No transactions yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div><!-- end scroll wrapper -->
    </div>
</div> <!-- End Widgets Container -->
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function filterDashRoster() {
    const search = document.getElementById('dashRosterSearch').value.toLowerCase().trim();
    const section = document.getElementById('dashSectionFilter').value;
    const rows = document.querySelectorAll('#dashStudentRosterTable tbody .dash-roster-row');

    rows.forEach(row => {
        const text = row.getAttribute('data-search') || '';
        const rowSec = row.getAttribute('data-section') || '';

        const matchesSearch = (!search || text.includes(search));
        const matchesSection = (!section || rowSec === section);

        if (matchesSearch && matchesSection) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

// Period dropdown toggle
function togglePeriodDropdown(idx) {
    document.querySelectorAll('[id^="period-dd-"]').forEach(dd => {
        if (dd.id !== 'period-dd-' + idx) dd.style.display = 'none';
    });
    const dd = document.getElementById('period-dd-' + idx);
    if (dd) dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('[id^="period-dd-"]') && !e.target.closest('button[onclick^="togglePeriodDropdown"]')) {
        document.querySelectorAll('[id^="period-dd-"]').forEach(dd => dd.style.display = 'none');
    }
});

<?php if (!$isInstructorRole && $company_id): ?>
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
const ctxRev = document.getElementById('revExpChart');
if(ctxRev) {
    new Chart(ctxRev, {
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
}

// Asset Pie Chart
const ctxAsset = document.getElementById('assetChart');
if(ctxAsset) {
    new Chart(ctxAsset, {
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
}

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
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
