<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';

$db = get_db();

// ------------------------------------------------------------------
// Auto-migration: create the employees table if it doesn't exist yet.
// Follows the same defensive pattern used elsewhere in the app.
// ------------------------------------------------------------------
try {
    $db->query("
        CREATE TABLE IF NOT EXISTS employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            code VARCHAR(20) NULL,
            name VARCHAR(150) NOT NULL,
            position VARCHAR(150) NULL,
            department VARCHAR(150) NULL,
            email VARCHAR(150) NULL,
            phone VARCHAR(50) NULL,
            address VARCHAR(255) NULL,
            date_hired DATE NULL,
            rate DECIMAL(15,2) NOT NULL DEFAULT 0,
            pay_frequency VARCHAR(50) NULL,
            status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employees_company (company_id)
        )
    ");
} catch (Exception $e) {}

// Defensive: add the `code` column for installs where the table already
// existed before this feature was added.
try { $db->query("ALTER TABLE employees ADD COLUMN code VARCHAR(20) NULL AFTER company_id"); } catch (Exception $e) {}

$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    require_once '../includes/header.php';
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to manage employees.</div>';
    require_once '../includes/footer.php';
    exit;
}

// Generate an Employee ID (e.g. EMP-0001) for any existing rows that
// don't have one yet, in creation order, so nothing is left blank.
try {
    $stmtBackfill = $db->prepare("SELECT id FROM employees WHERE company_id = ? AND (code IS NULL OR code = '') ORDER BY id ASC");
    $stmtBackfill->bind_param('i', $company_id);
    $stmtBackfill->execute();
    $needsCode = $stmtBackfill->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($needsCode) > 0) {
        $stmtMax = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) as max_num FROM employees WHERE company_id = ? AND code LIKE 'EMP-%'");
        $stmtMax->bind_param('i', $company_id);
        $stmtMax->execute();
        $nextNum = (int)($stmtMax->get_result()->fetch_assoc()['max_num'] ?? 0) + 1;
        $stmtSetCode = $db->prepare("UPDATE employees SET code = ? WHERE id = ?");
        foreach ($needsCode as $row) {
            $newCode = sprintf('EMP-%04d', $nextNum);
            $stmtSetCode->bind_param('si', $newCode, $row['id']);
            $stmtSetCode->execute();
            $nextNum++;
        }
    }
} catch (Exception $e) {}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add' || $action === 'edit') {
        $id = $_POST['id'] ?? null;
        $name = trim($_POST['name'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $date_hired = $_POST['date_hired'] !== '' ? $_POST['date_hired'] : null;
        $rate = $_POST['rate'] !== '' ? (float)$_POST['rate'] : 0;
        $pay_frequency = $_POST['pay_frequency'] ?? 'Monthly';
        $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        $notes = trim($_POST['notes'] ?? '');

        if ($name === '') {
            $error = "Employee name is required.";
        } else if ($action === 'add') {
            // Generate the next sequential Employee ID for this company.
            $stmtMax = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) as max_num FROM employees WHERE company_id = ? AND code LIKE 'EMP-%'");
            $stmtMax->bind_param('i', $company_id);
            $stmtMax->execute();
            $nextNum = (int)($stmtMax->get_result()->fetch_assoc()['max_num'] ?? 0) + 1;
            $employee_code = sprintf('EMP-%04d', $nextNum);

            $stmt = $db->prepare("INSERT INTO employees (company_id, code, name, position, department, email, phone, address, date_hired, rate, pay_frequency, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssssssdsss', $company_id, $employee_code, $name, $position, $department, $email, $phone, $address, $date_hired, $rate, $pay_frequency, $status, $notes);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Create', 'Employees', "Added employee: $name ($employee_code)");
            $success = "Employee added successfully.";
            header("Location: employees.php");
            exit;
        } else if ($action === 'edit' && $id) {
            $stmt = $db->prepare("UPDATE employees SET name=?, position=?, department=?, email=?, phone=?, address=?, date_hired=?, rate=?, pay_frequency=?, status=?, notes=? WHERE id=? AND company_id=?");
            $stmt->bind_param('sssssssdsssii', $name, $position, $department, $email, $phone, $address, $date_hired, $rate, $pay_frequency, $status, $notes, $id, $company_id);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Update', 'Employees', "Updated employee: $name");
            $success = "Employee updated successfully.";
            header("Location: employees.php");
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];

        $stmt = $db->prepare("SELECT name FROM employees WHERE id = ? AND company_id = ?");
        $stmt->bind_param('ii', $id, $company_id);
        $stmt->execute();
        $emp = $stmt->get_result()->fetch_assoc();

        if ($emp) {
            $stmtDel = $db->prepare("DELETE FROM employees WHERE id = ? AND company_id = ?");
            $stmtDel->bind_param('ii', $id, $company_id);
            if ($stmtDel->execute()) {
                log_activity($db, $_SESSION['user_id'], 'Delete', 'Employees', "Deleted employee: {$emp['name']}");
            }
        }
        header("Location: employees.php");
        exit;
    }
}

require_once '../includes/header.php';

// Search Filter
$search = $_GET['search'] ?? '';
$query = "SELECT * FROM employees WHERE company_id = ?";
$params = [$company_id];
$types = "i";

if ($search) {
    $query .= " AND (name LIKE ? OR position LIKE ? OR department LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like, $like);
    $types .= "sssss";
}
$query .= " ORDER BY name ASC";

$stmt = $db->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Employee List</h1>
        <p class="page-subtitle">Manage employee records and employment details.</p>
    </div>
    <div class="flex gap-2">
        <button class="btn btn-primary" onclick="openModal()">
            <i data-lucide="plus" style="width:15px;height:15px;"></i> Add Employee
        </button>
    </div>
</div>

<?php if (isset($error)): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="flex items-center gap-3" style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-color);">
        <form method="GET" style="position: relative; flex: 1; max-width: 320px; display: flex;">
            <i data-lucide="search" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); width:14px; height:14px;"></i>
            <input type="text" name="search" class="form-control" placeholder="Search by name, position, department..." value="<?= htmlspecialchars($search) ?>" style="padding-left: 2.25rem;">
            <button type="submit" style="display:none;"></button>
        </form>
        <span class="text-sm text-muted"><?= count($employees) ?> employees</span>
    </div>

    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Employee Name</th>
                    <th>Position</th>
                    <th>Department</th>
                    <th>Email / Phone</th>
                    <th>Date Hired</th>
                    <th class="text-right">Rate</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($employees) === 0): ?>
                <tr><td colspan="9" class="text-center text-secondary" style="padding: 2rem;">No employees found.</td></tr>
                <?php else: foreach($employees as $e): ?>
                <tr style="color: #000;">
                    <td style="font-family: monospace; font-weight: 600; font-size: 0.8rem; color: var(--primary-color);"><?= htmlspecialchars($e['code'] ?: '—') ?></td>
                    <td style="font-weight: 600;"><?= htmlspecialchars($e['name']) ?></td>
                    <td style="font-size: 0.875rem;"><?= htmlspecialchars($e['position'] ?: '—') ?></td>
                    <td style="font-size: 0.875rem;"><?= htmlspecialchars($e['department'] ?: '—') ?></td>
                    <td style="font-size: 0.8125rem;">
                        <?= htmlspecialchars($e['email'] ?: '—') ?><br>
                        <span style="color: var(--text-muted);"><?= htmlspecialchars($e['phone'] ?: '') ?></span>
                    </td>
                    <td style="font-size: 0.8125rem;"><?= $e['date_hired'] ? date('M d, Y', strtotime($e['date_hired'])) : '—' ?></td>
                    <td class="text-right" style="font-weight: 700; color: var(--primary-color);">₱<?= number_format($e['rate'], 2) ?><span style="font-weight:400; color: var(--text-muted); font-size:0.75rem;"> / <?= htmlspecialchars($e['pay_frequency'] ?: '—') ?></span></td>
                    <td class="text-center">
                        <span class="badge <?= $e['status'] === 'Active' ? 'badge-success' : 'badge-neutral' ?>"><?= htmlspecialchars($e['status']) ?></span>
                    </td>
                    <td>
                        <div class="flex justify-center gap-2">
                            <button class="icon-btn" onclick='openModal(<?= json_encode($e) ?>)'><i data-lucide="edit-2" style="width:16px;height:16px;"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this employee?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                <button type="submit" class="icon-btn text-danger"><i data-lucide="trash-2" style="width:16px;height:16px;"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div id="empModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 620px;">
        <div class="modal-header">
            <div>
                <h2 style="font-size: 1.125rem;" id="modalTitle">New Employee</h2>
                <p class="text-xs text-muted mt-1" id="modalDesc">Fill in the employee's details.</p>
            </div>
            <button class="icon-btn" onclick="closeModal()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="emp-form" method="POST">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="empId" value="">

                <div class="form-group" id="empCodeGroup" style="display:none;">
                    <label class="form-label">Employee ID</label>
                    <input type="text" id="empCode" class="form-control" readonly disabled style="background: var(--bg-secondary); font-family: monospace; font-weight: 600; color: var(--primary-color);">
                </div>

                <div class="flex gap-4">
                    <div class="form-group" style="flex: 2;">
                        <label class="form-label">Employee Name <span class="required">*</span></label>
                        <input type="text" name="name" id="empName" class="form-control" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Status</label>
                        <select name="status" id="empStatus" class="form-control">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="flex gap-4">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Position</label>
                        <input type="text" name="position" id="empPosition" class="form-control">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" id="empDepartment" class="form-control">
                    </div>
                </div>

                <div class="flex gap-4">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="empEmail" class="form-control">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" id="empPhone" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" id="empAddress" class="form-control">
                </div>

                <div class="flex gap-4">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Date Hired</label>
                        <input type="date" name="date_hired" id="empDateHired" class="form-control">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Rate</label>
                        <input type="number" step="0.01" name="rate" id="empRate" class="form-control" value="0">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Pay Frequency</label>
                        <select name="pay_frequency" id="empPayFreq" class="form-control">
                            <option value="Daily">Daily</option>
                            <option value="Weekly">Weekly</option>
                            <option value="Bi-Weekly">Bi-Weekly</option>
                            <option value="Semi-Monthly">Semi-Monthly</option>
                            <option value="Monthly">Monthly</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" id="empNotes" class="form-control" rows="2"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="emp-form" class="btn btn-primary">Save Employee</button>
        </div>
    </div>
</div>

<script>
function openModal(e = null) {
    const modal = document.getElementById('empModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    if (e) {
        document.getElementById('modalTitle').innerText = 'Edit Employee';
        document.getElementById('modalDesc').innerText = 'Update employee details below.';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('empId').value = e.id;
        document.getElementById('empCodeGroup').style.display = 'block';
        document.getElementById('empCode').value = e.code || '—';
        document.getElementById('empName').value = e.name;
        document.getElementById('empStatus').value = e.status;
        document.getElementById('empPosition').value = e.position || '';
        document.getElementById('empDepartment').value = e.department || '';
        document.getElementById('empEmail').value = e.email || '';
        document.getElementById('empPhone').value = e.phone || '';
        document.getElementById('empAddress').value = e.address || '';
        document.getElementById('empDateHired').value = e.date_hired || '';
        document.getElementById('empRate').value = e.rate;
        document.getElementById('empPayFreq').value = e.pay_frequency || 'Monthly';
        document.getElementById('empNotes').value = e.notes || '';
    } else {
        document.getElementById('modalTitle').innerText = 'New Employee';
        document.getElementById('modalDesc').innerText = "Fill in the employee's details.";
        document.getElementById('formAction').value = 'add';
        document.getElementById('empId').value = '';
        document.getElementById('empCodeGroup').style.display = 'none';
        document.getElementById('emp-form').reset();
        document.getElementById('empRate').value = '0';
        document.getElementById('empStatus').value = 'Active';
        document.getElementById('empPayFreq').value = 'Monthly';
    }
}
function closeModal() {
    const modal = document.getElementById('empModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}
document.getElementById('empModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php require_once '../includes/footer.php'; ?>