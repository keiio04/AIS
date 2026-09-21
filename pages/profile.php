<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';

$db = get_db();
$user_id = (int)$_SESSION['user_id'];
$message = '';
$msgType = 'success';

// Handle Profile and Password Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        
        if ($name) {
            $update = $db->prepare("UPDATE users SET name = ? WHERE id = ?");
            $update->bind_param('si', $name, $user_id);
            if ($update->execute()) {
                $_SESSION['user_name'] = $name;
                $message = "Profile name updated successfully.";
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
        $currHash = $checkStmt->get_result()->fetch_row()[0] ?? '';

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

// Fetch current user data
$stmt = $db->prepare("SELECT id, name, email, role, created_at, active_company_id FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Student Academic Information (Section & Assigned Instructor)
$studentSection = null;
$instructorName = null;
$instructorEmail = null;

if ($user['role'] === 'Student') {
    $insStmt = $db->prepare("
        SELECT ins.section, inst.name as instructor_name, inst.email as instructor_email 
        FROM instructor_students ins 
        JOIN users inst ON inst.id = ins.instructor_id 
        WHERE ins.student_id = ? 
        LIMIT 1
    ");
    $insStmt->bind_param('i', $user_id);
    $insStmt->execute();
    $sectionRow = $insStmt->get_result()->fetch_assoc();
    if ($sectionRow) {
        $studentSection = $sectionRow['section'];
        $instructorName = $sectionRow['instructor_name'];
        $instructorEmail = $sectionRow['instructor_email'];
    }
}

// Active Company and Simulation Stats
$companyInfo = null;
$totalJournals = 0;
$totalAccounts = 0;

$activeCompId = $user['active_company_id'] ?? null;
if (!$activeCompId) {
    // Check latest company
    $cStmt = $db->prepare("SELECT id FROM companies WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $cStmt->bind_param('i', $user_id);
    $cStmt->execute();
    $cRes = $cStmt->get_result()->fetch_assoc();
    if ($cRes) {
        $activeCompId = (int)$cRes['id'];
    }
}

if ($activeCompId) {
    $compStmt = $db->prepare("SELECT id, name, business_type, tax_registered, tax_type, period_type, created_at FROM companies WHERE id = ?");
    $compStmt->bind_param('i', $activeCompId);
    $compStmt->execute();
    $companyInfo = $compStmt->get_result()->fetch_assoc();

    if ($companyInfo) {
        // Count journal entries
        $jStmt = $db->prepare("SELECT COUNT(*) as cnt FROM journal_entries WHERE company_id = ?");
        $jStmt->bind_param('i', $activeCompId);
        $jStmt->execute();
        $totalJournals = (int)($jStmt->get_result()->fetch_assoc()['cnt'] ?? 0);

        // Count chart of accounts
        $aStmt = $db->prepare("SELECT COUNT(*) as cnt FROM accounts WHERE company_id = ?");
        $aStmt->bind_param('i', $activeCompId);
        $aStmt->execute();
        $totalAccounts = (int)($aStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    }
}

// Generate avatar initials
$parts = explode(' ', $user['name'] ?? 'User');
$initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
$registeredDate = !empty($user['created_at']) ? date('M d, Y', strtotime($user['created_at'])) : 'N/A';
?>

<div style="padding: 1.5rem; max-width: 1000px; margin: 0 auto;">
    
    <!-- Page Header -->
    <div style="margin-bottom: 1.5rem;">
        <h1 style="font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.25rem; letter-spacing: -0.02em;">
            <?= $user['role'] === 'Student' ? 'Student Profile' : 'User Profile' ?>
        </h1>
        <p style="color: var(--text-muted); font-size: 0.9rem;">
            View your academic details, simulation records, and manage your account security.
        </p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="margin-bottom: 1.5rem; border-radius: 12px; display: flex; align-items: center; gap: 0.75rem;">
        <i data-lucide="<?= $msgType === 'success' ? 'check-circle-2' : 'alert-circle' ?>" style="width: 20px; height: 20px; flex-shrink: 0;"></i>
        <div><?= htmlspecialchars($message) ?></div>
    </div>
    <?php endif; ?>

    <!-- 1. STUDENT IDENTITY BANNER -->
    <div class="card" style="padding: 1.75rem; border-radius: 18px; margin-bottom: 1.5rem; background: var(--bg-card); border: 1px solid var(--border-color); box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
        <div style="display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;">
            
            <!-- Large Initials Avatar -->
            <div style="width: 84px; height: 84px; border-radius: 50%; background: linear-gradient(135deg, #0284c7, #2563eb, #1d4ed8); display: flex; align-items: center; justify-content: center; color: #ffffff; font-size: 2.2rem; font-weight: 800; flex-shrink: 0; box-shadow: 0 8px 24px rgba(37,99,235,0.35); border: 3px solid rgba(255,255,255,0.2);">
                <?= $initials ?>
            </div>

            <!-- Student Summary Info -->
            <div style="flex: 1; min-width: 260px;">
                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.35rem;">
                    <h2 style="font-size: 1.45rem; font-weight: 800; color: var(--text-primary); margin: 0; letter-spacing: -0.01em;">
                        <?= htmlspecialchars($user['name']) ?>
                    </h2>
                    <span class="badge" style="background: rgba(37, 99, 235, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3); font-weight: 700; font-size: 0.75rem; padding: 0.25rem 0.65rem; border-radius: 99px;">
                        <?= $user['role'] === 'Student' ? '🎓 Student' : ($user['role'] === 'Instructor' ? '👨‍🏫 Instructor' : '🛡️ Admin') ?>
                    </span>
                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); font-weight: 700; font-size: 0.75rem; padding: 0.25rem 0.65rem; border-radius: 99px;">
                        ● Active
                    </span>
                </div>

                <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; font-size: 0.875rem; color: var(--text-muted); margin-top: 0.4rem;">
                    <div style="display: flex; align-items: center; gap: 0.4rem;">
                        <i data-lucide="mail" style="width: 15px; height: 15px; color: var(--text-muted);"></i>
                        <?= htmlspecialchars($user['email']) ?>
                    </div>
                    <?php if ($user['role'] === 'Student'): ?>
                    <div style="display: flex; align-items: center; gap: 0.4rem;">
                        <i data-lucide="book-open" style="width: 15px; height: 15px; color: #3b82f6;"></i>
                        <span>Section: <strong><?= htmlspecialchars($studentSection ?? 'Not Assigned') ?></strong></span>
                    </div>
                    <?php if ($instructorName): ?>
                    <div style="display: flex; align-items: center; gap: 0.4rem;">
                        <i data-lucide="user-check" style="width: 15px; height: 15px; color: #10b981;"></i>
                        <span>Instructor: <strong><?= htmlspecialchars($instructorName) ?></strong></span>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <div style="display: flex; align-items: center; gap: 0.4rem;">
                        <i data-lucide="calendar" style="width: 15px; height: 15px; color: var(--text-muted);"></i>
                        <span>Enrolled: <?= $registeredDate ?></span>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php if ($user['role'] === 'Student'): ?>
    <!-- 2. SIMULATION & PRACTICE COMPANY SUMMARY -->
    <div style="margin-bottom: 1.5rem;">
        <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="briefcase" style="width: 18px; height: 18px; color: #3b82f6;"></i> Accounting Simulation Overview
        </h3>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
            
            <!-- Active Practice Entity Card -->
            <div class="card" style="padding: 1.25rem; border-radius: 14px; background: var(--bg-card); border: 1px solid var(--border-color);">
                <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.35rem;">
                    Active Practice Business
                </div>
                <div style="font-size: 1.15rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.35rem;">
                    <?= htmlspecialchars($companyInfo['name'] ?? 'No Active Company') ?>
                </div>
                <div style="font-size: 0.8rem; color: var(--text-muted); display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                    <?php if ($companyInfo): ?>
                        <span style="background: var(--bg-tertiary); padding: 0.2rem 0.5rem; border-radius: 6px; font-weight: 600;">
                            <?= htmlspecialchars($companyInfo['business_type'] ?? 'Service') ?>
                        </span>
                        <span>•</span>
                        <span><?= !empty($companyInfo['tax_registered']) ? htmlspecialchars($companyInfo['tax_type'] ?? 'Tax Registered') : 'Non-VAT' ?></span>
                    <?php else: ?>
                        <span>Setup your company to start recording journal entries.</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Total Transactions Card -->
            <div class="card" style="padding: 1.25rem; border-radius: 14px; background: var(--bg-card); border: 1px solid var(--border-color);">
                <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.35rem;">
                    Journal Entries Recorded
                </div>
                <div style="display: flex; align-items: baseline; gap: 0.5rem;">
                    <div style="font-size: 1.65rem; font-weight: 900; color: #3b82f6;">
                        <?= number_format($totalJournals) ?>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                        total transactions posted
                    </div>
                </div>
                <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Across General, Sales, Purchases, and Cash Journals
                </div>
            </div>

            <!-- Chart of Accounts Card -->
            <div class="card" style="padding: 1.25rem; border-radius: 14px; background: var(--bg-card); border: 1px solid var(--border-color);">
                <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.35rem;">
                    Chart of Accounts
                </div>
                <div style="display: flex; align-items: baseline; gap: 0.5rem;">
                    <div style="font-size: 1.65rem; font-weight: 900; color: #10b981;">
                        <?= number_format($totalAccounts) ?>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                        active ledger accounts
                    </div>
                </div>
                <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Ready for Trial Balance and Financial Reporting
                </div>
            </div>

        </div>
    </div>
    <?php endif; ?>

    <!-- 3. EDITABLE SETTINGS & SECURITY GRID -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
        
        <!-- Profile Details Form -->
        <div class="card" style="padding: 1.5rem; border-radius: 16px; background: var(--bg-card); border: 1px solid var(--border-color);">
            <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="user-cog" style="width: 18px; height: 18px; color: #3b82f6;"></i> Personal Information
            </h3>

            <form method="POST">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; display: block;">Full Name</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required style="border-radius: 8px;">
                </div>
                
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; display: block;">Email Address</label>
                    <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly disabled style="background: var(--bg-secondary); cursor: not-allowed; opacity: 0.75; border-radius: 8px;">
                    <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.25rem;">Login email address is locked for institutional security.</div>
                </div>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; display: block;">System Role</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['role']) ?>" readonly disabled style="background: var(--bg-secondary); cursor: not-allowed; opacity: 0.75; border-radius: 8px;">
                </div>

                <button type="submit" class="btn btn-primary" style="font-weight: 700; padding: 0.6rem 1.4rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 0.4rem;">
                    <i data-lucide="save" style="width: 15px; height: 15px;"></i> Save Changes
                </button>
            </form>
        </div>

        <!-- Security & Password Form -->
        <div class="card" style="padding: 1.5rem; border-radius: 16px; background: var(--bg-card); border: 1px solid var(--border-color);">
            <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="shield-check" style="width: 18px; height: 18px; color: #ef4444;"></i> Account Security
            </h3>

            <form method="POST">
                <input type="hidden" name="action" value="update_password">
                
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; display: block;">Current Password</label>
                    <input type="password" name="current_password" class="form-control" required placeholder="Enter current password" style="border-radius: 8px;">
                </div>

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; display: block;">New Password</label>
                    <input type="password" name="new_password" class="form-control" required placeholder="Min. 6 characters" style="border-radius: 8px;">
                </div>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; display: block;">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required placeholder="Repeat new password" style="border-radius: 8px;">
                </div>

                <button type="submit" class="btn btn-danger" style="font-weight: 700; padding: 0.6rem 1.4rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 0.4rem;">
                    <i data-lucide="key" style="width: 15px; height: 15px;"></i> Update Password
                </button>
            </form>
        </div>

    </div>
</div>

<script>
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>

<?php require_once '../includes/footer.php'; ?>
