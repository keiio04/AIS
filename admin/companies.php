<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/admin_auth.php';
require_once '../includes/csrf.php';
require_once '../includes/header.php';

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify()) {
        $error = "Security token invalid or expired. Please refresh and try again.";
    } elseif ($_POST['action'] === 'delete_company') {
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
            $success = "Company \"{$row['name']}\" was deleted successfully.";
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
    min-width: 160px;
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
.adm-badge-neutral {
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
.adm-btn-danger-icon {
    background: transparent;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    padding: 4px;
    border-radius: 6px;
    transition: color 0.15s ease, background 0.15s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.adm-btn-danger-icon:hover {
    color: #ef4444;
    background: #fee2e2;
}
</style>

<div class="adm-container">

    <div class="adm-header">
        <div>
            <h1 class="adm-title">Company Entities</h1>
            <p class="adm-subtitle">Directory of all simulated accounting business profiles and associated owners.</p>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div style="background: #f0fdf4; color: #166534; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem; border: 1px solid #bbf7d0; font-size: 0.85rem;">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div style="background: #fef2f2; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem; border: 1px solid #fecaca; font-size: 0.85rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- FILTER BAR -->
    <div class="adm-filter-bar">
        <div class="adm-search-wrap">
            <i data-lucide="search" style="width:14px;height:14px; position:absolute; left:10px; top:50%; transform:translateY(-50%); color: #94a3b8;"></i>
            <input type="text" id="companySearch" class="adm-search-input" placeholder="Search by company or owner name..." onkeyup="filterCompanies()">
        </div>
        <select id="typeFilter" class="adm-select-filter" onchange="filterCompanies()">
            <option value="">All Business Types</option>
            <option value="Service">Service</option>
            <option value="Merchandising">Merchandising</option>
            <option value="Manufacturing">Manufacturing</option>
        </select>
    </div>

    <!-- TABLE CARD -->
    <div class="adm-table-card">
        <table class="adm-clean-table">
            <thead>
                <tr>
                    <th>Company Name</th>
                    <th>Account Owner</th>
                    <th>Business Model</th>
                    <th style="text-align: center;">Journal Entries</th>
                    <th>Last Active</th>
                    <th>Registered</th>
                    <th style="text-align: center; width: 70px;">Action</th>
                </tr>
            </thead>
            <tbody id="companyTableBody">
                <?php foreach ($companies as $c): ?>
                <tr data-search="<?= htmlspecialchars(strtolower($c['name'] . ' ' . $c['owner_name'] . ' ' . $c['owner_email'])) ?>" data-type="<?= htmlspecialchars($c['business_type']) ?>">
                    <td style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($c['name']) ?></td>
                    <td>
                        <div style="font-weight: 500; color: #0f172a;"><?= htmlspecialchars($c['owner_name']) ?></div>
                        <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($c['owner_email']) ?> &middot; <?= htmlspecialchars($c['owner_role']) ?></div>
                    </td>
                    <td><span class="adm-badge-neutral"><?= htmlspecialchars($c['business_type']) ?></span></td>
                    <td style="text-align: center; font-weight: 600; color: #0f172a;"><?= (int)$c['entry_count'] ?></td>
                    <td style="font-size: 0.78rem; color: #64748b;">
                        <?= $c['last_activity'] ? date('M j, Y', strtotime($c['last_activity'])) : '—' ?>
                    </td>
                    <td style="font-size: 0.78rem; color: #64748b;"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                    <td style="text-align: center;">
                        <form method="POST" onsubmit="return confirm('Permanently delete \'<?= htmlspecialchars(addslashes($c['name'])) ?>\'? This will remove all associated financial records.');" style="display:inline;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete_company">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="adm-btn-danger-icon" title="Delete Company">
                                <i data-lucide="trash-2" style="width:15px;height:15px;"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($companies) === 0): ?>
                <tr><td colspan="7" style="text-align: center; color: #94a3b8; padding: 2.5rem;">No company profiles found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="noResultsRowCompany" class="hidden" style="text-align: center; color: #94a3b8; padding: 2rem;">No companies match your search.</div>
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