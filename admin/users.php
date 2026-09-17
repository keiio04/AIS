<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/admin_auth.php';
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

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_user') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'Student';
        $customPassword = trim($_POST['password'] ?? '');

        if ($name === '' || $email === '') {
            $error = "Name and email are required.";
        } else {
            $plainPassword = $customPassword !== '' ? $customPassword : generate_random_password();
            $hashed = password_hash($plainPassword, PASSWORD_BCRYPT);
            try {
                $stmt = $db->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssss', $name, $email, $hashed, $role);
                $stmt->execute();
                log_activity($db, $_SESSION['user_id'], 'Create', 'User Management', "Created user: $email ($role)");
                $success = "User added successfully.";
                if ($customPassword === '') {
                    $generatedPassword = $plainPassword;
                }
            } catch (Exception $e) {
                $error = "Error adding user: " . $e->getMessage();
            }
        }

    } elseif ($_POST['action'] === 'edit_user') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'Student';
        $newPassword = trim($_POST['password'] ?? '');

        if ($name === '' || $email === '') {
            $error = "Name and email are required.";
        } else {
            try {
                if ($newPassword !== '') {
                    $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, role = ?, password = ? WHERE id = ?");
                    $stmt->bind_param('ssssi', $name, $email, $role, $hashed, $id);
                } else {
                    $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
                    $stmt->bind_param('sssi', $name, $email, $role, $id);
                }
                $stmt->execute();
                log_activity($db, $_SESSION['user_id'], 'Update', 'User Management', "Updated user: $email ($role)" . ($newPassword !== '' ? " + password reset" : ""));
                $success = "User updated successfully.";
            } catch (Exception $e) {
                $error = "Error updating user: " . $e->getMessage();
            }
        }

    } elseif ($_POST['action'] === 'delete_user') {
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

// Fetch all users
$res = $db->query("SELECT id, name, email, role, created_at FROM users ORDER BY name ASC");
$users = $res->fetch_all(MYSQLI_ASSOC);

$roleBadge = [
    'Admin' => 'primary',
    'Instructor' => 'info',
    'Student' => 'neutral',
];
?>


<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">User Management</h1>
        <p class="page-subtitle">Manage system users and their roles.</p>
    </div>
    <button class="btn btn-primary" onclick="openAddModal()">
        <i data-lucide="user-plus" style="width:15px;height:15px;"></i> Add User
    </button>
</div>

<?php if (isset($success)): ?>
    <div style="background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= htmlspecialchars($success) ?>
        <?php if ($generatedPassword): ?>
            <div style="margin-top: 0.5rem; font-weight: 600;">
                Temporary password: <code style="background: #fff; padding: 2px 8px; border-radius: 6px; border: 1px solid #a7f3d0;"><?= htmlspecialchars($generatedPassword) ?></code>
                <div style="font-weight: 400; font-size: 0.8rem; margin-top: 0.25rem;">Please copy and share this securely — it will not be shown again. Ask the user to change it after logging in.</div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="card" style="padding: 1rem; margin-bottom: 1rem; display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
    <div style="position: relative; flex: 1; min-width: 220px;">
        <i data-lucide="search" style="width:15px;height:15px; position:absolute; left:10px; top:50%; transform:translateY(-50%); color: var(--text-muted);"></i>
        <input type="text" id="userSearch" class="form-control" style="padding-left: 32px;" placeholder="Search by name or email..." onkeyup="filterUsers()">
    </div>
    <select id="roleFilter" class="form-control" style="max-width: 180px;" onchange="filterUsers()">
        <option value="">All Roles</option>
        <option value="Admin">Admin</option>
        <option value="Instructor">Instructor</option>
        <option value="Student">Student</option>
    </select>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Joined Date</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody id="userTableBody">
                <?php foreach ($users as $u): ?>
                <tr data-search="<?= htmlspecialchars(strtolower($u['name'] . ' ' . $u['email'])) ?>" data-role="<?= htmlspecialchars($u['role']) ?>">
                    <td style="font-weight: 500;"><?= htmlspecialchars($u['name']) ?></td>
                    <td class="text-muted"><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <span class="badge badge-<?= $roleBadge[$u['role']] ?? 'neutral' ?>">
                            <?= htmlspecialchars($u['role']) ?>
                        </span>
                    </td>
                    <td style="font-size: 0.8125rem; color: var(--text-muted);"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <div class="flex justify-center gap-2">
                            <button type="button" class="icon-btn" title="Edit User"
                                onclick='openEditModal(<?= (int)$u['id'] ?>, <?= json_encode($u['name']) ?>, <?= json_encode($u['email']) ?>, <?= json_encode($u['role']) ?>)'>
                                <i data-lucide="pencil" style="width:16px;height:16px;"></i>
                            </button>
                            <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user? This cannot be undone.');" style="display:inline;">
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
                <tr><td colspan="5" class="text-center text-muted" style="padding: 2rem;">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="noResultsRow" class="text-center text-muted hidden" style="padding: 2rem;">No users match your search.</div>
    </div>
</div>

<!-- Add User Modal -->
<div id="userModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 450px;">
        <div class="modal-header">
            <h2>Add New User</h2>
            <button class="icon-btn" onclick="closeModal()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="user-form" method="POST">
                <input type="hidden" name="action" value="add_user">
                <div class="form-group">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-control">
                        <option value="Student">Student</option>
                        <option value="Instructor">Instructor</option>
                        <option value="Admin">Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Password (optional)</label>
                    <input type="text" name="password" class="form-control" placeholder="Leave blank to auto-generate">
                </div>
                <div class="text-muted text-xs mt-2">
                    * If left blank, a random temporary password will be generated and shown once after saving.
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="user-form" class="btn btn-primary">Add User</button>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 450px;">
        <div class="modal-header">
            <h2>Edit User</h2>
            <button class="icon-btn" onclick="closeEditModal()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="edit-user-form" method="POST">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="id" id="editUserId">
                <div class="form-group">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="name" id="editUserName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" id="editUserEmail" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Role</label>
                    <select name="role" id="editUserRole" class="form-control">
                        <option value="Student">Student</option>
                        <option value="Instructor">Instructor</option>
                        <option value="Admin">Admin</option>
                    </select>
                </div>
                <div class="form-group">
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

<script>
function openAddModal() {
    const modal = document.getElementById('userModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}
function closeModal() {
    const modal = document.getElementById('userModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}
document.getElementById('userModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function openEditModal(id, name, email, role) {
    document.getElementById('editUserId').value = id;
    document.getElementById('editUserName').value = name;
    document.getElementById('editUserEmail').value = email;
    document.getElementById('editUserRole').value = role;
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

function filterUsers() {
    const q = document.getElementById('userSearch').value.toLowerCase();
    const role = document.getElementById('roleFilter').value;
    const rows = document.querySelectorAll('#userTableBody tr[data-search]');
    let visibleCount = 0;
    rows.forEach(row => {
        const matchesSearch = row.getAttribute('data-search').includes(q);
        const matchesRole = !role || row.getAttribute('data-role') === role;
        const show = matchesSearch && matchesRole;
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
    document.getElementById('noResultsRow').classList.toggle('hidden', visibleCount !== 0);
}
</script>

<?php require_once '../includes/footer.php'; ?>