<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/header.php';

$db = get_db();

if ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'Super Admin') {
    echo '<div class="alert alert-danger" style="margin: 2rem;">Access Denied. You do not have permission to view this page.</div>';
    require_once '../includes/footer.php';
    exit;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_user') {
        $name = $_POST['name'];
        $email = $_POST['email'];
        $role = $_POST['role'];
        $password = password_hash('password123', PASSWORD_BCRYPT); // Default password
        
        try {
            $stmt = $db->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $name, $email, $password, $role);
            $stmt->execute();
            $success = "User added successfully. Default password is 'password123'.";
        } catch (Exception $e) {
            $error = "Error adding user: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'delete_user') {
        $id = (int)$_POST['id'];
        if ($id !== $_SESSION['user_id']) { // prevent self-deletion
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $success = "User deleted successfully.";
        } else {
            $error = "You cannot delete your own account.";
        }
    }
}

// Fetch all users
$res = $db->query("SELECT id, name, email, role, created_at FROM users ORDER BY name ASC");
$users = $res->fetch_all(MYSQLI_ASSOC);

?>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">User Management</h1>
        <p class="page-subtitle">Manage system users and their roles.</p>
    </div>
    <button class="btn btn-primary" onclick="openModal()">
        <i data-lucide="user-plus" style="width:15px;height:15px;"></i> Add User
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
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td style="font-weight: 500;"><?= htmlspecialchars($u['name']) ?></td>
                    <td class="text-muted"><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <span class="badge badge-<?= $u['role'] === 'Admin' ? 'primary' : 'neutral' ?>">
                            <?= htmlspecialchars($u['role']) ?>
                        </span>
                    </td>
                    <td style="font-size: 0.8125rem; color: var(--text-muted);"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <div class="flex justify-center gap-2">
                            <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" class="icon-btn text-danger" title="Delete User">
                                    <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted text-xs">Current User</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
                <div class="text-muted text-xs mt-2">
                    * The default password will be <strong>password123</strong>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="user-form" class="btn btn-primary">Add User</button>
        </div>
    </div>
</div>

<script>
function openModal() {
    const modal = document.getElementById('userModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}
function closeModal() {
    const modal = document.getElementById('userModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}
// Close on overlay click
document.getElementById('userModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php require_once '../includes/footer.php'; ?>
