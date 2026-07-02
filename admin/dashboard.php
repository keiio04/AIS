<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/admin_auth.php';
require_once '../includes/header.php';

$db = get_db();

// --- Stats ---
$users_count     = $db->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$companies_count = $db->query("SELECT COUNT(*) FROM companies")->fetch_row()[0];
$logs_count      = $db->query("SELECT COUNT(*) FROM activity_logs")->fetch_row()[0];
$admin_count     = $db->query("SELECT COUNT(*) FROM users WHERE role='Admin'")->fetch_row()[0];
$instructor_count= $db->query("SELECT COUNT(*) FROM users WHERE role='Instructor'")->fetch_row()[0];
$student_count   = $db->query("SELECT COUNT(*) FROM users WHERE role='Student'")->fetch_row()[0];

// --- Today's logs ---
$today_logs = $db->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at)=CURDATE()")->fetch_row()[0];

// --- Recent Logs ---
$recent_logs = $db->query("
    SELECT l.*, u.name as user_name, u.role as user_role
    FROM activity_logs l
    LEFT JOIN users u ON l.user_id = u.id
    ORDER BY l.created_at DESC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

// --- Latest users ---
$latest_users = $db->query("SELECT name, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
?>

<style>
/* ── Admin Dashboard Custom Styles ── */
.adm-wrapper { padding: 2rem 2rem 3rem; max-width: 1400px; margin: 0 auto; }

/* Hero banner */
.adm-hero {
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #4338ca 70%, #6366f1 100%);
    border-radius: 20px;
    padding: 2.5rem 2.5rem;
    margin-bottom: 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(99,102,241,0.35);
}
.adm-hero::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 300px; height: 300px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
}
.adm-hero::after {
    content: '';
    position: absolute;
    bottom: -80px; left: 30%;
    width: 250px; height: 250px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
}
.adm-hero-title {
    font-size: 2rem; font-weight: 800; color: #fff;
    letter-spacing: -0.03em; margin-bottom: 0.4rem;
}
.adm-hero-sub { font-size: 0.95rem; color: rgba(255,255,255,0.65); margin-bottom: 1.5rem; }
.adm-hero-btns { display: flex; gap: 0.75rem; flex-wrap: wrap; }
.adm-hero-btn {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.6rem 1.25rem; border-radius: 10px; font-size: 0.875rem;
    font-weight: 600; text-decoration: none; transition: all 0.2s;
    cursor: pointer;
}
.adm-hero-btn-primary {
    background: #fff; color: #4338ca;
}
.adm-hero-btn-primary:hover { background: #e0e7ff; transform: translateY(-1px); }
.adm-hero-btn-ghost {
    background: rgba(255,255,255,0.15); color: #fff;
    border: 1px solid rgba(255,255,255,0.25);
}
.adm-hero-btn-ghost:hover { background: rgba(255,255,255,0.25); transform: translateY(-1px); }
.adm-hero-right { text-align: right; flex-shrink: 0; }
.adm-hero-date { font-size: 0.8rem; color: rgba(255,255,255,0.5); margin-bottom: 0.25rem; }
.adm-hero-time { font-size: 2.5rem; font-weight: 800; color: #fff; letter-spacing: -0.04em; line-height: 1; }

/* Stats Grid */
.adm-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.25rem; margin-bottom: 2rem; }
@media(max-width:900px){ .adm-stats { grid-template-columns: repeat(2,1fr); } }
@media(max-width:500px){ .adm-stats { grid-template-columns: 1fr; } }

.adm-stat-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 1.5rem;
    position: relative;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
    animation: fadeUp 0.5s both;
}
.adm-stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(0,0,0,0.1); }
@keyframes fadeUp {
    from { opacity:0; transform: translateY(20px); }
    to   { opacity:1; transform: translateY(0); }
}
.adm-stat-card:nth-child(1){ animation-delay:.05s; }
.adm-stat-card:nth-child(2){ animation-delay:.1s; }
.adm-stat-card:nth-child(3){ animation-delay:.15s; }
.adm-stat-card:nth-child(4){ animation-delay:.2s; }

.adm-stat-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 1rem;
}
.adm-stat-label {
    font-size: 0.78rem; font-weight: 600; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.25rem;
}
.adm-stat-value {
    font-size: 2.25rem; font-weight: 800; color: var(--text-primary);
    letter-spacing: -0.04em; line-height: 1;
}
.adm-stat-sub {
    font-size: 0.78rem; color: var(--text-muted); margin-top: 0.4rem;
}
.adm-stat-glow {
    position: absolute; bottom: -20px; right: -20px;
    width: 100px; height: 100px; border-radius: 50%;
    opacity: 0.08;
}

/* Two-column layout */
.adm-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }
@media(max-width:900px){ .adm-row { grid-template-columns: 1fr; } }

/* Panel cards */
.adm-panel {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    overflow: hidden;
}
.adm-panel-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
}
.adm-panel-title {
    font-size: 0.9rem; font-weight: 700;
    color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;
}
.adm-panel-link {
    font-size: 0.8rem; color: var(--primary-color); text-decoration: none; font-weight: 600;
    display: flex; align-items: center; gap: 0.25rem;
    transition: opacity 0.2s;
}
.adm-panel-link:hover { opacity: 0.7; }

/* Role bar */
.adm-role-bar { padding: 1.25rem 1.5rem; }
.adm-role-row { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
.adm-role-row:last-child { margin-bottom: 0; }
.adm-role-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.adm-role-label { font-size: 0.85rem; color: var(--text-muted); flex: 1; }
.adm-role-count { font-size: 0.85rem; font-weight: 700; color: var(--text-primary); flex-shrink: 0; }
.adm-progress { flex: 2; background: var(--bg-tertiary); border-radius: 99px; height: 6px; overflow: hidden; }
.adm-progress-fill { height: 100%; border-radius: 99px; transition: width 1s ease; }

/* Activity timeline */
.adm-activity { padding: 0.5rem 0; }
.adm-act-item {
    display: flex; align-items: flex-start; gap: 1rem;
    padding: 0.85rem 1.5rem; border-bottom: 1px solid var(--border-color);
    transition: background 0.15s;
}
.adm-act-item:last-child { border-bottom: none; }
.adm-act-item:hover { background: var(--bg-tertiary); }
.adm-act-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.7rem; font-weight: 700; color: #fff; flex-shrink: 0;
}
.adm-act-body { flex: 1; min-width: 0; }
.adm-act-name { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); }
.adm-act-desc { font-size: 0.78rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.adm-act-time { font-size: 0.72rem; color: var(--text-muted); flex-shrink: 0; padding-top: 2px; }

/* Quick Actions */
.adm-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; padding: 1.25rem 1.5rem; }
.adm-action-btn {
    display: flex; align-items: center; gap: 0.75rem;
    padding: 0.85rem 1rem; border-radius: 12px;
    text-decoration: none; font-size: 0.85rem; font-weight: 600;
    color: var(--text-primary); background: var(--bg-tertiary);
    border: 1px solid var(--border-color);
    transition: all 0.2s; cursor: pointer;
}
.adm-action-btn:hover {
    background: var(--primary-color); color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99,102,241,0.3);
}
.adm-action-btn:hover .adm-action-icon { background: rgba(255,255,255,0.2); color: #fff; }
.adm-action-icon {
    width: 36px; height: 36px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    background: var(--bg-secondary); color: var(--primary-color);
    flex-shrink: 0; transition: all 0.2s;
}

/* New users table */
.adm-user-row {
    display: flex; align-items: center; gap: 0.85rem;
    padding: 0.85rem 1.5rem; border-bottom: 1px solid var(--border-color);
    transition: background 0.15s;
}
.adm-user-row:last-child { border-bottom: none; }
.adm-user-row:hover { background: var(--bg-tertiary); }
.adm-user-av {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; font-weight: 700; color: #fff; flex-shrink: 0;
}
.adm-user-name { font-size: 0.875rem; font-weight: 600; color: var(--text-primary); }
.adm-user-email { font-size: 0.75rem; color: var(--text-muted); }
.adm-role-badge {
    margin-left: auto; font-size: 0.7rem; font-weight: 600;
    padding: 3px 10px; border-radius: 99px;
}
</style>

<div class="adm-wrapper">

    <!-- HERO BANNER -->
    <div class="adm-hero">
        <div>
            <div class="adm-hero-title">⚙️ Admin Control Center</div>
            <div class="adm-hero-sub">Welcome back, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>. Here's your system overview.</div>
            <div class="adm-hero-btns">
                <a href="users.php" class="adm-hero-btn adm-hero-btn-primary">
                    <i data-lucide="users" style="width:15px;height:15px;"></i> Manage Users
                </a>
                <a href="logs.php" class="adm-hero-btn adm-hero-btn-ghost">
                    <i data-lucide="scroll-text" style="width:15px;height:15px;"></i> View Logs
                </a>
                <a href="<?= BASE_URL ?>pages/company_setup.php" class="adm-hero-btn adm-hero-btn-ghost">
                    <i data-lucide="building-2" style="width:15px;height:15px;"></i> Companies
                </a>
            </div>
        </div>
        <div class="adm-hero-right">
            <div class="adm-hero-date"><?= date('l, F j, Y') ?></div>
            <div class="adm-hero-time" id="live-clock">--:--</div>
        </div>
    </div>

    <!-- STAT CARDS -->
    <div class="adm-stats">
        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:rgba(99,102,241,0.12); color:#6366f1;">
                <i data-lucide="users" style="width:22px;height:22px;"></i>
            </div>
            <div class="adm-stat-label">Total Users</div>
            <div class="adm-stat-value"><?= $users_count ?></div>
            <div class="adm-stat-sub"><?= $admin_count ?> Admin · <?= $instructor_count ?> Instructor · <?= $student_count ?> Student</div>
            <div class="adm-stat-glow" style="background:#6366f1;"></div>
        </div>
        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:rgba(16,185,129,0.12); color:#10b981;">
                <i data-lucide="building-2" style="width:22px;height:22px;"></i>
            </div>
            <div class="adm-stat-label">Companies</div>
            <div class="adm-stat-value"><?= $companies_count ?></div>
            <div class="adm-stat-sub">Registered in the system</div>
            <div class="adm-stat-glow" style="background:#10b981;"></div>
        </div>
        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:rgba(245,158,11,0.12); color:#f59e0b;">
                <i data-lucide="scroll-text" style="width:22px;height:22px;"></i>
            </div>
            <div class="adm-stat-label">Total Logs</div>
            <div class="adm-stat-value"><?= number_format($logs_count) ?></div>
            <div class="adm-stat-sub">All-time activity records</div>
            <div class="adm-stat-glow" style="background:#f59e0b;"></div>
        </div>
        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:rgba(239,68,68,0.12); color:#ef4444;">
                <i data-lucide="zap" style="width:22px;height:22px;"></i>
            </div>
            <div class="adm-stat-label">Today's Activity</div>
            <div class="adm-stat-value"><?= $today_logs ?></div>
            <div class="adm-stat-sub">Actions logged today</div>
            <div class="adm-stat-glow" style="background:#ef4444;"></div>
        </div>
    </div>

    <!-- ROW 1: Recent Activity + Quick Actions -->
    <div class="adm-row">

        <!-- Recent Activity -->
        <div class="adm-panel">
            <div class="adm-panel-header">
                <div class="adm-panel-title">
                    <i data-lucide="activity" style="width:16px;height:16px;color:#6366f1;"></i>
                    Recent Activity
                </div>
                <a href="logs.php" class="adm-panel-link">
                    View All <i data-lucide="arrow-right" style="width:13px;height:13px;"></i>
                </a>
            </div>
            <div class="adm-activity">
                <?php if (empty($recent_logs)): ?>
                <div style="padding:2rem;text-align:center;color:var(--text-muted);font-size:0.875rem;">No activity yet.</div>
                <?php else: foreach($recent_logs as $log):
                    $colors = ['Admin'=>'#6366f1','Instructor'=>'#10b981','Student'=>'#f59e0b'];
                    $avatarColor = $colors[$log['user_role'] ?? 'Student'] ?? '#6b7280';
                    $initials = strtoupper(substr($log['user_name'] ?? 'S', 0, 2));
                    $actionColors = ['Create'=>'#10b981','Update'=>'#3b82f6','Delete'=>'#ef4444','Login'=>'#6366f1','Logout'=>'#f59e0b'];
                    $actionColor = $actionColors[$log['action']] ?? '#6b7280';
                    $timeAgo = date('M d, h:i A', strtotime($log['created_at']));
                ?>
                <div class="adm-act-item">
                    <div class="adm-act-avatar" style="background:<?= $avatarColor ?>;"><?= $initials ?></div>
                    <div class="adm-act-body">
                        <div class="adm-act-name"><?= htmlspecialchars($log['user_name'] ?? 'Unknown') ?></div>
                        <div class="adm-act-desc">
                            <span style="font-size:0.7rem;font-weight:700;color:<?= $actionColor ?>;background:<?= $actionColor ?>18;padding:1px 6px;border-radius:4px;margin-right:4px;"><?= htmlspecialchars($log['action']) ?></span>
                            <?= htmlspecialchars($log['description'] ?? $log['module'] ?? '') ?>
                        </div>
                    </div>
                    <div class="adm-act-time"><?= $timeAgo ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Quick Actions + Role Breakdown -->
        <div style="display:flex;flex-direction:column;gap:1.5rem;">

            <!-- Quick Actions -->
            <div class="adm-panel">
                <div class="adm-panel-header">
                    <div class="adm-panel-title">
                        <i data-lucide="zap" style="width:16px;height:16px;color:#f59e0b;"></i>
                        Quick Actions
                    </div>
                </div>
                <div class="adm-actions">
                    <a href="users.php" class="adm-action-btn">
                        <div class="adm-action-icon"><i data-lucide="user-plus" style="width:16px;height:16px;"></i></div>
                        Add New User
                    </a>
                    <a href="logs.php" class="adm-action-btn">
                        <div class="adm-action-icon"><i data-lucide="scroll-text" style="width:16px;height:16px;"></i></div>
                        View All Logs
                    </a>
                    <a href="<?= BASE_URL ?>pages/company_setup.php" class="adm-action-btn">
                        <div class="adm-action-icon"><i data-lucide="building-2" style="width:16px;height:16px;"></i></div>
                        Company Setup
                    </a>
                    <a href="<?= BASE_URL ?>pages/chart_of_accounts.php" class="adm-action-btn">
                        <div class="adm-action-icon"><i data-lucide="book-open" style="width:16px;height:16px;"></i></div>
                        Chart of Accounts
                    </a>
                </div>
            </div>

            <!-- Role Breakdown -->
            <div class="adm-panel">
                <div class="adm-panel-header">
                    <div class="adm-panel-title">
                        <i data-lucide="pie-chart" style="width:16px;height:16px;color:#10b981;"></i>
                        User Role Breakdown
                    </div>
                </div>
                <div class="adm-role-bar">
                    <?php
                    $roles = [
                        ['label'=>'Admins', 'count'=>$admin_count, 'color'=>'#6366f1'],
                        ['label'=>'Instructors', 'count'=>$instructor_count, 'color'=>'#10b981'],
                        ['label'=>'Students', 'count'=>$student_count, 'color'=>'#f59e0b'],
                    ];
                    foreach($roles as $r):
                        $pct = $users_count > 0 ? round(($r['count']/$users_count)*100) : 0;
                    ?>
                    <div class="adm-role-row">
                        <div class="adm-role-dot" style="background:<?= $r['color'] ?>;"></div>
                        <div class="adm-role-label"><?= $r['label'] ?></div>
                        <div class="adm-progress">
                            <div class="adm-progress-fill" style="width:<?= $pct ?>%;background:<?= $r['color'] ?>;"></div>
                        </div>
                        <div class="adm-role-count"><?= $r['count'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- ROW 2: Latest Registered Users -->
    <div class="adm-panel">
        <div class="adm-panel-header">
            <div class="adm-panel-title">
                <i data-lucide="user-check" style="width:16px;height:16px;color:#6366f1;"></i>
                Latest Registered Users
            </div>
            <a href="users.php" class="adm-panel-link">
                Manage All <i data-lucide="arrow-right" style="width:13px;height:13px;"></i>
            </a>
        </div>
        <?php if(empty($latest_users)): ?>
        <div style="padding:2rem;text-align:center;color:var(--text-muted);font-size:0.875rem;">No users yet.</div>
        <?php else: foreach($latest_users as $u):
            $colors = ['Admin'=>'#6366f1','Instructor'=>'#10b981','Student'=>'#f59e0b'];
            $bgColor = $colors[$u['role']] ?? '#6b7280';
            $roleBgAlpha = ['Admin'=>'rgba(99,102,241,0.12)','Instructor'=>'rgba(16,185,129,0.12)','Student'=>'rgba(245,158,11,0.12)'];
            $roleTextColor = ['Admin'=>'#6366f1','Instructor'=>'#10b981','Student'=>'#d97706'];
            $init = strtoupper(substr($u['name'],0,1));
        ?>
        <div class="adm-user-row">
            <div class="adm-user-av" style="background:<?= $bgColor ?>;"><?= $init ?></div>
            <div>
                <div class="adm-user-name"><?= htmlspecialchars($u['name']) ?></div>
                <div class="adm-user-email"><?= htmlspecialchars($u['email']) ?></div>
            </div>
            <span class="adm-role-badge" style="background:<?= $roleBgAlpha[$u['role']] ?? 'rgba(107,114,128,0.1)' ?>;color:<?= $roleTextColor[$u['role']] ?? '#6b7280' ?>;">
                <?= $u['role'] ?>
            </span>
        </div>
        <?php endforeach; endif; ?>
    </div>

</div>

<script>
// Live Clock
function updateClock() {
    const now = new Date();
    let h = now.getHours().toString().padStart(2,'0');
    let m = now.getMinutes().toString().padStart(2,'0');
    let s = now.getSeconds().toString().padStart(2,'0');
    const el = document.getElementById('live-clock');
    if(el) el.textContent = h + ':' + m + ':' + s;
}
updateClock();
setInterval(updateClock, 1000);

// Animate progress bars on load
window.addEventListener('load', () => {
    document.querySelectorAll('.adm-progress-fill').forEach(el => {
        const w = el.style.width;
        el.style.width = '0';
        setTimeout(() => el.style.width = w, 100);
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
