<?php
require_once '../config.php';

// Redirect to unified dashboard
$queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ' . BASE_URL . 'pages/dashboard.php' . $queryString);
exit;


// Handle POST actions (Assign / Unassign)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_student') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $section = trim($_POST['section'] ?? 'Section 1');
        if ($section === '') $section = 'Section 1';

        if ($studentId > 0) {
            $stmt = $db->prepare("INSERT INTO instructor_students (instructor_id, student_id, section) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE section = VALUES(section)");
            $stmt->bind_param('iis', $instructorId, $studentId, $section);
            if ($stmt->execute()) {
                $message = 'Student assigned successfully to ' . htmlspecialchars($section) . '!';
                $msgType = 'success';
            } else {
                $message = 'Failed to assign student.';
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'unassign_student') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        if ($studentId > 0) {
            $stmt = $db->prepare("DELETE FROM instructor_students WHERE instructor_id = ? AND student_id = ?");
            $stmt->bind_param('ii', $instructorId, $studentId);
            $stmt->execute();
            $message = 'Student removed from your roster.';
            $msgType = 'warning';
        }
    } elseif ($action === 'bulk_assign_all') {
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
        $message = "Enrolled {$count} student(s) into {$section}!";
        $msgType = 'success';
    }
}

// Fetch assigned students for this instructor (or all students if Admin)
if ($isAdmin) {
    $query = "
        SELECT 
            u.id as student_id,
            u.name as student_name,
            u.email as student_email,
            u.created_at as student_registered_at,
            IFNULL(ins.section, 'General') as section,
            ins.created_at as assigned_at
        FROM users u
        LEFT JOIN instructor_students ins ON u.id = ins.student_id
        WHERE u.role = 'Student'
        GROUP BY u.id
        ORDER BY u.name ASC
    ";
    $studentsRes = $db->query($query)->fetch_all(MYSQLI_ASSOC);
} else {
    $stmt = $db->prepare("
        SELECT 
            u.id as student_id,
            u.name as student_name,
            u.email as student_email,
            u.created_at as student_registered_at,
            ins.section,
            ins.created_at as assigned_at
        FROM instructor_students ins
        JOIN users u ON ins.student_id = u.id
        WHERE ins.instructor_id = ? AND u.role = 'Student'
        ORDER BY ins.section ASC, u.name ASC
    ");
    $stmt->bind_param('i', $instructorId);
    $stmt->execute();
    $studentsRes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// For each student, gather simulation companies and summary metrics
$studentsData = [];
$totalStudents = count($studentsRes);
$totalSimulations = 0;
$totalTransactions = 0;
$balancedCount = 0;
$sectionsList = [];

foreach ($studentsRes as $st) {
    $sid = (int)$st['student_id'];
    if (!in_array($st['section'], $sectionsList)) {
        $sectionsList[] = $st['section'];
    }

    // Fetch student's companies
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
    $st['total_companies'] = count($companies);
    $totalSimulations += count($companies);

    $stEntrySum = 0;
    foreach ($companies as $co) {
        $stEntrySum += (int)$co['entry_count'];
        $totalTransactions += (int)$co['entry_count'];
        if ($co['entry_count'] > 0 && abs((float)$co['total_dr'] - (float)$co['total_cr']) < 0.001) {
            $balancedCount++;
        }
    }
    $st['total_entries'] = $stEntrySum;

    $studentsData[] = $st;
}

// Fetch list of all students not yet assigned for assignment modal
$stmtAvail = $db->prepare("
    SELECT id, name, email 
    FROM users 
    WHERE role = 'Student' 
      AND id NOT IN (SELECT student_id FROM instructor_students WHERE instructor_id = ?)
    ORDER BY name ASC
");
$stmtAvail->bind_param('i', $instructorId);
$stmtAvail->execute();
$availableStudents = $stmtAvail->get_result()->fetch_all(MYSQLI_ASSOC);

require_once '../includes/header.php';
?>

<div style="display: flex; justify-content: flex-end; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
    <button class="btn btn-secondary btn-sm" onclick="openModal('bulkAssignModal')">
        <i data-lucide="users" style="width: 15px; height: 15px;"></i> Enroll All Students
    </button>
    <button class="btn btn-primary btn-sm" onclick="openModal('assignModal')">
        <i data-lucide="user-plus" style="width: 15px; height: 15px;"></i> Assign Student
    </button>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $msgType ?> mb-4" style="border-radius: var(--radius-md);">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<!-- METRIC CARDS -->
<div class="metric-cards-grid mb-4">
    
    <div class="metric-card" style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem;">
        <div class="flex justify-between items-center mb-2">
            <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #94a3b8; letter-spacing: 0.05em;">Assigned Students</span>
            <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(59,130,246,0.15); color: #60a5fa; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="users" style="width: 18px; height: 18px;"></i>
            </div>
        </div>
        <div style="font-size: 1.85rem; font-weight: 800; color: var(--text-primary);"><?= $totalStudents ?></div>
        <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">In <?= count($sectionsList) ?> active section(s)</div>
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
        <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">Equal debits & credits</div>
    </div>

</div>

<!-- ROSTER & SIMULATION AUDIT SECTION -->
<div class="card" style="padding: 0; overflow: hidden; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px;">
    
    <!-- Controls Header -->
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary);">Student Activity &amp; Output Roster</h2>
            <p class="text-xs text-muted" style="margin-top: 2px;">Select a student to inspect their journals, ledgers, trial balance, and financial statements.</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <div style="position: relative;">
                <input type="text" id="rosterSearch" placeholder="Search student or simulation..." oninput="filterRoster()"
                       class="form-control" style="padding-left: 2rem; width: 240px; font-size: 0.85rem; border-radius: 99px;">
                <i data-lucide="search" style="width: 14px; height: 14px; position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
            </div>
            
            <select id="sectionFilter" onchange="filterRoster()" class="form-control" style="width: 140px; font-size: 0.85rem; border-radius: 99px;">
                <option value="">All Sections</option>
                <?php foreach($sectionsList as $sec): ?>
                <option value="<?= htmlspecialchars($sec) ?>"><?= htmlspecialchars($sec) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Roster Table Container -->
    <div class="table-container" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
        <table class="table" id="studentRosterTable" style="width: 100%; min-width: 860px;">
            <thead>
                <tr>
                    <th style="min-width: 220px;">Student Information</th>
                    <th style="min-width: 110px;" class="nowrap">Section</th>
                    <th style="min-width: 200px;">Company</th>
                    <th style="min-width: 110px;" class="nowrap text-center">Entries</th>
                    <th style="min-width: 130px;" class="nowrap text-center">Balance Status</th>
                    <th style="min-width: 120px;" class="nowrap text-center">Progress</th>
                    <th style="min-width: 170px;" class="nowrap text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($studentsData) === 0): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted" style="padding: 3rem 1rem;">
                        <i data-lucide="graduation-cap" style="width: 48px; height: 48px; margin-bottom: 0.75rem; opacity: 0.4;"></i>
                        <p style="font-size: 1rem; font-weight: 600; color: #94a3b8;">No students assigned yet</p>
                        <p style="font-size: 0.85rem; margin-top: 0.25rem;">Assign registered students to your section or click Enroll All Students to begin monitoring.</p>
                        <button class="btn btn-primary btn-sm mt-3" onclick="openModal('assignModal')">
                            <i data-lucide="user-plus" style="width: 14px; height: 14px;"></i> Assign First Student
                        </button>
                    </td>
                </tr>
                <?php endif; ?>

                <?php 
                foreach ($studentsData as $st): 
                    $stName = $st['student_name'];
                    $stEmail = $st['student_email'];
                    $stSection = $st['section'];
                    $stInitials = strtoupper(substr($stName, 0, 1) . (strpos($stName, ' ') !== false ? substr(explode(' ', $stName)[1], 0, 1) : ''));
                    $cos = $st['companies'];
                    $hasCos = count($cos) > 0;
                    
                    if (!$hasCos):
                ?>
                <tr class="roster-row" data-search="<?= strtolower(htmlspecialchars($stName . ' ' . $stEmail . ' ' . $stSection)) ?>" data-section="<?= htmlspecialchars($stSection) ?>">
                    <td>
                        <div class="flex items-center gap-3">
                            <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; color: #fff; flex-shrink: 0;">
                                <?= htmlspecialchars($stInitials) ?>
                            </div>
                            <div>
                                <div style="font-weight: 600; font-size: 0.9rem; color: var(--text-primary);"><?= htmlspecialchars($stName) ?></div>
                                <div style="font-size: 0.75rem; color: #94a3b8;"><?= htmlspecialchars($stEmail) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge" style="background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); font-size: 0.75rem; padding: 3px 8px; border-radius: 99px;">
                            <?= htmlspecialchars($stSection) ?>
                        </span>
                    </td>
                    <td>
                        <span style="font-size: 0.85rem; color: #64748b; font-style: italic;">No simulation created yet</span>
                    </td>
                    <td class="text-center">
                        <span class="text-muted" style="font-size: 0.85rem;">0</span>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-neutral" style="font-size: 0.7rem;">Empty</span>
                    </td>
                    <td class="text-center">
                        <span class="badge" style="background: rgba(100,116,139,0.15); color: #94a3b8; font-size: 0.75rem; padding: 2px 8px; border-radius: 99px;">
                            Not Started
                        </span>
                    </td>
                    <td class="text-right">
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this student from your roster?');">
                            <input type="hidden" name="action" value="unassign_student">
                            <input type="hidden" name="student_id" value="<?= $st['student_id'] ?>">
                            <button type="submit" class="icon-btn text-danger" title="Unassign Student">
                                <i data-lucide="user-minus" style="width: 15px; height: 15px;"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php 
                    else:
                        foreach ($cos as $coIdx => $co):
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
                <tr class="roster-row" data-search="<?= strtolower(htmlspecialchars($stName . ' ' . $stEmail . ' ' . $stSection . ' ' . $coName)) ?>" data-section="<?= htmlspecialchars($stSection) ?>">
                    <td>
                        <div class="flex items-center gap-3">
                            <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; color: #fff; flex-shrink: 0;">
                                <?= htmlspecialchars($stInitials) ?>
                            </div>
                            <div>
                                <div style="font-weight: 600; font-size: 0.9rem; color: var(--text-primary);"><?= htmlspecialchars($stName) ?></div>
                                <div style="font-size: 0.75rem; color: #94a3b8;"><?= htmlspecialchars($stEmail) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge" style="background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); font-size: 0.75rem; padding: 3px 8px; border-radius: 99px;">
                            <?= htmlspecialchars($stSection) ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight: 600; font-size: 0.875rem; color: #38bdf8;">
                            <?= htmlspecialchars($coName) ?>
                        </div>
                        <div style="font-size: 0.72rem; color: #64748b;">
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
                            <a href="<?= BASE_URL ?>pages/instructor_dashboard.php?action=start_view&company_id=<?= $co['id'] ?>&student_id=<?= $st['student_id'] ?>" 
                               class="btn btn-secondary btn-sm" style="padding: 4px 8px; font-size: 0.75rem;" title="Open Live View-Only Mode">
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

<!-- MODAL 1: ASSIGN SINGLE STUDENT -->
<div id="assignModal" class="modal-backdrop" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 999; align-items: center; justify-content: center;">
    <div class="modal card" style="background: var(--bg-secondary); border: 1px solid var(--border-color); width: 100%; max-width: 480px; padding: 1.75rem; border-radius: 16px;">
        <div class="modal-header flex justify-between items-center mb-4">
            <h3 style="font-size: 1.15rem; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="user-plus" style="width: 20px; height: 20px; color: #3b82f6;"></i>
                Assign Student to Section
            </h3>
            <button type="button" class="icon-btn" onclick="closeModal('assignModal')"><i data-lucide="x" style="width: 18px; height: 18px;"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="assign_student">
            
            <div class="form-group mb-3">
                <label class="form-label">Select Registered Student</label>
                <?php if (count($availableStudents) > 0): ?>
                <select name="student_id" class="form-control" required style="background: var(--bg-tertiary); color: #fff;">
                    <option value="">-- Choose a Student --</option>
                    <?php foreach ($availableStudents as $av): ?>
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
                <input type="text" name="section" class="form-control" placeholder="e.g. BSA 2-A, ACT101, Section 1" required value="BSA 1-A" style="background: var(--bg-tertiary); color: #fff;">
                <span class="text-xs text-muted">Used to organize and filter students by section in your dashboard.</span>
            </div>

            <div class="modal-footer flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" onclick="closeModal('assignModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" <?= count($availableStudents) === 0 ? 'disabled' : '' ?>>
                    Assign Student
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: BULK ENROLL ALL STUDENTS -->
<div id="bulkAssignModal" class="modal-backdrop" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 999; align-items: center; justify-content: center;">
    <div class="modal card" style="background: var(--bg-secondary); border: 1px solid var(--border-color); width: 100%; max-width: 480px; padding: 1.75rem; border-radius: 16px;">
        <div class="modal-header flex justify-between items-center mb-4">
            <h3 style="font-size: 1.15rem; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="users" style="width: 20px; height: 20px; color: #10b981;"></i>
                Bulk Enroll All Registered Students
            </h3>
            <button type="button" class="icon-btn" onclick="closeModal('bulkAssignModal')"><i data-lucide="x" style="width: 18px; height: 18px;"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="bulk_assign_all">
            
            <p style="font-size: 0.875rem; color: #cbd5e1; margin-bottom: 1rem; line-height: 1.5;">
                This will automatically add all registered student accounts into your instructor roster under the designated class section.
            </p>

            <div class="form-group mb-4">
                <label class="form-label">Assign to Section</label>
                <input type="text" name="section" class="form-control" placeholder="e.g. BSA 1-A" required value="BSA 1-A" style="background: var(--bg-tertiary); color: #fff;">
            </div>

            <div class="modal-footer flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" onclick="closeModal('bulkAssignModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #10b981; border-color: #10b981;">
                    Enroll All Students
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function filterRoster() {
    const search = document.getElementById('rosterSearch').value.toLowerCase().trim();
    const section = document.getElementById('sectionFilter').value;
    const rows = document.querySelectorAll('#studentRosterTable tbody .roster-row');

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
    if (el) {
        el.style.display = 'flex';
    }
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) {
        el.style.display = 'none';
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
