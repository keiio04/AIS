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
.adm-alert-pending {
    background: #fffbeb;
    border: 1px solid #fef3c7;
    border-left: 4px solid #f59e0b;
    border-radius: 8px;
    padding: 0.9rem 1.25rem;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.adm-filter-bar {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.85rem 1rem;
    margin-bottom: 1.25rem;
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    align-items: center;
}
.adm-search-wrap {
    position: relative;
    flex: 1;
    min-width: 240px;
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
.adm-select-filter {
    padding: 0.5rem 0.85rem;
    font-size: 0.85rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #ffffff;
    color: #334155;
    outline: none;
    min-width: 150px;
}
.adm-table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 2rem;
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
.adm-badge-verified {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 6px;
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}
.adm-badge-pending {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 6px;
    background: #fffbeb;
    color: #b45309;
    border: 1px solid #fde68a;
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
            <h1 class="adm-title">User Accounts &amp; Access Control</h1>
            <p class="adm-subtitle">Manage user permissions, student enrollment, and faculty instructor verifications.</p>
        </div>
        <div>
            <button class="adm-btn-primary" onclick="openAddModal()">
                <i data-lucide="user-plus" style="width:14px;height:14px;"></i> Add New User
            </button>
        </div>
    </div>

    <?php if ($pendingCount > 0): ?>
        <div class="adm-alert-pending">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <i data-lucide="shield-alert" style="width: 20px; height: 20px; color: #d97706; flex-shrink: 0;"></i>
                <div>
                    <div style="font-weight: 600; color: #92400e; font-size: 0.875rem;">Action Required: <?= $pendingCount ?> Pending Faculty Instructor(s)</div>
                    <div style="color: #b45309; font-size: 0.78rem;">Faculty registrations must be verified before instructor dashboard access is granted.</div>
                </div>
            </div>
            <button onclick="filterByPending()" style="background: #ffffff; color: #b45309; border: 1px solid #fde68a; padding: 0.4rem 0.85rem; border-radius: 6px; font-weight: 600; font-size: 0.78rem; cursor: pointer;">
                Filter Pending &rarr;
            </button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div style="background: #f0fdf4; color: #166534; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem; border: 1px solid #bbf7d0; font-size: 0.85rem;">
            <?= htmlspecialchars($success) ?>
            <?php if ($generatedPassword): ?>
                <div style="margin-top: 0.5rem; font-weight: 600;">
                    Generated password: <code style="background: #ffffff; padding: 2px 8px; border-radius: 4px; border: 1px solid #bbf7d0;"><?= htmlspecialchars($generatedPassword) ?></code>
                    <div style="font-weight: 400; font-size: 0.78rem; color: #166534; margin-top: 0.25rem;">Please copy and share this credentials securely.</div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="background: #fef2f2; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem; border: 1px solid #fecaca; font-size: 0.85rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- FILTER BAR -->
    <div class="adm-filter-bar">
        <div class="adm-search-wrap">
            <i data-lucide="search" style="width:14px;height:14px; position:absolute; left:10px; top:50%; transform:translateY(-50%); color: #94a3b8;"></i>
            <input type="text" id="userSearch" class="adm-search-input" placeholder="Search by name, email, or faculty ID..." onkeyup="filterUsers()">
        </div>
        <select id="roleFilter" class="adm-select-filter" onchange="filterUsers()">
            <option value="">All Roles</option>
            <option value="Admin">Admin</option>
            <option value="Instructor">Instructor</option>
            <option value="Student">Student</option>
        </select>
        <select id="statusFilter" class="adm-select-filter" onchange="filterUsers()">
            <option value="">All Statuses</option>
            <option value="approved">Approved</option>
            <option value="pending">Pending</option>
            <option value="rejected">Rejected</option>
        </select>
    </div>

    <!-- TABLE -->
    <div class="adm-table-card">
        <table class="adm-clean-table">
            <thead>
                <tr>
                    <th>User / Faculty</th>
                    <th>Email Address</th>
                    <th>Assigned Role</th>
                    <th>Verification Status</th>
                    <th>Joined Date</th>
                    <th style="text-align: center; width: 100px;">Actions</th>
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
                    style="<?= $isPendingInst ? 'background: #fffdf5;' : '' ?>">
                    <td>
                        <div style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($u['name']) ?></div>
                        <?php if ($u['role'] === 'Instructor' && !empty($u['faculty_id'])): ?>
                            <div style="font-size: 0.75rem; color: #64748b; font-weight: 500;">Faculty ID: <?= htmlspecialchars($u['faculty_id']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="color: #64748b;"><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <span class="adm-badge-tag"><?= htmlspecialchars($u['role']) ?></span>
                    </td>
                    <td>
                        <?php if ($uStatus === 'approved'): ?>
                            <span class="adm-badge-verified">
                                <i data-lucide="check" style="width: 11px; height: 11px;"></i> Approved
                            </span>
                            <?php if (!empty($u['department'])): ?>
                                <span style="font-size: 0.72rem; color: #94a3b8; display: block; margin-top: 2px;"><?= htmlspecialchars($u['department']) ?></span>
                            <?php endif; ?>
                        <?php elseif ($uStatus === 'pending'): ?>
                            <span class="adm-badge-pending">
                                <i data-lucide="clock" style="width: 11px; height: 11px;"></i> Pending Review
                            </span>
                        <?php else: ?>
                            <span class="adm-badge-tag" style="background:#fee2e2; color:#991b1b; border-color:#fca5a5;">
                                Rejected
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.78rem; color: #64748b;"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                    <td style="text-align: center;">
                        <div style="display: flex; align-items: center; justify-content: center; gap: 6px;">
                            <?php if ($isPendingInst): ?>
                                <button type="button" class="adm-btn-primary" style="padding: 3px 8px; font-size: 0.72rem;"
                                    title="Verify and Approve"
                                    onclick='openApproveModal(<?= (int)$u['id'] ?>, <?= json_encode($u['name']) ?>, <?= json_encode($u['email']) ?>, <?= json_encode($u['faculty_id'] ?? '') ?>, <?= json_encode($u['department'] ?? '') ?>)'>
                                    Approve
                                </button>
                            <?php endif; ?>

                            <button type="button" class="adm-icon-btn" title="Edit User"
                                onclick='openEditModal(<?= (int)$u['id'] ?>, <?= json_encode($u['name']) ?>, <?= json_encode($u['email']) ?>, <?= json_encode($u['role']) ?>, <?= json_encode($uStatus) ?>, <?= json_encode($u['faculty_id'] ?? '') ?>, <?= json_encode($u['department'] ?? '') ?>, <?= json_encode($u['verification_notes'] ?? '') ?>)'>
                                <i data-lucide="pencil" style="width:14px;height:14px;"></i>
                            </button>

                            <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                            <form method="POST" onsubmit="return confirm('Permanently delete this user account?');" style="display:inline;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" class="adm-icon-btn" style="color: #94a3b8;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'" title="Delete User">
                                    <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <span style="font-size: 0.72rem; color: #94a3b8;">(You)</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($users) === 0): ?>
                <tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 2.5rem;">No user accounts found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="noResultsRow" class="hidden" style="text-align: center; color: #94a3b8; padding: 2rem;">No users match your filter criteria.</div>
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