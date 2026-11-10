<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/admin_auth.php';
require_once '../includes/header.php';

$db = get_db();

// Fetch Security & Authentication events (Login, Logout, Failed Login, Auto-Logout, Admin changes).
$query = "
    SELECT l.id, l.action, l.module, l.description, l.created_at, 
           COALESCE(u.name, 'Unauthenticated / System') as user_name, 
           COALESCE(u.role, 'Security') as user_role
    FROM activity_logs l
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.module IN ('Authentication', 'Session', 'Security', 'Admin', 'User Management', 'Audit', 'Backup')
       OR l.action IN ('Login', 'Google Login', 'Logout', 'Failed Login', 'Auto-Logout', 'Lockout', 'Backup Export', 'Export Logs')
    ORDER BY l.created_at DESC
    LIMIT 500
";
$res = $db->query($query);
$logs = $res->fetch_all(MYSQLI_ASSOC);
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
    transition: border-color 0.15s ease;
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
    min-width: 140px;
}
.adm-table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
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
.adm-badge-login {
    display: inline-flex;
    align-items: center;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 6px;
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}
.adm-badge-warn {
    display: inline-flex;
    align-items: center;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 6px;
    background: #fffbeb;
    color: #b45309;
    border: 1px solid #fde68a;
}
.adm-badge-danger {
    display: inline-flex;
    align-items: center;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 6px;
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}
.adm-badge-admin {
    display: inline-flex;
    align-items: center;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 6px;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}
.adm-btn-action {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 0.85rem;
    font-size: 0.82rem;
    font-weight: 600;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.15s ease;
}
.adm-btn-action-outline {
    background: #ffffff;
    color: #334155;
    border: 1px solid #cbd5e1;
}
.adm-btn-action-outline:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
.adm-btn-action-primary {
    background: #0f172a;
    color: #ffffff;
    border: 1px solid #0f172a;
}
.adm-btn-action-primary:hover {
    background: #1e293b;
}
.adm-avatar-mini {
    width: 26px;
    height: 26px;
    border-radius: 6px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    color: #334155;
    font-size: 0.72rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
</style>

<div class="adm-container">

    <div class="adm-header">
        <div>
            <h1 class="adm-title">Security & Audit Logs</h1>
            <p class="adm-subtitle">Chronological record of authentication events, auto-logouts, security alerts, and administrative changes.</p>
        </div>
        <div style="display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap;">
            <a href="backup.php?type=csv" class="adm-btn-action adm-btn-action-outline" title="Export audit trail in CSV format">
                <i data-lucide="download" style="width: 14px; height: 14px;"></i> Export CSV
            </a>
            <a href="backup.php?type=sql" class="adm-btn-action adm-btn-action-primary" title="Export full database backup">
                <i data-lucide="database" style="width: 14px; height: 14px;"></i> Download SQL Backup
            </a>
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="adm-filter-bar">
        <div class="adm-search-wrap">
            <i data-lucide="search" style="width:14px;height:14px; position:absolute; left:10px; top:50%; transform:translateY(-50%); color: #94a3b8;"></i>
            <input type="text" id="logSearch" class="adm-search-input" placeholder="Search by user, event, or details..." onkeyup="filterLogs()">
        </div>
        <select id="roleFilterLog" class="adm-select-filter" onchange="filterLogs()">
            <option value="">All Roles</option>
            <option value="Admin">Admin</option>
            <option value="Instructor">Instructor</option>
            <option value="Student">Student</option>
            <option value="Security">Security / System</option>
        </select>
    </div>

    <!-- LOGS TABLE -->
    <div class="adm-table-card">
        <table class="adm-clean-table">
            <thead>
                <tr>
                    <th style="width: 180px;">Timestamp</th>
                    <th style="width: 220px;">User Account</th>
                    <th style="width: 120px;">Role</th>
                    <th style="width: 160px;">Event Action</th>
                    <th>Audit Details</th>
                </tr>
            </thead>
            <tbody id="logTableBody">
                <?php foreach ($logs as $log): 
                    $act = $log['action'];
                    $badgeClass = 'adm-badge-tag';
                    if ($act === 'Login' || $act === 'Google Login') {
                        $badgeClass = 'adm-badge-login';
                    } elseif ($act === 'Auto-Logout') {
                        $badgeClass = 'adm-badge-warn';
                    } elseif ($act === 'Failed Login' || $act === 'Lockout') {
                        $badgeClass = 'adm-badge-danger';
                    } elseif ($act === 'Create' || $act === 'Update' || $act === 'Delete' || $act === 'Backup Export') {
                        $badgeClass = 'adm-badge-admin';
                    }
                ?>
                <tr data-search="<?= htmlspecialchars(strtolower($log['user_name'] . ' ' . $log['action'] . ' ' . ($log['description'] ?? ''))) ?>"
                    data-role="<?= htmlspecialchars($log['user_role'] ?? '') ?>">
                    <td style="color: #64748b; font-size: 0.78rem;">
                        <?= date('M j, Y · h:i A', strtotime($log['created_at'])) ?>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 0.6rem;">
                            <div class="adm-avatar-mini">
                                <?= strtoupper(substr($log['user_name'], 0, 1)) ?>
                            </div>
                            <span style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($log['user_name']) ?></span>
                        </div>
                    </td>
                    <td><span class="adm-badge-tag"><?= htmlspecialchars($log['user_role'] ?? 'Unknown') ?></span></td>
                    <td>
                        <span class="<?= $badgeClass ?>">
                            <?= htmlspecialchars($log['action']) ?>
                        </span>
                    </td>
                    <td style="color: #64748b;"><?= htmlspecialchars($log['description'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($logs) === 0): ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: #94a3b8; padding: 2.5rem;">No audit logs recorded yet.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="noResultsRowLog" class="hidden" style="text-align: center; color: #94a3b8; padding: 2rem;">No logs match your filter criteria.</div>
    </div>

</div>

<script>
function filterLogs() {
    const q = document.getElementById('logSearch').value.toLowerCase();
    const role = document.getElementById('roleFilterLog').value;
    const rows = document.querySelectorAll('#logTableBody tr[data-search]');
    let visibleCount = 0;
    rows.forEach(row => {
        const matchesSearch = row.getAttribute('data-search').includes(q);
        const matchesRole = !role || row.getAttribute('data-role') === role;
        const show = matchesSearch && matchesRole;
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
    document.getElementById('noResultsRowLog').classList.toggle('hidden', visibleCount !== 0);
}
</script>

<?php require_once '../includes/footer.php'; ?>