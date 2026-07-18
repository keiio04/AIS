<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';

$db = get_db();
$user_id = $_SESSION['user_id'];
$message = '';
$msgType = 'success';

// Fetch current user data
$stmt = $db->prepare("SELECT name, email, role, created_at FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        
        if ($name) {
            $update = $db->prepare("UPDATE users SET name = ? WHERE id = ?");
            $update->bind_param('si', $name, $user_id);
            if ($update->execute()) {
                $_SESSION['user_name'] = $name; // Update session
                $user['name'] = $name;
                $message = "Profile updated successfully.";
                $msgType = "success";
                log_activity($db, $user_id, 'Update', 'Profile', 'Updated profile name');
            } else {
                $message = "Failed to update profile.";
                $msgType = "danger";
            }
        } else {
            $message = "Name cannot be empty.";
            $msgType = "danger";
        }
    } elseif ($action === 'update_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Verify current password
        $checkStmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $checkStmt->bind_param('i', $user_id);
        $checkStmt->execute();
        $currHash = $checkStmt->get_result()->fetch_row()[0];

        if (!password_verify($current_password, $currHash)) {
            $message = "Incorrect current password.";
            $msgType = "danger";
        } elseif (strlen($new_password) < 6) {
            $message = "New password must be at least 6 characters.";
            $msgType = "danger";
        } elseif ($new_password !== $confirm_password) {
            $message = "New passwords do not match.";
            $msgType = "danger";
        } else {
            $newHash = password_hash($new_password, PASSWORD_BCRYPT);
            $updatePass = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updatePass->bind_param('si', $newHash, $user_id);
            if ($updatePass->execute()) {
                $message = "Password updated successfully.";
                $msgType = "success";
                log_activity($db, $user_id, 'Update', 'Profile', 'Updated password');
            } else {
                $message = "Failed to update password.";
                $msgType = "danger";
            }
        }
    }
}

// Generate avatar initials
$parts = explode(' ', $user['name']);
$initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
?>

<div style="padding: 1.5rem; max-width: 900px; margin: 0 auto;">
    <div style="margin-bottom: 2rem;">
        <h1 style="font-size: 1.8rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.5rem; letter-spacing: -0.02em;">Account Settings</h1>
        <p style="color: var(--text-muted); font-size: 0.95rem;">Manage your profile and security preferences.</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="margin-bottom: 2rem;">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem;">
        
        <!-- Profile Information -->
        <div class="card" style="padding: 2rem;">
            <div style="display: flex; gap: 2rem; align-items: flex-start; flex-wrap: wrap;">
                
                <div style="width: 100px; height: 100px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); display: flex; align-items: center; justify-content: center; color: white; font-size: 2.5rem; font-weight: 700; flex-shrink: 0; box-shadow: 0 10px 25px rgba(59,130,246,0.3); margin: 0 auto;">
                    <?= $initials ?>
                </div>

                <div style="flex: 1;">
                    <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i data-lucide="user" style="width: 18px; height: 18px; color: var(--primary-color);"></i> Profile Information
                    </h3>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">Full Name</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required style="background: var(--bg-tertiary);">
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">Email Address</label>
                            <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly disabled style="background: var(--bg-secondary); cursor: not-allowed; opacity: 0.7;">
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">Email address cannot be changed.</div>
                        </div>

                        <div class="form-group" style="margin-bottom: 1.5rem;">
                            <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">Role</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($user['role']) ?>" readonly disabled style="background: var(--bg-secondary); cursor: not-allowed; opacity: 0.7;">
                        </div>

                        <button type="submit" class="btn btn-primary" style="font-weight: 600; padding: 0.6rem 1.5rem;">Save Changes</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Security -->
        <div class="card" style="padding: 2rem;">
            <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="lock" style="width: 18px; height: 18px; color: #ef4444;"></i> Security
            </h3>

            <form method="POST">
                <input type="hidden" name="action" value="update_password">
                
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">Current Password</label>
                    <input type="password" name="current_password" class="form-control" required style="background: var(--bg-tertiary);">
                </div>

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">New Password</label>
                    <input type="password" name="new_password" class="form-control" required style="background: var(--bg-tertiary);" placeholder="Min. 6 characters">
                </div>

                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required style="background: var(--bg-tertiary);">
                </div>

                <button type="submit" class="btn btn-danger" style="font-weight: 600; padding: 0.6rem 1.5rem;">Update Password</button>
            </form>
        </div>

    </div>
</div>

<?php require_once '../includes/footer.php'; ?>