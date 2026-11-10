<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/admin_auth.php';
require_once '../includes/csrf.php';
require_once '../includes/header.php';

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify()) {
        $error = "Security validation failed (CSRF token expired or invalid).";
    } else {
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

$instructors = $db->query("SELECT id, name, email FROM users WHERE role = 'Instructor' AND (status = 'approved' OR status IS NULL) ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$unassignedStudents = $db->query("
    SELECT id, name, email FROM users
    WHERE role = 'Student' AND id NOT IN (SELECT student_id FROM instructor_students)
    ORDER BY name ASC
")->fetch_all(MYSQLI_ASSOC);
$allStudents = $db->query("SELECT id, name, email FROM users WHERE role = 'Student' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
?>


<style>
.adm-container {
    max-width: 1360px;
    margin: 0 auto;
    padding: 1.5rem 1.75rem 3rem;
    color: #0f172a;
}
.adm-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid #e2e8f0;
}
.adm-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.02em;
    margin: 0 0 0.25rem 0;
}
.adm-subtitle {
    font-size: 0.875rem;
    color: #64748b;
    margin: 0;
}
.adm-btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    background: #166534;
    color: #ffffff !important;
    font-size: 0.84rem;
    font-weight: 600;
    padding: 0.5rem 0.95rem;
    border-radius: 8px;
    text-decoration: none;
    border: 1px solid #166534;
    cursor: pointer;
    transition: background 0.15s ease;
}
.adm-btn-primary:hover {
    background: #14532d;
}
.adm-filter-bar {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.85rem 1rem;
    margin-bottom: 1.25rem;
    display: flex;
    gap: 0.75rem;
    align-items: center;
}
.adm-search-wrap {
    position: relative;
    max-width: 420px;
    width: 100%;
}
.adm-search-input {
    width: 100%;
    padding: 0.5rem 0.85rem 0.5rem 2.2rem;
    font-size: 0.85rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #ffffff;
    color: #0f172a;
    outline: none;
}
.adm-search-input:focus {
    border-color: #94a3b8;
}
.adm-table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 1.5rem;
}
.adm-clean-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.84rem;
    text-align: left;
}
.adm-clean-table th {
    background: #fafafa;
    color: #64748b;
    font-weight: 600;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
}
.adm-clean-table td {
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}
.adm-clean-table tr:last-child td {
    border-bottom: none;
}
.adm-clean-table tr:hover td {
    background: #f8fafc;
}
.adm-badge-tag {
    display: inline-flex;
    align-items: center;
    font-size: 0.72rem;
    font-weight: 500;
    padding: 2px 7px;
    border-radius: 6px;
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
}
.adm-icon-btn {
    background: transparent;
    border: none;
    color: #64748b;
    cursor: pointer;
    padding: 4px;
    border-radius: 6px;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.adm-icon-btn:hover {
    color: #0f172a;
    background: #f1f5f9;
}
</style>

<div class="adm-container">

    <div class="adm-header">
        <div>
            <h1 class="adm-title">Instructor &amp; Student Cohorts</h1>
            <p class="adm-subtitle">Supervision links between assigned faculty instructors and student simulation companies.</p>
        </div>
        <div>
            <button class="adm-btn-primary" onclick="openAssignModal()">
                <i data-lucide="user-plus" style="width:14px;height:14px;"></i> New Assignment
            </button>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div style="background: #f0fdf4; color: #166534; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem; border: 1px solid #bbf7d0; font-size: 0.85rem;">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div style="background: #fef2f2; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem; border: 1px solid #fecaca; font-size: 0.85rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- FILTER BAR -->
    <div class="adm-filter-bar">
        <div class="adm-search-wrap">
            <i data-lucide="search" style="width:14px;height:14px; position:absolute; left:10px; top:50%; transform:translateY(-50%); color: #94a3b8;"></i>
            <input type="text" id="assignSearch" class="adm-search-input" placeholder="Search by instructor or student name..." onkeyup="filterAssignments()">
        </div>
    </div>

    <!-- TABLE CARD -->
    <div class="adm-table-card">
        <table class="adm-clean-table">
            <thead>
                <tr>
                    <th>Faculty Instructor</th>
                    <th>Assigned Student</th>
                    <th>Section / Cohort</th>
                    <th>Assigned Date</th>
                    <th style="text-align: center; width: 80px;">Actions</th>
                </tr>
            </thead>
            <tbody id="assignTableBody">
                <?php foreach ($assignments as $a): ?>
                <tr data-search="<?= htmlspecialchars(strtolower($a['instructor_name'] . ' ' . $a['student_name'])) ?>">
                    <td>
                        <div style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($a['instructor_name']) ?></div>
                        <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($a['instructor_email']) ?></div>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($a['student_name']) ?></div>
                        <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($a['student_email']) ?></div>
                    </td>
                    <td><span class="adm-badge-tag"><?= htmlspecialchars($a['section']) ?></span></td>
                    <td style="font-size: 0.78rem; color: #64748b;"><?= date('M j, Y', strtotime($a['created_at'])) ?></td>
                    <td style="text-align: center;">
                        <div style="display: flex; justify-content: center; align-items: center; gap: 4px;">
                            <button type="button" class="adm-icon-btn" title="Reassign Section"
                                onclick='openReassignModal(<?= (int)$a['id'] ?>, <?= (int)$a['instructor_id'] ?>, <?= json_encode($a['section']) ?>, <?= json_encode($a['student_name']) ?>)'>
                                <i data-lucide="repeat" style="width:14px;height:14px;"></i>
                            </button>
                            <form method="POST" onsubmit="return confirm('Remove this student assignment?');" style="display:inline;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="unassign">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button type="submit" class="adm-icon-btn" style="color: #94a3b8;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'" title="Remove Assignment">
                                    <i data-lucide="user-minus" style="width:14px;height:14px;"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($assignments) === 0): ?>
                <tr><td colspan="5" style="text-align: center; color: #94a3b8; padding: 2.5rem;">No assignments registered yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="noResultsRowAssign" class="hidden" style="text-align: center; color: #94a3b8; padding: 2rem;">No assignments match your search.</div>
    </div>

    <?php if (count($unassignedStudents) > 0): ?>
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1.25rem; margin-bottom: 2rem;">
        <div style="font-size: 0.85rem; font-weight: 600; color: #0f172a; margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.4rem;">
            <i data-lucide="alert-circle" style="width:15px;height:15px; color:#d97706;"></i>
            Unassigned Students (<?= count($unassignedStudents) ?>)
        </div>
        <div style="font-size: 0.78rem; color: #64748b; margin-bottom: 0.75rem;">These students are currently without an assigned faculty evaluator.</div>
        <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
            <?php foreach ($unassignedStudents as $s): ?>
                <span class="adm-badge-tag"><?= htmlspecialchars($s['name']) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- New Assignment Modal -->
<div id="assignModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 450px;">
        <div class="modal-header">
            <h2>New Assignment</h2>
            <button class="icon-btn" onclick="closeAssignModal()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="assign-form" method="POST">
                <?= csrf_input() ?>
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
                <?= csrf_input() ?>
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