<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/admin_auth.php';
require_once '../includes/header.php';

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'unassign') {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM instructor_students WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        log_activity($db, $_SESSION['user_id'], 'Delete', 'Assignment Management', "Removed instructor-student assignment ID: $id");
        $success = "Assignment removed.";

    } elseif ($_POST['action'] === 'reassign') {
        $id = (int)$_POST['id'];
        $newInstructorId = (int)$_POST['instructor_id'];
        $newSection = trim($_POST['section'] ?? '');
        if ($newSection === '') $newSection = 'Section 1';
        try {
            $stmt = $db->prepare("UPDATE instructor_students SET instructor_id = ?, section = ? WHERE id = ?");
            $stmt->bind_param('isi', $newInstructorId, $newSection, $id);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Update', 'Assignment Management', "Reassigned assignment ID: $id");
            $success = "Assignment updated successfully.";
        } catch (Exception $e) {
            $error = "This student is already assigned to that instructor.";
        }

    } elseif ($_POST['action'] === 'assign_new') {
        $instructorId = (int)$_POST['instructor_id'];
        $studentId = (int)$_POST['student_id'];
        $section = trim($_POST['section'] ?? '');
        if ($section === '') $section = 'Section 1';
        try {
            $stmt = $db->prepare("INSERT INTO instructor_students (instructor_id, student_id, section) VALUES (?, ?, ?)");
            $stmt->bind_param('iis', $instructorId, $studentId, $section);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Create', 'Assignment Management', "Assigned student ID $studentId to instructor ID $instructorId");
            $success = "Student assigned successfully.";
        } catch (Exception $e) {
            $error = "This student is already assigned to that instructor.";
        }
    }
}

$assignments = $db->query("
    SELECT ins.id, ins.section, ins.created_at,
           i.id as instructor_id, i.name as instructor_name, i.email as instructor_email,
           s.id as student_id, s.name as student_name, s.email as student_email
    FROM instructor_students ins
    JOIN users i ON ins.instructor_id = i.id
    JOIN users s ON ins.student_id = s.id
    ORDER BY i.name ASC, s.name ASC
")->fetch_all(MYSQLI_ASSOC);

$instructors = $db->query("SELECT id, name, email FROM users WHERE role = 'Instructor' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$unassignedStudents = $db->query("
    SELECT id, name, email FROM users
    WHERE role = 'Student' AND id NOT IN (SELECT student_id FROM instructor_students)
    ORDER BY name ASC
")->fetch_all(MYSQLI_ASSOC);
$allStudents = $db->query("SELECT id, name, email FROM users WHERE role = 'Student' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
?>


<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Instructor-Student Assignments</h1>
        <p class="page-subtitle">See and manage which students are assigned to which instructors, system-wide.</p>
    </div>
    <button class="btn btn-primary" onclick="openAssignModal()">
        <i data-lucide="user-plus" style="width:15px;height:15px;"></i> New Assignment
    </button>
</div>

<?php if (isset($success)): ?>
    <div style="background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= htmlspecialchars($success) ?>
    </div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="card" style="padding: 1rem; margin-bottom: 1rem;">
    <div style="position: relative;">
        <i data-lucide="search" style="width:15px;height:15px; position:absolute; left:10px; top:50%; transform:translateY(-50%); color: var(--text-muted);"></i>
        <input type="text" id="assignSearch" class="form-control" style="padding-left: 32px; max-width: 400px;" placeholder="Search by instructor or student..." onkeyup="filterAssignments()">
    </div>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Instructor</th>
                    <th>Student</th>
                    <th>Section</th>
                    <th>Assigned On</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody id="assignTableBody">
                <?php foreach ($assignments as $a): ?>
                <tr data-search="<?= htmlspecialchars(strtolower($a['instructor_name'] . ' ' . $a['student_name'])) ?>">
                    <td>
                        <div style="font-weight: 500;"><?= htmlspecialchars($a['instructor_name']) ?></div>
                        <div class="text-muted" style="font-size: 0.78rem;"><?= htmlspecialchars($a['instructor_email']) ?></div>
                    </td>
                    <td>
                        <div style="font-weight: 500;"><?= htmlspecialchars($a['student_name']) ?></div>
                        <div class="text-muted" style="font-size: 0.78rem;"><?= htmlspecialchars($a['student_email']) ?></div>
                    </td>
                    <td><span class="badge badge-neutral"><?= htmlspecialchars($a['section']) ?></span></td>
                    <td style="font-size: 0.8125rem; color: var(--text-muted);"><?= date('M j, Y', strtotime($a['created_at'])) ?></td>
                    <td>
                        <div class="flex justify-center gap-2">
                            <button type="button" class="icon-btn" title="Reassign"
                                onclick='openReassignModal(<?= (int)$a['id'] ?>, <?= (int)$a['instructor_id'] ?>, <?= json_encode($a['section']) ?>, <?= json_encode($a['student_name']) ?>)'>
                                <i data-lucide="repeat" style="width:16px;height:16px;"></i>
                            </button>
                            <form method="POST" onsubmit="return confirm('Remove this assignment? The instructor will no longer be able to monitor this student.');" style="display:inline;">
                                <input type="hidden" name="action" value="unassign">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button type="submit" class="icon-btn text-danger" title="Unassign">
                                    <i data-lucide="user-minus" style="width:16px;height:16px;"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($assignments) === 0): ?>
                <tr><td colspan="5" class="text-center text-muted" style="padding: 2rem;">No assignments yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="noResultsRowAssign" class="text-center text-muted hidden" style="padding: 2rem;">No assignments match your search.</div>
    </div>
</div>

<?php if (count($unassignedStudents) > 0): ?>
<div class="card" style="padding: 1.25rem; margin-top: 1.5rem;">
    <h3 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 0.75rem; display:flex; align-items:center; gap:0.5rem;">
        <i data-lucide="alert-circle" style="width:16px;height:16px; color:#f59e0b;"></i>
        Unassigned Students (<?= count($unassignedStudents) ?>)
    </h3>
    <div class="text-muted" style="font-size: 0.85rem; margin-bottom: 0.75rem;">These students are not yet monitored by any instructor.</div>
    <div class="flex flex-col gap-2" style="align-items: flex-start;">
        <?php foreach ($unassignedStudents as $s): ?>
            <span class="badge badge-neutral"><?= htmlspecialchars($s['name']) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- New Assignment Modal -->
<div id="assignModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 450px;">
        <div class="modal-header">
            <h2>New Assignment</h2>
            <button class="icon-btn" onclick="closeAssignModal()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="assign-form" method="POST">
                <input type="hidden" name="action" value="assign_new">
                <div class="form-group">
                    <label class="form-label">Instructor</label>
                    <select name="instructor_id" class="form-control" required>
                        <option value="">Select instructor...</option>
                        <?php foreach ($instructors as $i): ?>
                        <option value="<?= $i['id'] ?>"><?= htmlspecialchars($i['name']) ?> (<?= htmlspecialchars($i['email']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Student</label>
                    <select name="student_id" class="form-control" required>
                        <option value="">Select student...</option>
                        <?php foreach ($allStudents as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['email']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Section</label>
                    <input type="text" name="section" class="form-control" value="Section 1">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeAssignModal()">Cancel</button>
            <button type="submit" form="assign-form" class="btn btn-primary">Assign</button>
        </div>
    </div>
</div>

<!-- Reassign Modal -->
<div id="reassignModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 450px;">
        <div class="modal-header">
            <h2>Reassign Student</h2>
            <button class="icon-btn" onclick="closeReassignModal()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body">
            <div class="text-muted" style="font-size: 0.85rem; margin-bottom: 1rem;">Student: <strong id="reassignStudentName"></strong></div>
            <form id="reassign-form" method="POST">
                <input type="hidden" name="action" value="reassign">
                <input type="hidden" name="id" id="reassignId">
                <div class="form-group">
                    <label class="form-label">Instructor</label>
                    <select name="instructor_id" id="reassignInstructorId" class="form-control" required>
                        <?php foreach ($instructors as $i): ?>
                        <option value="<?= $i['id'] ?>"><?= htmlspecialchars($i['name']) ?> (<?= htmlspecialchars($i['email']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Section</label>
                    <input type="text" name="section" id="reassignSection" class="form-control">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeReassignModal()">Cancel</button>
            <button type="submit" form="reassign-form" class="btn btn-primary">Save</button>
        </div>
    </div>
</div>

<script>
function openAssignModal() {
    const modal = document.getElementById('assignModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}
function closeAssignModal() {
    const modal = document.getElementById('assignModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}
document.getElementById('assignModal').addEventListener('click', function(e) {
    if (e.target === this) closeAssignModal();
});

function openReassignModal(id, instructorId, section, studentName) {
    document.getElementById('reassignId').value = id;
    document.getElementById('reassignInstructorId').value = instructorId;
    document.getElementById('reassignSection').value = section;
    document.getElementById('reassignStudentName').textContent = studentName;
    const modal = document.getElementById('reassignModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}
function closeReassignModal() {
    const modal = document.getElementById('reassignModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}
document.getElementById('reassignModal').addEventListener('click', function(e) {
    if (e.target === this) closeReassignModal();
});

function filterAssignments() {
    const q = document.getElementById('assignSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#assignTableBody tr[data-search]');
    let visibleCount = 0;
    rows.forEach(row => {
        const show = row.getAttribute('data-search').includes(q);
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
    document.getElementById('noResultsRowAssign').classList.toggle('hidden', visibleCount !== 0);
}
</script>

<?php require_once '../includes/footer.php'; ?>