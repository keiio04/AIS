<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/admin_auth.php';
require_once '../includes/csrf.php';
require_once '../includes/mailer.php';
require_once '../includes/header.php';

$db = get_db();

function generate_random_password($length = 10) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

$generatedPassword = null;
$error = null;
$success = null;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify()) {
        $error = "Security validation failed (CSRF token expired or invalid). Please try again.";
    } else {
        $action = $_POST['action'];

        if ($action === 'add_user') {
            $name               = trim($_POST['name'] ?? '');
            $email              = trim($_POST['email'] ?? '');
            $role               = $_POST['role'] ?? 'Student';
            $customPassword     = trim($_POST['password'] ?? '');
            $facultyId          = trim($_POST['faculty_id'] ?? '');
            $department         = trim($_POST['department'] ?? '');
            $verificationNotes  = trim($_POST['verification_notes'] ?? '');
            $status             = $_POST['status'] ?? 'approved';

            if (!in_array($role, ['Admin', 'Instructor', 'Student'])) {
                $role = 'Student';
            }
            if (!in_array($status, ['approved', 'pending', 'rejected'])) {
                $status = 'approved';
            }

            if ($name === '' || $email === '') {
                $error = "Name and email are required.";
            } else {
                $plainPassword = $customPassword !== '' ? $customPassword : generate_random_password();
                $hashed = password_hash($plainPassword, PASSWORD_BCRYPT);
                $verifiedBy = ($role === 'Instructor' && $status === 'approved') ? (int)$_SESSION['user_id'] : null;
                $verifiedAt = ($role === 'Instructor' && $status === 'approved') ? date('Y-m-d H:i:s') : null;

                try {
                    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, status, faculty_id, department, verification_notes, verified_by, verified_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('ssssssssis', $name, $email, $hashed, $role, $status, $facultyId, $department, $verificationNotes, $verifiedBy, $verifiedAt);
                    $stmt->execute();
                    log_activity($db, $_SESSION['user_id'], 'Create', 'User Management', "Created user: $email ($role, Status: $status)");
                    $success = "User added successfully.";
                    if ($customPassword === '') {
                        $generatedPassword = $plainPassword;
                    }

                    // Auto-send credentials email to the new user
                    $mailResult = send_account_credentials_email($email, $name, $plainPassword, $role, $status);
                    if ($mailResult['success']) {
                        $success .= " Login credentials have been emailed to {$email}.";
                    } else {
                        $success .= " ⚠️ Email could not be sent ({$mailResult['error']}). Please share the credentials manually.";
                    }
                } catch (Exception $e) {
                    $error = "Error adding user: " . $e->getMessage();
                }
            }

        } elseif ($action === 'edit_user') {
            $id                 = (int)$_POST['id'];
            $name               = trim($_POST['name'] ?? '');
            $email              = trim($_POST['email'] ?? '');
            $role               = $_POST['role'] ?? 'Student';
            $status             = $_POST['status'] ?? 'approved';
            $facultyId          = trim($_POST['faculty_id'] ?? '');
            $department         = trim($_POST['department'] ?? '');
            $verificationNotes  = trim($_POST['verification_notes'] ?? '');
            $newPassword        = trim($_POST['password'] ?? '');

            if (!in_array($role, ['Admin', 'Instructor', 'Student'])) {
                $role = 'Student';
            }
            if (!in_array($status, ['approved', 'pending', 'rejected'])) {
                $status = 'approved';
            }

            if ($name === '' || $email === '') {
                $error = "Name and email are required.";
            } else {
                try {
                    $verifiedBy = ($role === 'Instructor' && $status === 'approved') ? (int)$_SESSION['user_id'] : null;
                    $verifiedAt = ($role === 'Instructor' && $status === 'approved') ? date('Y-m-d H:i:s') : null;

                    if ($newPassword !== '') {
                        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
                        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, role = ?, status = ?, faculty_id = ?, department = ?, verification_notes = ?, verified_by = ?, verified_at = ?, password = ? WHERE id = ?");
                        $stmt->bind_param('sssssssisssi', $name, $email, $role, $status, $facultyId, $department, $verificationNotes, $verifiedBy, $verifiedAt, $hashed, $id);
                    } else {
                        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, role = ?, status = ?, faculty_id = ?, department = ?, verification_notes = ?, verified_by = ?, verified_at = ? WHERE id = ?");
                        $stmt->bind_param('sssssssisii', $name, $email, $role, $status, $facultyId, $department, $verificationNotes, $verifiedBy, $verifiedAt, $id);
                    }
                    $stmt->execute();
                    log_activity($db, $_SESSION['user_id'], 'Update', 'User Management', "Updated user: $email ($role, Status: $status)");
                    $success = "User updated successfully.";
                } catch (Exception $e) {
                    $error = "Error updating user: " . $e->getMessage();
                }
            }

        } elseif ($action === 'approve_faculty') {
            $id                 = (int)$_POST['id'];
            $facultyId          = trim($_POST['faculty_id'] ?? '');
            $department         = trim($_POST['department'] ?? '');
            $verificationNotes  = trim($_POST['verification_notes'] ?? 'Verified legitimate faculty member');
            $verifiedBy         = (int)$_SESSION['user_id'];
            $verifiedAt         = date('Y-m-d H:i:s');

            try {
                // Fetch user info before updating for the email
                $stmtUser = $db->prepare("SELECT name, email FROM users WHERE id = ?");
                $stmtUser->bind_param('i', $id);
                $stmtUser->execute();
                $approvedUser = $stmtUser->get_result()->fetch_assoc();

                $stmt = $db->prepare("UPDATE users SET role = 'Instructor', status = 'approved', faculty_id = ?, department = ?, verification_notes = ?, verified_by = ?, verified_at = ? WHERE id = ?");
                $stmt->bind_param('sssisi', $facultyId, $department, $verificationNotes, $verifiedBy, $verifiedAt, $id);
                $stmt->execute();
                log_activity($db, $_SESSION['user_id'], 'Approve', 'Faculty Verification', "Approved faculty status for user ID: $id ($facultyId - $department)");
                $success = "Instructor account approved as verified faculty member.";

                // Send approval notification email
                if ($approvedUser) {
                    $mailResult = send_faculty_approved_email($approvedUser['email'], $approvedUser['name'], $department);
                    if ($mailResult['success']) {
                        $success .= " Approval notification emailed to {$approvedUser['email']}.";
                    } else {
                        $success .= " ⚠️ Notification email could not be sent ({$mailResult['error']}).";
                    }
                }
            } catch (Exception $e) {
                $error = "Error approving instructor: " . $e->getMessage();
            }

        } elseif ($action === 'reject_faculty') {
            $id     = (int)$_POST['id'];
            $reason = trim($_POST['reason'] ?? 'Faculty credentials could not be verified');

            try {
                $stmt = $db->prepare("UPDATE users SET status = 'rejected', verification_notes = ? WHERE id = ?");
                $stmt->bind_param('si', $reason, $id);
                $stmt->execute();
                log_activity($db, $_SESSION['user_id'], 'Reject', 'Faculty Verification', "Rejected instructor account ID: $id (Reason: $reason)");
                $success = "Instructor account marked as rejected.";
            } catch (Exception $e) {
                $error = "Error updating user: " . $e->getMessage();
            }

        } elseif ($action === 'delete_user') {
            $id = (int)$_POST['id'];
            if ($id !== $_SESSION['user_id']) { // prevent self-deletion
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                log_activity($db, $_SESSION['user_id'], 'Delete', 'User Management', "Deleted user ID: $id");
                $success = "User deleted successfully.";
            } else {
                $error = "You cannot delete your own account.";
            }
        }
    }
}

// Fetch all users with status & faculty verification details
$query = "
    SELECT u.id, u.name, u.email, u.role, u.status, u.faculty_id, u.department, 
           u.verification_notes, u.verified_by, u.verified_at, u.created_at,
           v.name as verifier_name
    FROM users u
    LEFT JOIN users v ON u.verified_by = v.id
    ORDER BY (CASE WHEN u.role = 'Instructor' AND u.status = 'pending' THEN 0 ELSE 1 END), u.name ASC
";
$res = $db->query($query);
$users = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$roleBadge = [
    'Admin' => 'primary',
    'Instructor' => 'info',
    'Student' => 'neutral',
];

$pendingCount = 0;
foreach ($users as $u) {
    if ($u['role'] === 'Instructor' && ($u['status'] ?? 'approved') === 'pending') {
        $pendingCount++;
    }
}
?>

<div class="page-header flex justify-between items-center" style="margin-bottom: 1.5rem;">
    <div class="page-header-text">
        <h1 class="page-title">User Management &amp; Role Security</h1>
        <p class="page-subtitle">Manage system users, student accounts, and verify faculty instructor credentials.</p>
    </div>
    <div class="flex items-center gap-2">
        <button class="btn btn-primary" onclick="openAddModal()">
            <i data-lucide="user-plus" style="width:15px;height:15px;"></i> Add User / Faculty
        </button>
    </div>
</div>

<?php if ($pendingCount > 0): ?>
    <div style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(217, 119, 6, 0.1)); border: 1px solid rgba(245, 158, 11, 0.4); border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
        <div class="flex items-center gap-3">
            <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(245, 158, 11, 0.2); color: #f59e0b; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="shield-alert" style="width: 20px; height: 20px;"></i>
            </div>
            <div>
                <div style="font-weight: 700; color: #fbbf24; font-size: 0.95rem;">Action Required: <?= $pendingCount ?> Pending Instructor Account(s)</div>
                <div style="color: #cbd5e1; font-size: 0.825rem;">Only authorized faculty with verified credentials can be approved to access the Instructor Dashboard.</div>
            </div>
        </div>
        <button class="btn btn-sm" onclick="filterByPending()" style="background: #f59e0b; color: #000; font-weight: 700; border: none; padding: 0.4rem 0.9rem; border-radius: 8px;">
            Filter Pending Faculty
        </button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div style="background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #a7f3d0;">
        <?= htmlspecialchars($success) ?>
        <?php if ($generatedPassword): ?>
            <div style="margin-top: 0.5rem; font-weight: 600;">
                Temporary password: <code style="background: #fff; padding: 2px 8px; border-radius: 6px; border: 1px solid #a7f3d0;"><?= htmlspecialchars($generatedPassword) ?></code>
                <div style="font-weight: 400; font-size: 0.8rem; margin-top: 0.25rem;">Please copy and share this securely — it will not be shown again.</div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #fca5a5;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="card" style="padding: 1rem; margin-bottom: 1rem; display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
    <div style="position: relative; flex: 1; min-width: 220px;">
        <i data-lucide="search" style="width:15px;height:15px; position:absolute; left:10px; top:50%; transform:translateY(-50%); color: var(--text-muted);"></i>
        <input type="text" id="userSearch" class="form-control" style="padding-left: 32px;" placeholder="Search by name, email, or faculty ID..." onkeyup="filterUsers()">
    </div>
    <select id="roleFilter" class="form-control" style="max-width: 180px;" onchange="filterUsers()">
        <option value="">All Roles</option>
        <option value="Admin">Admin</option>
        <option value="Instructor">Instructor</option>
        <option value="Student">Student</option>
    </select>
    <select id="statusFilter" class="form-control" style="max-width: 190px;" onchange="filterUsers()">
        <option value="">All Statuses</option>
        <option value="approved">Approved / Verified</option>
        <option value="pending">Pending Approval</option>
        <option value="rejected">Rejected / Inactive</option>
    </select>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>User / Faculty</th>
                    <th>Email Address</th>
                    <th>Role</th>
                    <th>Account Status &amp; Credentials</th>
                    <th>Joined Date</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody id="userTableBody">
                <?php foreach ($users as $u): 
                    $uStatus = $u['status'] ?? 'approved';
                    $isPendingInst = ($u['role'] === 'Instructor' && $uStatus === 'pending');
                ?>
                <tr data-search="<?= htmlspecialchars(strtolower($u['name'] . ' ' . $u['email'] . ' ' . ($u['faculty_id'] ?? '') . ' ' . ($u['department'] ?? ''))) ?>" 
                    data-role="<?= htmlspecialchars($u['role']) ?>"
                    data-status="<?= htmlspecialchars($uStatus) ?>"
                    style="<?= $isPendingInst ? 'background: rgba(245, 158, 11, 0.05);' : '' ?>">
                    <td>
                        <div style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($u['name']) ?></div>
                        <?php if ($u['role'] === 'Instructor' && !empty($u['faculty_id'])): ?>
                            <div style="font-size: 0.75rem; color: #38bdf8; font-weight: 600;">Faculty ID: <?= htmlspecialchars($u['faculty_id']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <span class="badge badge-<?= $roleBadge[$u['role']] ?? 'neutral' ?>">
                            <?= htmlspecialchars($u['role']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($uStatus === 'approved'): ?>
                            <span class="badge" style="background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3); font-weight: 600; font-size: 0.75rem;">
                                <i data-lucide="check-circle" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle;"></i> Verified
                            </span>
                            <?php if (!empty($u['department'])): ?>
                                <span style="font-size: 0.75rem; color: #94a3b8; display: block; margin-top: 2px;">Dept: <?= htmlspecialchars($u['department']) ?></span>
                            <?php endif; ?>
                        <?php elseif ($uStatus === 'pending'): ?>
                            <span class="badge" style="background: rgba(245,158,11,0.2); color: #fbbf24; border: 1px solid rgba(245,158,11,0.4); font-weight: 700; font-size: 0.75rem;">
                                <i data-lucide="clock" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle;"></i> Pending Verification
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3); font-weight: 600; font-size: 0.75rem;">
                                Rejected
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.8125rem; color: var(--text-muted);"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <div class="flex justify-center items-center gap-2">
                            <?php if ($isPendingInst): ?>
                                <button type="button" class="btn btn-sm btn-success" style="padding: 4px 10px; font-size: 0.75rem; font-weight: 700; background: #10b981; border: none; color: #fff; display: inline-flex; align-items: center; gap: 4px;"
                                    title="Verify and Approve Faculty Member"
                                    onclick='openApproveModal(<?= (int)$u['id'] ?>, <?= json_encode($u['name']) ?>, <?= json_encode($u['email']) ?>, <?= json_encode($u['faculty_id'] ?? '') ?>, <?= json_encode($u['department'] ?? '') ?>)'>
                                    <i data-lucide="user-check" style="width: 13px; height: 13px;"></i> Approve
                                </button>
                            <?php endif; ?>

                            <button type="button" class="icon-btn" title="Edit User"
                                onclick='openEditModal(<?= (int)$u['id'] ?>, <?= json_encode($u['name']) ?>, <?= json_encode($u['email']) ?>, <?= json_encode($u['role']) ?>, <?= json_encode($uStatus) ?>, <?= json_encode($u['faculty_id'] ?? '') ?>, <?= json_encode($u['department'] ?? '') ?>, <?= json_encode($u['verification_notes'] ?? '') ?>)'>
                                <i data-lucide="pencil" style="width:16px;height:16px;"></i>
                            </button>

                            <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user? This cannot be undone.');" style="display:inline;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" class="icon-btn text-danger" title="Delete User">
                                    <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted text-xs">You</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($users) === 0): ?>
                <tr><td colspan="6" class="text-center text-muted" style="padding: 2rem;">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="noResultsRow" class="text-center text-muted hidden" style="padding: 2rem;">No users match your filter criteria.</div>
    </div>
</div>

<!-- Add User / Faculty Modal -->
<div id="userModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h2>Add New User / Faculty</h2>
            <button class="icon-btn" onclick="closeModal()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="user-form" method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="add_user">
                <div class="form-group mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="name" class="form-control" required placeholder="e.g. Prof. Maria Santos">
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" required placeholder="user@lspu.edu.ph">
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Assigned Role</label>
                    <select name="role" id="addRoleSelect" class="form-control" onchange="toggleFacultyFields('add')">
                        <option value="Student">Student (Simulation Learner)</option>
                        <option value="Instructor">Instructor (Faculty Member)</option>
                        <option value="Admin">Admin (System Administrator)</option>
                    </select>
                </div>

                <!-- Dynamic Faculty Verification Fields -->
                <div id="addFacultyFields" style="display:none; background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.25); border-radius: 10px; padding: 1rem; margin-bottom: 1rem;">
                    <div style="font-weight: 700; font-size: 0.85rem; color: #93c5fd; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i data-lucide="shield-check" style="width: 16px; height: 16px;"></i> Faculty Verification Details
                    </div>
                    <div class="form-group mb-2">
                        <label class="form-label" style="font-size: 0.8rem;">Faculty Employee ID #</label>
                        <input type="text" name="faculty_id" class="form-control" placeholder="e.g. LSPU-FAC-2024-089">
                    </div>
                    <div class="form-group mb-2">
                        <label class="form-label" style="font-size: 0.8rem;">College / Department</label>
                        <input type="text" name="department" class="form-control" placeholder="e.g. College of Business Administration and Accountancy">
                    </div>
                    <div class="form-group mb-2">
                        <label class="form-label" style="font-size: 0.8rem;">Verification Notes</label>
                        <input type="text" name="verification_notes" class="form-control" placeholder="e.g. Verified with LSPU Dean of Accountancy">
                    </div>
                    <div class="form-group mb-1">
                        <label class="form-label" style="font-size: 0.8rem;">Initial Status</label>
                        <select name="status" class="form-control">
                            <option value="approved">Approved &amp; Verified (Grant Dashboard Access)</option>
                            <option value="pending">Pending Further Verification</option>
                        </select>
                    </div>
                </div>

                <div class="form-group mb-2">
                    <label class="form-label">Password (optional)</label>
                    <input type="text" name="password" class="form-control" placeholder="Leave blank to auto-generate temporary password">
                </div>
                <div class="text-muted text-xs">
                    * If left blank, a random secure temporary password will be generated and displayed once.
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="user-form" class="btn btn-primary">Create Account</button>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 520px;">
        <div class="modal-header">
            <h2>Edit User &amp; Credentials</h2>
            <button class="icon-btn" onclick="closeEditModal()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="edit-user-form" method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="id" id="editUserId">
                
                <div class="form-group mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="name" id="editUserName" class="form-control" required>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" id="editUserEmail" class="form-control" required>
                </div>
                <div class="grid grid-cols-2 gap-3 mb-3" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label class="form-label">Role</label>
                        <select name="role" id="editUserRole" class="form-control" onchange="toggleFacultyFields('edit')">
                            <option value="Student">Student</option>
                            <option value="Instructor">Instructor</option>
                            <option value="Admin">Admin</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Account Status</label>
                        <select name="status" id="editUserStatus" class="form-control">
                            <option value="approved">Approved / Active</option>
                            <option value="pending">Pending Approval</option>
                            <option value="rejected">Rejected / Disabled</option>
                        </select>
                    </div>
                </div>

                <!-- Edit Faculty Details -->
                <div id="editFacultyFields" style="display:none; background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.25); border-radius: 10px; padding: 1rem; margin-bottom: 1rem;">
                    <div style="font-weight: 700; font-size: 0.85rem; color: #93c5fd; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i data-lucide="shield-check" style="width: 16px; height: 16px;"></i> Faculty Verification Details
                    </div>
                    <div class="form-group mb-2">
                        <label class="form-label" style="font-size: 0.8rem;">Faculty Employee ID #</label>
                        <input type="text" name="faculty_id" id="editFacultyId" class="form-control" placeholder="e.g. LSPU-FAC-2024-089">
                    </div>
                    <div class="form-group mb-2">
                        <label class="form-label" style="font-size: 0.8rem;">College / Department</label>
                        <input type="text" name="department" id="editDepartment" class="form-control" placeholder="e.g. CBAA - Accountancy">
                    </div>
                    <div class="form-group mb-1">
                        <label class="form-label" style="font-size: 0.8rem;">Admin Verification Notes</label>
                        <input type="text" name="verification_notes" id="editVerificationNotes" class="form-control" placeholder="e.g. Verified with university directory">
                    </div>
                </div>

                <div class="form-group mb-2">
                    <label class="form-label">Reset Password (optional)</label>
                    <input type="text" name="password" class="form-control" placeholder="Leave blank to keep current password">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
            <button type="submit" form="edit-user-form" class="btn btn-primary">Save Changes</button>
        </div>
    </div>
</div>

<!-- Faculty Approval Modal -->
<div id="approveModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <h2 style="display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="shield-check" style="width: 20px; height: 20px; color: #10b981;"></i>
                Verify Faculty Instructor
            </h2>
            <button class="icon-btn" onclick="closeApproveModal()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="approve-form" method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="approve_faculty">
                <input type="hidden" name="id" id="approveUserId">

                <p style="color: var(--text-muted); font-size: 0.875rem; margin-bottom: 1rem; line-height: 1.5;">
                    Confirm that <strong id="approveUserName" style="color: #fff;"></strong> (<span id="approveUserEmail" style="color: #93c5fd;"></span>) is a legitimate university faculty member before granting Instructor Dashboard privileges.
                </p>

                <div class="form-group mb-3">
                    <label class="form-label">Faculty ID / Employee Number</label>
                    <input type="text" name="faculty_id" id="approveFacultyId" class="form-control" required placeholder="e.g. LSPU-FAC-2024-012">
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Department / College</label>
                    <input type="text" name="department" id="approveDepartment" class="form-control" required placeholder="e.g. College of Business Administration and Accountancy">
                </div>
                <div class="form-group mb-2">
                    <label class="form-label">Verification Documentation / Remarks</label>
                    <input type="text" name="verification_notes" class="form-control" value="Verified legitimate faculty member by Administrator" required>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeApproveModal()">Cancel</button>
            <button type="submit" form="approve-form" class="btn btn-primary" style="background: #10b981; border-color: #10b981;">Confirm &amp; Approve Faculty</button>
        </div>
    </div>
</div>

<script>
function toggleFacultyFields(mode) {
    const roleSelect = document.getElementById(mode === 'add' ? 'addRoleSelect' : 'editUserRole');
    const fields = document.getElementById(mode === 'add' ? 'addFacultyFields' : 'editFacultyFields');
    if (roleSelect && fields) {
        fields.style.display = (roleSelect.value === 'Instructor') ? 'block' : 'none';
    }
}

function openAddModal() {
    const modal = document.getElementById('userModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    toggleFacultyFields('add');
}
function closeModal() {
    const modal = document.getElementById('userModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}
document.getElementById('userModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function openEditModal(id, name, email, role, status, facultyId, department, notes) {
    document.getElementById('editUserId').value = id;
    document.getElementById('editUserName').value = name;
    document.getElementById('editUserEmail').value = email;
    document.getElementById('editUserRole').value = role;
    document.getElementById('editUserStatus').value = status || 'approved';
    document.getElementById('editFacultyId').value = facultyId || '';
    document.getElementById('editDepartment').value = department || '';
    document.getElementById('editVerificationNotes').value = notes || '';
    toggleFacultyFields('edit');
    const modal = document.getElementById('editUserModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}
function closeEditModal() {
    const modal = document.getElementById('editUserModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}
document.getElementById('editUserModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

function openApproveModal(id, name, email, facultyId, department) {
    document.getElementById('approveUserId').value = id;
    document.getElementById('approveUserName').textContent = name;
    document.getElementById('approveUserEmail').textContent = email;
    document.getElementById('approveFacultyId').value = facultyId || '';
    document.getElementById('approveDepartment').value = department || 'College of Business Administration and Accountancy';
    const modal = document.getElementById('approveModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}
function closeApproveModal() {
    const modal = document.getElementById('approveModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}
document.getElementById('approveModal').addEventListener('click', function(e) {
    if (e.target === this) closeApproveModal();
});

function filterByPending() {
    document.getElementById('statusFilter').value = 'pending';
    document.getElementById('roleFilter').value = 'Instructor';
    filterUsers();
}

function filterUsers() {
    const q = document.getElementById('userSearch').value.toLowerCase();
    const role = document.getElementById('roleFilter').value;
    const status = document.getElementById('statusFilter').value;
    const rows = document.querySelectorAll('#userTableBody tr[data-search]');
    let visibleCount = 0;
    rows.forEach(row => {
        const matchesSearch = row.getAttribute('data-search').includes(q);
        const matchesRole = !role || row.getAttribute('data-role') === role;
        const matchesStatus = !status || row.getAttribute('data-status') === status;
        const show = matchesSearch && matchesRole && matchesStatus;
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
    document.getElementById('noResultsRow').classList.toggle('hidden', visibleCount !== 0);
}
</script>

<?php require_once '../includes/footer.php'; ?>