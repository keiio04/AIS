<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/admin_auth.php';
require_once '../includes/header.php';

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_company') {
        $id = (int)$_POST['id'];
        $lookup = $db->prepare("SELECT c.name, u.name as owner_name FROM companies c JOIN users u ON c.user_id = u.id WHERE c.id = ?");
        $lookup->bind_param('i', $id);
        $lookup->execute();
        $row = $lookup->get_result()->fetch_assoc();

        if ($row) {
            $stmt = $db->prepare("DELETE FROM companies WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Delete', 'Company Management', "Deleted company \"{$row['name']}\" (owner: {$row['owner_name']})");
            $success = "Company deleted successfully, along with all its accounts, journal entries, and related records.";
        } else {
            $error = "Company not found.";
        }
    }
}

$res = $db->query("
    SELECT c.id, c.name, c.business_type, c.tax_registered, c.created_at,
           u.id as owner_id, u.name as owner_name, u.email as owner_email, u.role as owner_role,
           (SELECT COUNT(*) FROM journal_entries e WHERE e.company_id = c.id AND e.deleted_at IS NULL) as entry_count,
           (SELECT MAX(e.created_at) FROM journal_entries e WHERE e.company_id = c.id AND e.deleted_at IS NULL) as last_activity
    FROM companies c
    JOIN users u ON c.user_id = u.id
    ORDER BY c.created_at DESC
");
$companies = $res->fetch_all(MYSQLI_ASSOC);
?>


<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Company Management</h1>
        <p class="page-subtitle">View and manage every simulated company created across the system.</p>
    </div>
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

<div class="card" style="padding: 1rem; margin-bottom: 1rem; display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
    <div style="position: relative; flex: 1; min-width: 220px;">
        <i data-lucide="search" style="width:15px;height:15px; position:absolute; left:10px; top:50%; transform:translateY(-50%); color: var(--text-muted);"></i>
        <input type="text" id="companySearch" class="form-control" style="padding-left: 32px;" placeholder="Search by company or owner..." onkeyup="filterCompanies()">
    </div>
    <select id="typeFilter" class="form-control" style="max-width: 200px;" onchange="filterCompanies()">
        <option value="">All Business Types</option>
        <option value="Service">Service</option>
        <option value="Merchandising">Merchandising</option>
        <option value="Manufacturing">Manufacturing</option>
    </select>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Company</th>
                    <th>Owner</th>
                    <th>Type</th>
                    <th class="text-center">Entries</th>
                    <th>Last Activity</th>
                    <th>Created</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody id="companyTableBody">
                <?php foreach ($companies as $c): ?>
                <tr data-search="<?= htmlspecialchars(strtolower($c['name'] . ' ' . $c['owner_name'] . ' ' . $c['owner_email'])) ?>" data-type="<?= htmlspecialchars($c['business_type']) ?>">
                    <td style="font-weight: 500;"><?= htmlspecialchars($c['name']) ?></td>
                    <td>
                        <div style="font-weight: 500;"><?= htmlspecialchars($c['owner_name']) ?></div>
                        <div class="text-muted" style="font-size: 0.78rem;"><?= htmlspecialchars($c['owner_email']) ?> · <?= htmlspecialchars($c['owner_role']) ?></div>
                    </td>
                    <td><span class="badge badge-neutral"><?= htmlspecialchars($c['business_type']) ?></span></td>
                    <td class="text-center"><?= (int)$c['entry_count'] ?></td>
                    <td style="font-size: 0.8125rem; color: var(--text-muted);">
                        <?= $c['last_activity'] ? date('M j, Y', strtotime($c['last_activity'])) : '—' ?>
                    </td>
                    <td style="font-size: 0.8125rem; color: var(--text-muted);"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                    <td>
                        <div class="flex justify-center gap-2">
                            <form method="POST" onsubmit="return confirm('Permanently delete \'<?= htmlspecialchars(addslashes($c['name'])) ?>\'? This will remove ALL its accounts, journal entries, and records. This cannot be undone.');" style="display:inline;">
                                <input type="hidden" name="action" value="delete_company">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="icon-btn text-danger" title="Delete Company">
                                    <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($companies) === 0): ?>
                <tr><td colspan="7" class="text-center text-muted" style="padding: 2rem;">No companies found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="noResultsRowCompany" class="text-center text-muted hidden" style="padding: 2rem;">No companies match your search.</div>
    </div>
</div>

<script>
function filterCompanies() {
    const q = document.getElementById('companySearch').value.toLowerCase();
    const type = document.getElementById('typeFilter').value;
    const rows = document.querySelectorAll('#companyTableBody tr[data-search]');
    let visibleCount = 0;
    rows.forEach(row => {
        const matchesSearch = row.getAttribute('data-search').includes(q);
        const matchesType = !type || row.getAttribute('data-type') === type;
        const show = matchesSearch && matchesType;
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
    document.getElementById('noResultsRowCompany').classList.toggle('hidden', visibleCount !== 0);
}
</script>

<?php require_once '../includes/footer.php'; ?>