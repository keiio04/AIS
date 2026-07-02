<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/admin_auth.php';
require_once '../includes/header.php';

$db = get_db();

// Fetch logs
$query = "
    SELECT l.id, l.action, l.module, l.description, l.created_at, u.name as user_name, u.role as user_role
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
                    <th style="width: 180px;">Timestamp</th>
                    <th style="width: 200px;">User</th>
                    <th style="width: 120px;">Role</th>
                    <th style="width: 150px;">Module</th>
                    <th style="width: 150px;">Action</th>
                    <th>Description</th>
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
                    <td><span class="badge badge-neutral"><?= htmlspecialchars($log['user_role'] ?? 'Unknown') ?></span></td>
                    <td><?= htmlspecialchars($log['module'] ?? '-') ?></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($log['action']) ?></span></td>
                    <td class="text-sm"><?= htmlspecialchars($log['description'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($logs) === 0): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted" style="padding: 2rem;">No activity logs found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
