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

// Distinct modules for the filter dropdown
$modules = [];
foreach ($logs as $log) {
    if (!empty($log['module']) && !in_array($log['module'], $modules)) {
        $modules[] = $log['module'];
    }
}
sort($modules);
?>


<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Activity Logs</h1>
        <p class="page-subtitle">View system audit trails and user activities. Showing the most recent 500 entries.</p>
    </div>
    <div>
        <a href="users.php" class="btn btn-secondary">
            <i data-lucide="users" style="width:15px;height:15px;"></i> Manage Users
        </a>
    </div>
</div>

<div class="card" style="padding: 1rem; margin-bottom: 1rem; display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
    <div style="position: relative; flex: 1; min-width: 220px;">
        <i data-lucide="search" style="width:15px;height:15px; position:absolute; left:10px; top:50%; transform:translateY(-50%); color: var(--text-muted);"></i>
        <input type="text" id="logSearch" class="form-control" style="padding-left: 32px;" placeholder="Search by user, action, or description..." onkeyup="filterLogs()">
    </div>
    <select id="moduleFilter" class="form-control" style="max-width: 200px;" onchange="filterLogs()">
        <option value="">All Modules</option>
        <?php foreach ($modules as $m): ?>
        <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="roleFilterLog" class="form-control" style="max-width: 160px;" onchange="filterLogs()">
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
                    <th style="width: 180px;">Timestamp</th>
                    <th style="width: 200px;">User</th>
                    <th style="width: 120px;">Role</th>
                    <th style="width: 150px;">Module</th>
                    <th style="width: 150px;">Action</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody id="logTableBody">
                <?php foreach ($logs as $log): ?>
                <tr data-search="<?= htmlspecialchars(strtolower($log['user_name'] . ' ' . $log['action'] . ' ' . ($log['description'] ?? ''))) ?>"
                    data-module="<?= htmlspecialchars($log['module'] ?? '') ?>"
                    data-role="<?= htmlspecialchars($log['user_role'] ?? '') ?>">
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
        <div id="noResultsRowLog" class="text-center text-muted hidden" style="padding: 2rem;">No logs match your search.</div>
    </div>
</div>

<script>
function filterLogs() {
    const q = document.getElementById('logSearch').value.toLowerCase();
    const mod = document.getElementById('moduleFilter').value;
    const role = document.getElementById('roleFilterLog').value;
    const rows = document.querySelectorAll('#logTableBody tr[data-search]');
    let visibleCount = 0;
    rows.forEach(row => {
        const matchesSearch = row.getAttribute('data-search').includes(q);
        const matchesModule = !mod || row.getAttribute('data-module') === mod;
        const matchesRole = !role || row.getAttribute('data-role') === role;
        const show = matchesSearch && matchesModule && matchesRole;
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
    document.getElementById('noResultsRowLog').classList.toggle('hidden', visibleCount !== 0);
}
</script>

<?php require_once '../includes/footer.php'; ?>