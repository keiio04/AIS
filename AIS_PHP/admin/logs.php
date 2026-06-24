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

// Fetch logs
$query = "
    SELECT l.id, l.action, l.created_at, u.name as user_name
    FROM activity_logs l
    JOIN users u ON l.user_id = u.id
    ORDER BY l.created_at DESC
    LIMIT 500
";
$res = $db->query($query);
$logs = $res->fetch_all(MYSQLI_ASSOC);

?>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Activity Logs</h1>
        <p class="page-subtitle">View system audit trails and user activities.</p>
    </div>
    <div>
        <a href="users.php" class="btn btn-secondary">
            <i data-lucide="users" style="width:15px;height:15px;"></i> Manage Users
        </a>
    </div>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 200px;">Timestamp</th>
                    <th style="width: 200px;">User</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="color: var(--text-muted); font-size: 0.8125rem;">
                        <?= date('M j, Y h:i A', strtotime($log['created_at'])) ?>
                    </td>
                    <td style="font-weight: 500;">
                        <div class="flex items-center gap-2">
                            <div style="width: 24px; height: 24px; border-radius: 50%; background: var(--bg-tertiary); display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 600; color: var(--primary-color);">
                                <?= strtoupper(substr($log['user_name'], 0, 1)) ?>
                            </div>
                            <?= htmlspecialchars($log['user_name']) ?>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($log['action']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($logs) === 0): ?>
                <tr>
                    <td colspan="3" class="text-center text-muted" style="padding: 2rem;">No activity logs found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
