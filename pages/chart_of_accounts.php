<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';

$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    require_once '../includes/header.php';
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to view the chart of accounts.</div>';
    require_once '../includes/footer.php';
    exit;
}

// ------------------------------------------------------------------
// Handle Form Submissions (Add, Edit, Delete)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add' || $action === 'edit') {
        $id = $_POST['id'] ?? null;
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? 'Assets');
        $sub_category = trim($_POST['sub_category'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($action === 'add') {
            $stmt = $db->prepare("INSERT INTO accounts (company_id, code, name, category, sub_category, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssss', $company_id, $code, $name, $category, $sub_category, $description);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Create', 'Chart of Accounts', "Added account: $code - $name");
            $_SESSION['flash_success'] = "Account $code - $name added successfully.";
        } else if ($action === 'edit' && $id) {
            $stmt = $db->prepare("UPDATE accounts SET code=?, name=?, category=?, sub_category=?, description=? WHERE id=? AND company_id=?");
            $stmt->bind_param('sssssii', $code, $name, $category, $sub_category, $description, $id, $company_id);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Update', 'Chart of Accounts', "Updated account: $code - $name");
            $_SESSION['flash_success'] = "Account $code - $name updated successfully.";
        }
        header("Location: chart_of_accounts.php");
        exit;
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        // Ensure no journal entries use this account
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM journal_entry_lines WHERE account_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
        
        if ($cnt > 0) {
            $_SESSION['flash_error'] = "Cannot delete account: It is used in $cnt existing journal entry line(s).";
        } else {
            $stmt = $db->prepare("DELETE FROM accounts WHERE id = ? AND company_id = ?");
            $stmt->bind_param('ii', $id, $company_id);
            if ($stmt->execute()) {
                log_activity($db, $_SESSION['user_id'], 'Delete', 'Chart of Accounts', "Deleted account ID: $id");
                $_SESSION['flash_success'] = "Account deleted successfully.";
            }
        }
        header("Location: chart_of_accounts.php");
        exit;
    }
}

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);


// ------------------------------------------------------------------
// Fetch Customers
// ------------------------------------------------------------------
$allCustomers = [];
$stmtCustList = $db->prepare("
    SELECT c.id, c.name, c.code, c.contact_person, c.email, c.phone
    FROM customers c 
    WHERE c.company_id = ? 
    ORDER BY c.name ASC
");
if ($stmtCustList) {
    $stmtCustList->bind_param('i', $company_id);
    $stmtCustList->execute();
    $allCustomers = $stmtCustList->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ------------------------------------------------------------------
// Fetch Suppliers
// ------------------------------------------------------------------
$allSuppliers = [];
$stmtSuppList = $db->prepare("
    SELECT s.id, s.name, s.code, s.contact_person, s.email, s.phone
    FROM suppliers s 
    WHERE s.company_id = ? 
    ORDER BY s.name ASC
");
if ($stmtSuppList) {
    $stmtSuppList->bind_param('i', $company_id);
    $stmtSuppList->execute();
    $allSuppliers = $stmtSuppList->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Helper functions for strict control account classification
function coa_is_trade_receivable($accountName, $accountCode) {
    $n = strtolower(trim($accountName));
    return ($n === 'accounts receivable' || $n === 'trade receivables' || $accountCode === '1-110');
}

function coa_is_trade_payable($accountName, $accountCode) {
    $n = strtolower(trim($accountName));
    return ($n === 'accounts payable' || $n === 'trade payables' || $accountCode === '2-100');
}

// ------------------------------------------------------------------
// Fetch All GL Accounts
// ------------------------------------------------------------------
$query = "
    SELECT 
        a.id, a.company_id, a.code, a.name, a.category, a.sub_category, a.description
    FROM accounts a
    WHERE a.company_id = ?
    ORDER BY a.code ASC
";
$stmt = $db->prepare($query);
$stmt->bind_param('i', $company_id);
$stmt->execute();
$rawAccounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Search Query & Category Filter
$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');

$accounts = [];
$totalGLCount = count($rawAccounts);
$autoExpandAccIds = [];

foreach ($rawAccounts as $acc) {
    // Determine linked subsidiary records
    $subsidiaryList = [];
    $subsidiaryType = null;
    $subsidiaryPage = null;

    if (coa_is_trade_receivable($acc['name'], $acc['code'])) {
        $subsidiaryList = $allCustomers;
        $subsidiaryType = 'Customer';
        $subsidiaryPage = 'customers.php';
    } elseif (coa_is_trade_payable($acc['name'], $acc['code'])) {
        $subsidiaryList = $allSuppliers;
        $subsidiaryType = 'Supplier';
        $subsidiaryPage = 'suppliers.php';
    }

    $acc['subsidiary_list'] = $subsidiaryList;
    $acc['subsidiary_type'] = $subsidiaryType;
    $acc['subsidiary_page'] = $subsidiaryPage;

    // Apply Filters (Search & Category)
    if ($categoryFilter && strcasecmp($acc['category'], $categoryFilter) !== 0) {
        continue;
    }

    if ($search !== '') {
        $accMatch = (stripos($acc['code'], $search) !== false || stripos($acc['name'], $search) !== false || stripos($acc['description'] ?? '', $search) !== false);

        $matchingSubs = [];
        if (!empty($subsidiaryList)) {
            foreach ($subsidiaryList as $sub) {
                if (stripos($sub['name'], $search) !== false || stripos($sub['code'] ?? '', $search) !== false) {
                    $matchingSubs[] = $sub;
                }
            }
        }

        if ($accMatch || !empty($matchingSubs)) {
            if (!empty($matchingSubs)) {
                $autoExpandAccIds[] = $acc['id'];
                $acc['matching_sub_ids'] = array_column($matchingSubs, 'id');
            }
            $accounts[] = $acc;
        }
    } else {
        $accounts[] = $acc;
    }
}

// Group accounts: Category -> List
$categoryOrder = ['Assets', 'Liabilities', 'Equity', 'Revenue', 'Expenses'];
$grouped_accounts = [];
$categoryTotals = [];

foreach ($accounts as $acc) {
    $cat = $acc['category'] ?: 'Other';
    $grouped_accounts[$cat][] = $acc;
    
    if (!isset($categoryTotals[$cat])) {
        $categoryTotals[$cat] = ['count' => 0];
    }
    $categoryTotals[$cat]['count']++;
}

// Category Badge Color Schemes
$categoryStyles = [
    'Assets' => ['bg' => '#eff6ff', 'color' => '#1d4ed8', 'border' => '#bfdbfe', 'icon' => 'wallet'],
    'Liabilities' => ['bg' => '#fff7ed', 'color' => '#c2410c', 'border' => '#fed7aa', 'icon' => 'credit-card'],
    'Equity' => ['bg' => '#f5f3ff', 'color' => '#6d28d9', 'border' => '#ddd6fe', 'icon' => 'pie-chart'],
    'Revenue' => ['bg' => '#f0fdf4', 'color' => '#15803d', 'border' => '#bbf7d0', 'icon' => 'trending-up'],
    'Expenses' => ['bg' => '#fef2f2', 'color' => '#b91c1c', 'border' => '#fecaca', 'icon' => 'receipt']
];

require_once '../includes/header.php';
?>

<style>
/* ============================================================
   CHART OF ACCOUNTS ERP HIERARCHY STYLES
   ============================================================ */
.coa-wrapper {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    margin-bottom: 3rem;
}

/* Category Section */
.coa-category-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
}
.coa-category-card:hover {
    box-shadow: var(--shadow-md);
    border-color: var(--border-strong);
}

.coa-category-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.95rem 1.35rem;
    background: #f8fafc;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    user-select: none;
    transition: background-color 0.15s ease;
}
[data-theme='dark'] .coa-category-header {
    background: rgba(255, 255, 255, 0.03);
}
.coa-category-header:hover {
    background: #f1f5f9;
}
[data-theme='dark'] .coa-category-header:hover {
    background: rgba(255, 255, 255, 0.06);
}

.coa-category-title-wrap {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.coa-cat-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.25rem 0.65rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.coa-group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.55rem 1.35rem 0.55rem 2rem;
    background: #fdfdfd;
    border-top: 1px solid #f1f5f9;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-secondary);
    letter-spacing: 0.02em;
}
[data-theme='dark'] .coa-group-header {
    background: rgba(255, 255, 255, 0.015);
    border-color: var(--border-color);
}

/* GL Table */
.coa-table {
    width: 100%;
    border-collapse: collapse;
}

.coa-table th {
    text-align: left;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    padding: 0.65rem 1.25rem;
    border-bottom: 1px solid var(--border-color);
    background: #fafafa;
}
[data-theme='dark'] .coa-table th {
    background: rgba(255, 255, 255, 0.02);
}

.coa-row-gl {
    border-bottom: 1px solid var(--border-color);
    transition: background-color 0.15s ease;
    height: 52px;
}
.coa-row-gl:hover {
    background-color: #f8fafc;
}
[data-theme='dark'] .coa-row-gl:hover {
    background-color: rgba(255, 255, 255, 0.035);
}

.coa-row-gl td {
    padding: 0 1.25rem;
    vertical-align: middle;
    font-size: 0.875rem;
}

.coa-code-pill {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.825rem;
    font-weight: 700;
    color: var(--text-primary);
    background: #f1f5f9;
    padding: 0.2rem 0.55rem;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-color);
    display: inline-block;
}
[data-theme='dark'] .coa-code-pill {
    background: rgba(255, 255, 255, 0.06);
}

.coa-name-cell {
    display: flex;
    align-items: center;
    gap: 0.55rem;
}

.coa-chevron-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 4px;
    border: 1px solid var(--border-color);
    background: var(--bg-secondary);
    color: var(--text-secondary);
    transition: all 0.2s ease;
    cursor: pointer;
}
.coa-chevron-btn:hover {
    background: #e2e8f0;
    color: var(--text-primary);
}
.coa-chevron-btn.expanded {
    transform: rotate(90deg);
    background: var(--primary-color);
    color: #ffffff;
    border-color: var(--primary-color);
}

.coa-subsidiary-pill {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.15rem 0.45rem;
    border-radius: 9999px;
    background: #f1f5f9;
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}
[data-theme='dark'] .coa-subsidiary-pill {
    background: rgba(255, 255, 255, 0.06);
}

/* Subsidiary Expanded Row */
.coa-subsidiary-container-row {
    background-color: #fafbfc;
    border-bottom: 1px solid var(--border-color);
}
[data-theme='dark'] .coa-subsidiary-container-row {
    background-color: rgba(255, 255, 255, 0.015);
}

.coa-subsidiary-panel {
    padding: 0.85rem 1.5rem 1rem 3.5rem;
    position: relative;
}

/* Tree branch connector line */
.coa-subsidiary-panel::before {
    content: '';
    position: absolute;
    left: 2.25rem;
    top: 0;
    bottom: 1rem;
    width: 2px;
    background: #cbd5e1;
}
[data-theme='dark'] .coa-subsidiary-panel::before {
    background: rgba(255, 255, 255, 0.12);
}

.coa-sub-table {
    width: 100%;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    background: var(--bg-secondary);
    overflow: hidden;
    font-size: 0.825rem;
    border-collapse: collapse;
}

.coa-sub-table th {
    text-align: left;
    background: #f8fafc;
    padding: 0.5rem 0.85rem;
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    border-bottom: 1px solid var(--border-color);
}
[data-theme='dark'] .coa-sub-table th {
    background: rgba(255, 255, 255, 0.04);
}

.coa-sub-table td {
    padding: 0.55rem 0.85rem;
    border-bottom: 1px solid #f1f5f9;
    color: var(--text-secondary);
}
[data-theme='dark'] .coa-sub-table td {
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
}
.coa-sub-table tr:last-child td {
    border-bottom: none;
}
.coa-sub-table tr:hover td {
    background: #f8fafc;
}
[data-theme='dark'] .coa-sub-table tr:hover td {
    background: rgba(255, 255, 255, 0.03);
}

.coa-sub-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-weight: 700;
    color: var(--primary-color);
    font-size: 0.775rem;
}



/* Category Filter Tabs */
.coa-cat-tabs {
    display: flex;
    gap: 0.4rem;
    flex-wrap: wrap;
}
.coa-tab-btn {
    padding: 0.4rem 0.85rem;
    border-radius: var(--radius-md);
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-secondary);
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    text-decoration: none;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
.coa-tab-btn:hover {
    background: #f1f5f9;
    color: var(--text-primary);
}
.coa-tab-btn.active {
    background: var(--primary-color);
    color: #ffffff;
    border-color: var(--primary-color);
}
</style>

<!-- Top Notification Alerts -->
<?php if ($flash_success): ?>
    <div style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; padding: 0.85rem 1.25rem; border-radius: var(--radius-lg); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.6rem;">
        <i data-lucide="check-circle-2" style="width:18px;height:18px;"></i>
        <span><?= htmlspecialchars($flash_success) ?></span>
    </div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; padding: 0.85rem 1.25rem; border-radius: var(--radius-lg); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.6rem;">
        <i data-lucide="alert-circle" style="width:18px;height:18px;"></i>
        <span><?= htmlspecialchars($flash_error) ?></span>
    </div>
<?php endif; ?>

<!-- Controls Bar: Search & Actions -->
<div class="card" style="padding: 0.75rem 1.25rem; margin-bottom: 1.25rem;">
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <!-- Search Form -->
        <form method="GET" style="position: relative; flex: 1; min-width: 260px; max-width: 380px;">
            <?php if ($categoryFilter): ?>
                <input type="hidden" name="category" value="<?= htmlspecialchars($categoryFilter) ?>">
            <?php endif; ?>
            <i data-lucide="search" style="position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); width:15px; height:15px;"></i>
            <input type="text" name="search" class="form-control" placeholder="Search by account code, name, or customer/supplier..." value="<?= htmlspecialchars($search) ?>" style="padding-left: 2.35rem; padding-right: <?= $search ? '2.2rem' : '0.75rem' ?>;">
            <?php if ($search): ?>
                <a href="chart_of_accounts.php<?= $categoryFilter ? '?category='.urlencode($categoryFilter) : '' ?>" style="position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); text-decoration: none;">
                    <i data-lucide="x" style="width:14px;height:14px;"></i>
                </a>
            <?php endif; ?>
        </form>

        <div class="flex gap-2">
            <button class="btn btn-primary" onclick="openModal()">
                <i data-lucide="plus" style="width:16px;height:16px;"></i> Add Account
            </button>
        </div>
    </div>
</div>

<!-- Main Tree Table Wrapper -->
<div class="coa-wrapper">
    <?php if (empty($accounts)): ?>
        <div class="card text-center" style="padding: 3rem 1rem;">
            <i data-lucide="folder-search" style="width: 48px; height: 48px; color: var(--text-muted); margin: 0 auto 1rem auto;"></i>
            <h3 style="font-size: 1.1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">No Accounts Found</h3>
            <p class="text-muted text-sm" style="max-width: 420px; margin: 0 auto 1.25rem auto;">
                <?= $search ? "No accounts, customers, or suppliers match your search query '".htmlspecialchars($search)."'. Try clearing your search." : "No accounts currently exist in this category." ?>
            </p>
            <?php if ($search || $categoryFilter): ?>
                <div>
                    <a href="chart_of_accounts.php" class="btn btn-secondary">Clear Filters</a>
                </div>
            <?php else: ?>
                <div>
                    <button class="btn btn-primary" onclick="openModal()">
                        <i data-lucide="plus" style="width:15px;height:15px;"></i> Add First Account
                    </button>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($categoryOrder as $catName): 
            if (!isset($grouped_accounts[$catName])) continue;
            $catAccounts = $grouped_accounts[$catName];
        ?>
        <div class="coa-category-card">
            <!-- 1. CATEGORY HEADER -->
            <div class="coa-category-header" onclick="toggleCategorySection('cat-sec-<?= htmlspecialchars($catName) ?>')">
                <div class="coa-category-title-wrap">
                    <span style="font-weight: 700; font-size: 0.95rem; color: #1e293b; letter-spacing: 0.05em; text-transform: uppercase;">
                        <?= htmlspecialchars($catName) ?>
                    </span>
                </div>
                <div class="flex items-center gap-3">
                    <i data-lucide="chevron-down" id="cat-chevron-<?= htmlspecialchars($catName) ?>" style="width:16px;height:16px;color:var(--text-muted);transition:transform 0.2s;"></i>
                </div>
            </div>

            <!-- Category Body Content -->
            <div id="cat-sec-<?= htmlspecialchars($catName) ?>" style="display: block;">
                <!-- GL ACCOUNTS TABLE -->
                <div style="overflow-x: auto;">
                    <table class="coa-table">
                            <thead>
                                <tr>
                                    <th style="width: 180px; padding-left: 1.5rem;">Account Code</th>
                                    <th style="padding-left: calc(1.25rem + 22px + 0.55rem);">Account Name</th>
                                    <th style="width: 120px; text-align: center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($catAccounts as $acc):
                                    $hasSubsidiaries = !empty($acc['subsidiary_list']);
                                    $isAutoExpanded = in_array($acc['id'], $autoExpandAccIds);
                                    $subsidiaryCount = count($acc['subsidiary_list']);
                                    $accJson = htmlspecialchars(json_encode($acc), ENT_QUOTES, 'UTF-8');
                                ?>
                                <!-- GL ACCOUNT ROW -->
                                <tr class="coa-row-gl" id="acc-row-<?= $acc['id'] ?>">
                                    <td style="padding-left: 1.5rem;">
                                        <span class="coa-code-pill"><?= htmlspecialchars($acc['code']) ?></span>
                                    </td>
                                    <td>
                                        <div class="coa-name-cell">
                                            <?php if ($hasSubsidiaries): ?>
                                                <button type="button" class="coa-chevron-btn <?= $isAutoExpanded ? 'expanded' : '' ?>" id="chev-btn-<?= $acc['id'] ?>" onclick="toggleSubsidiaryRow(event, <?= $acc['id'] ?>)" title="Toggle Subsidiary Records">
                                                    <i data-lucide="chevron-right" style="width:13px;height:13px;"></i>
                                                </button>
                                            <?php else: ?>
                                                <span style="width: 22px; height: 22px; display: inline-flex; flex-shrink: 0;"></span>
                                            <?php endif; ?>
                                            <span style="font-weight: 600; color: var(--text-primary);">
                                                <?= htmlspecialchars($acc['name']) ?>
                                            </span>
                                            <?php if ($hasSubsidiaries): ?>
                                                <span class="coa-subsidiary-pill">
                                                    <i data-lucide="<?= $acc['subsidiary_type'] === 'Customer' ? 'user' : 'building-2' ?>" style="width:11px;height:11px;"></i>
                                                    <?= $subsidiaryCount ?> <?= $acc['subsidiary_type'] . ($subsidiaryCount > 1 ? 's' : '') ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex items-center justify-center gap-1" onclick="event.stopPropagation();">
                                            <!-- Edit Modal -->
                                            <button type="button" class="icon-btn" onclick='openModal(<?= $accJson ?>)' title="Edit Account">
                                                <i data-lucide="edit-2" style="width:15px;height:15px;"></i>
                                            </button>
                                            <!-- Delete Form -->
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete account <?= htmlspecialchars(addslashes($acc['code'])) ?> - <?= htmlspecialchars(addslashes($acc['name'])) ?>?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $acc['id'] ?>">
                                                <button type="submit" class="icon-btn text-danger" title="Delete Account">
                                                    <i data-lucide="trash-2" style="width:15px;height:15px;"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>

                                <!-- SUBSIDIARY EXPANDED ROW -->
                                <?php if ($hasSubsidiaries): ?>
                                <tr class="coa-subsidiary-container-row" id="sub-row-<?= $acc['id'] ?>" style="display: <?= $isAutoExpanded ? 'table-row' : 'none' ?>;">
                                    <td colspan="3" style="padding: 0;">
                                        <div class="coa-subsidiary-panel">
                                            <table class="coa-sub-table">
                                                <tbody>
                                                    <?php foreach ($acc['subsidiary_list'] as $sub):
                                                        $subCode = $sub['code'] ?: sprintf(($acc['subsidiary_type'] === 'Customer' ? 'CUST-%04d' : 'SUPP-%04d'), $sub['id']);
                                                        $isHighlight = isset($acc['matching_sub_ids']) && in_array($sub['id'], $acc['matching_sub_ids']);
                                                    ?>
                                                    <tr style="<?= $isHighlight ? 'background-color: #fef9c3 !important;' : '' ?>">
                                                        <td style="width: 25%;">
                                                            <span class="coa-sub-code">[<?= htmlspecialchars($subCode) ?>]</span>
                                                        </td>
                                                        <td style="width: 55%;">
                                                            <div class="flex items-center gap-2">
                                                                <i data-lucide="<?= $acc['subsidiary_type'] === 'Customer' ? 'user' : 'building-2' ?>" style="width:13px;height:13px;color:#94a3b8;"></i>
                                                                <span style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($sub['name']) ?></span>
                                                                <span style="font-size: 0.7rem; color: #94a3b8;">(<?= $acc['subsidiary_type'] ?>)</span>
                                                            </div>
                                                        </td>
                                                        <td style="width: 20%; text-align: center;">
                                                            <a href="<?= BASE_URL ?>pages/<?= $acc['subsidiary_page'] ?>?search=<?= urlencode($sub['name']) ?>" class="btn btn-secondary" style="font-size: 0.72rem; padding: 0.25rem 0.55rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.25rem;">
                                                                <span>View Record</span>
                                                                <i data-lucide="external-link" style="width:12px;height:12px;"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>



<!-- ============================================================
     6. ADD / EDIT ACCOUNT MODAL (Preserved Existing Backend)
     ============================================================ -->
<div id="accModal" class="modal-overlay hidden" style="display: none;">
    <div class="modal" style="max-width: 560px;">
        <div class="modal-header">
            <div>
                <h2 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary);" id="modalTitle">New Account</h2>
                <p class="text-xs text-muted mt-1" id="modalDesc">Fill in the details to create a new general ledger account.</p>
            </div>
            <button type="button" class="icon-btn" onclick="closeModal()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="acc-form" method="POST">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="accId" value="">
                
                <div class="flex gap-4">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Account Code <span class="text-danger">*</span></label>
                        <input type="text" name="code" id="accCode" class="form-control" placeholder="e.g., 1-100" required>
                    </div>
                    <div class="form-group" style="flex: 2;">
                        <label class="form-label">Account Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="accName" class="form-control" required placeholder="e.g., Cash on Hand">
                    </div>
                </div>
                
                <div class="flex gap-4">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Category <span class="text-danger">*</span></label>
                        <select name="category" id="accCat" class="form-control">
                            <option value="Assets">Assets</option>
                            <option value="Liabilities">Liabilities</option>
                            <option value="Equity">Equity</option>
                            <option value="Revenue">Revenue</option>
                            <option value="Expenses">Expenses</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Sub-Category</label>
                        <select name="sub_category" id="accSub" class="form-control">
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" id="accDesc" class="form-control" placeholder="Optional notes regarding account usage">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="acc-form" class="btn btn-primary">Save Account</button>
        </div>
    </div>
</div>

<!-- ============================================================
     JAVASCRIPT LOGIC
     ============================================================ -->
<script>
// Dynamic category to sub-category mappings
const categoryMap = {
    'Assets': ['Current Assets', 'Non-Current Assets'],
    'Liabilities': ['Current Liabilities', 'Non-Current Liabilities'],
    'Equity': ["Owner's Capital", 'Withdrawals', 'Retained Earnings'],
    'Revenue': ['Sales Revenue', 'Service Revenue'],
    'Expenses': ['Cost of Goods Sold', 'Operating Expenses', 'Other Expenses']
};

const codeGuides = {
    'Assets': 'Format: 1-XXX (e.g., 1-150)',
    'Liabilities': 'Format: 2-XXX (e.g., 2-100)',
    'Equity': 'Format: 3-XXX (e.g., 3-100)',
    'Revenue': 'Format: 4-XXX (e.g., 4-100)',
    'Expenses': 'Format: 5-XXX (e.g., 5-100)'
};

const prefixMap = { 
    'Assets': '1-', 
    'Liabilities': '2-', 
    'Equity': '3-', 
    'Revenue': '4-', 
    'Expenses': '5-' 
};

function updateSubCategories(category, selectedSub = '') {
    const subSelect = document.getElementById('accSub');
    subSelect.innerHTML = '';
    
    if (categoryMap[category]) {
        categoryMap[category].forEach(sub => {
            const option = document.createElement('option');
            option.value = sub;
            option.innerText = sub;
            if (sub === selectedSub) option.selected = true;
            subSelect.appendChild(option);
        });
    }
    
    document.getElementById('modalDesc').innerText = `Fill in the details. [${codeGuides[category] || ''}]`;
}

// Watch category select changes
document.getElementById('accCat').addEventListener('change', function() {
    updateSubCategories(this.value);
    const codeInput = document.getElementById('accCode');
    if (document.getElementById('formAction').value === 'add') {
        codeInput.value = prefixMap[this.value] || '';
    }
});

// Modal open/close
function openModal(acc = null) {
    const modal = document.getElementById('accModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    
    if (acc) {
        document.getElementById('modalTitle').innerText = 'Edit Account';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('accId').value = acc.id;
        document.getElementById('accCode').value = acc.code;
        document.getElementById('accName').value = acc.name;
        document.getElementById('accCat').value = acc.category;
        document.getElementById('accDesc').value = acc.description || '';
        
        updateSubCategories(acc.category, acc.sub_category);
    } else {
        document.getElementById('modalTitle').innerText = 'New Account';
        document.getElementById('formAction').value = 'add';
        document.getElementById('accId').value = '';
        document.getElementById('accCat').value = 'Assets';
        document.getElementById('accCode').value = '1-'; 
        document.getElementById('accName').value = '';
        document.getElementById('accDesc').value = '';
        
        updateSubCategories('Assets');
    }
}

function closeModal() {
    const modal = document.getElementById('accModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

document.getElementById('accModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Category section collapse/expand
function toggleCategorySection(sectionId) {
    const sec = document.getElementById(sectionId);
    const catName = sectionId.replace('cat-sec-', '');
    const chev = document.getElementById('cat-chevron-' + catName);
    
    if (sec.style.display === 'none') {
        sec.style.display = 'block';
        if (chev) chev.style.transform = 'rotate(0deg)';
    } else {
        sec.style.display = 'none';
        if (chev) chev.style.transform = 'rotate(-90deg)';
    }
}



// Subsidiary row toggle
function toggleSubsidiaryRow(event, accId) {
    if (event) event.stopPropagation();
    const row = document.getElementById('sub-row-' + accId);
    const btn = document.getElementById('chev-btn-' + accId);
    if (!row) return;

    if (row.style.display === 'none' || row.style.display === '') {
        row.style.display = 'table-row';
        if (btn) btn.classList.add('expanded');
    } else {
        row.style.display = 'none';
        if (btn) btn.classList.remove('expanded');
    }
}

// Close modal on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

// Re-initialize Lucide Icons after render
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>

<?php require_once '../includes/footer.php'; ?>