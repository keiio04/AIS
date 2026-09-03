<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';

$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to view the chart of accounts.</div>';
    require_once '../includes/footer.php';
    exit;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add' || $action === 'edit') {
        $id = $_POST['id'] ?? null;
        $code = $_POST['code'];
        $name = $_POST['name'];
        $category = $_POST['category'];
        $sub_category = $_POST['sub_category'];
        $description = $_POST['description'];

        if ($action === 'add') {
            $stmt = $db->prepare("INSERT INTO accounts (company_id, code, name, category, sub_category, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssss', $company_id, $code, $name, $category, $sub_category, $description);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Create', 'Chart of Accounts', "Added account: $code - $name");
            $success = "Account added successfully.";
        } else if ($action === 'edit' && $id) {
            $stmt = $db->prepare("UPDATE accounts SET code=?, name=?, category=?, sub_category=?, description=? WHERE id=? AND company_id=?");
            $stmt->bind_param('sssssii', $code, $name, $category, $sub_category, $description, $id, $company_id);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Update', 'Chart of Accounts', "Updated account: $code - $name");
            $success = "Account updated successfully.";
        }
        header("Location: chart_of_accounts.php");
        exit;
    }

    if ($action === 'delete') {
        $id = $_POST['id'];
        // Ensure no journal entries use this account
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM journal_entry_lines WHERE account_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
        
        if ($cnt > 0) {
            $error = "Cannot delete account: It is used in existing journal entries.";
        } else {
            $stmt = $db->prepare("DELETE FROM accounts WHERE id = ? AND company_id = ?");
            $stmt->bind_param('ii', $id, $company_id);
            if ($stmt->execute()) {
                log_activity($db, $_SESSION['user_id'], 'Delete', 'Chart of Accounts', "Deleted account ID: $id");
                $success = "Account deleted successfully.";
            }
            header("Location: chart_of_accounts.php");
            exit;
        }
    }
}

// Search Filter
$search = $_GET['search'] ?? '';
$query = "
    SELECT 
        a.*
    FROM accounts a
    WHERE a.company_id = ?
";
$params = [$company_id];
$types = "i";

if ($search) {
    $query .= " AND (a.code LIKE ? OR a.name LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

$query .= " ORDER BY a.code ASC";

$stmt = $db->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ------------------------------------------------------------------
// Subsidiary "card" entities (customers / suppliers / employees) are
// people, not rows in the accounts table -- but each one still rides
// under a control account (Accounts Receivable, Accounts Payable,
// etc.) and displays that control account's own Account Code.
// ------------------------------------------------------------------
function coa_is_receivable($accountName) {
    return preg_match('/receivable/i', $accountName) === 1;
}
function coa_is_payable($accountName) {
    return preg_match('/payable/i', $accountName) === 1;
}

// Make sure these tables exist even if the user has never opened the
// Customers / Suppliers pages yet (same defensive pattern used there).
try {
    $db->query("
        CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            contact_person VARCHAR(150) NULL,
            email VARCHAR(150) NULL,
            phone VARCHAR(50) NULL,
            address VARCHAR(255) NULL,
            tin VARCHAR(50) NULL,
            terms VARCHAR(50) NULL,
            opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
            status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customers_company (company_id)
        )
    ");
} catch (Exception $e) {}

try {
    $db->query("
        CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            contact_person VARCHAR(150) NULL,
            email VARCHAR(150) NULL,
            phone VARCHAR(50) NULL,
            address VARCHAR(255) NULL,
            tin VARCHAR(50) NULL,
            terms VARCHAR(50) NULL,
            opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
            status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_suppliers_company (company_id)
        )
    ");
} catch (Exception $e) {}

// Defensive: add the `code` column for installs where these tables
// already existed before the auto-ID feature was added (same pattern
// used in customers.php / suppliers.php).
try { $db->query("ALTER TABLE customers ADD COLUMN code VARCHAR(20) NULL AFTER company_id"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE suppliers ADD COLUMN code VARCHAR(20) NULL AFTER company_id"); } catch (Exception $e) {}

$allCustomers = [];
$stmtCustList = $db->prepare("SELECT id, name, code FROM customers WHERE company_id = ? ORDER BY name ASC");
if ($stmtCustList) {
    $stmtCustList->bind_param('i', $company_id);
    $stmtCustList->execute();
    $allCustomers = $stmtCustList->get_result()->fetch_all(MYSQLI_ASSOC);
}

$allSuppliers = [];
$stmtSuppList = $db->prepare("SELECT id, name, code FROM suppliers WHERE company_id = ? ORDER BY name ASC");
if ($stmtSuppList) {
    $stmtSuppList->bind_param('i', $company_id);
    $stmtSuppList->execute();
    $allSuppliers = $stmtSuppList->get_result()->fetch_all(MYSQLI_ASSOC);
}

require_once '../includes/header.php';
?>


<style>
    .account-row:hover {
        background-color: #f1f5f9 !important;
    }
</style>
<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Chart of Accounts</h1>
    </div>
    <div class="flex gap-2">
        <button class="btn btn-primary" onclick="openModal()">
            <i data-lucide="plus" style="width:15px;height:15px;"></i> Add Account
        </button>
    </div>
</div>

<?php if (isset($error)): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="flex items-center justify-between" style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-color);">
        <form method="GET" style="position: relative; flex: 1; max-width: 320px; display: flex;">
            <i data-lucide="search" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); width:14px; height:14px;"></i>
            <input type="text" name="search" class="form-control" placeholder="Search by code or name..." value="<?= htmlspecialchars($search) ?>" style="padding-left: 2.25rem;">
            <button type="submit" style="display:none;"></button>
        </form>
        <span class="text-sm text-muted"><?= count($accounts) ?> accounts</span>
    </div>

    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 25%; padding-left: 3rem;">Account Code</th>
                    <th style="width: 60%;">Account Name</th>
                    <th class="text-center" style="width: 15%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $grouped_accounts = [];
                foreach($accounts as $acc) {
                    $subCat = $acc['sub_category'] ?: 'Other ' . $acc['category'];
                    $grouped_accounts[$acc['category']][$subCat][] = $acc;
                }
                
                if (count($accounts) === 0): ?>
                <tr><td colspan="3" class="text-center text-secondary" style="padding: 2rem;">No accounts found.</td></tr>
                <?php else: 
                    foreach($grouped_accounts as $catName => $subGroups): 
                ?>
                <tr style="background-color: #e2e8f0;">
                    <td colspan="3" style="font-weight: 700; font-size: 0.95rem; color: #1e293b; padding-top: 0.75rem; padding-bottom: 0.75rem; padding-left: 1rem; letter-spacing: 0.05em;">
                        <?= htmlspecialchars(strtoupper($catName)) ?>
                    </td>
                </tr>
                    <?php foreach($subGroups as $subCatName => $catAccounts): ?>
                    <tr style="background-color: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                        <td colspan="3" style="font-weight: 600; font-size: 0.85rem; color: #475569; padding-top: 0.5rem; padding-bottom: 0.5rem; padding-left: 2rem;">
                            <?= htmlspecialchars($subCatName) ?>
                        </td>
                    </tr>
                    <?php foreach($catAccounts as $index => $acc): 
                        $rowBg = ($index % 2 === 0) ? '#ffffff' : '#f8fafc';
                    ?>
                    <tr class="account-row" style="color: #000; background-color: <?= $rowBg ?>; transition: background-color 0.2s;">
                        <td style="font-family: monospace; font-weight: 600; font-size: 0.875rem; padding-left: 3rem; text-align: left;"><?= htmlspecialchars($acc['code']) ?></td>
                        <td style="font-weight: 500;"><?= htmlspecialchars($acc['name']) ?></td>
                        <td>
                            <div class="flex justify-center gap-2">
                                <button class="icon-btn" onclick='openModal(<?= json_encode($acc) ?>)'><i data-lucide="edit-2" style="width:16px;height:16px;"></i></button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this account?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $acc['id'] ?>">
                                    <button type="submit" class="icon-btn text-danger"><i data-lucide="trash-2" style="width:16px;height:16px;"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php
                        // Subsidiary cards riding under this control account.
                        $subsidiaryList = [];
                        if (coa_is_receivable($acc['name'])) {
                            $subsidiaryList = $allCustomers;
                            $subsidiaryLabel = 'Customer';
                            $subsidiaryPage = 'customers.php';
                        } elseif (coa_is_payable($acc['name'])) {
                            $subsidiaryList = $allSuppliers;
                            $subsidiaryLabel = 'Supplier';
                            $subsidiaryPage = 'suppliers.php';
                        }
                    ?>
                    <?php if (!empty($subsidiaryList)): foreach ($subsidiaryList as $sub): ?>
                    <tr class="account-row" style="color: #475569; background-color: #fafbfc;">
                        <td style="font-family: monospace; font-weight: 500; font-size: 0.8rem; padding-left: 4.5rem; text-align: left;">
                            <?= htmlspecialchars($acc['code']) ?>
                        </td>
                        <td style="font-size: 0.85rem;">
                            <i data-lucide="user" style="width:12px;height:12px; vertical-align:middle; margin-right:4px; color:#94a3b8;"></i>
                            <?= htmlspecialchars($sub['name']) ?>
                            <?php if (!empty($sub['code'])): ?>
                            <span style="font-family: monospace; font-size: 0.7rem; font-weight: 600; color: var(--primary-color); margin-left: 6px;">[<?= htmlspecialchars($sub['code']) ?>]</span>
                            <?php endif; ?>
                            <span style="font-size: 0.7rem; color: #94a3b8; margin-left: 4px;">(<?= $subsidiaryLabel ?>)</span>
                        </td>
                        <td>
                            <div class="flex justify-center gap-2">
                                <a class="icon-btn" href="<?= BASE_URL ?>pages/<?= $subsidiaryPage ?>?search=<?= urlencode($sub['name']) ?>" title="View <?= $subsidiaryLabel ?> record">
                                    <i data-lucide="external-link" style="width:14px;height:14px;"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="accModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 560px;">
        <div class="modal-header">
            <div>
                <h2 style="font-size: 1.125rem;" id="modalTitle">New Account</h2>
                <p class="text-xs text-muted mt-1" id="modalDesc">Fill in the details to create a new account.</p>
            </div>
            <button class="icon-btn" onclick="closeModal()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="acc-form" method="POST">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="accId" value="">
                
                <div class="flex gap-4">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Account Code</label>
                        <input type="text" name="code" id="accCode" class="form-control" placeholder="e.g., 1-100" required>
                    </div>
                    <div class="form-group" style="flex: 2;">
                        <label class="form-label">Account Name</label>
                        <input type="text" name="name" id="accName" class="form-control" required>
                    </div>
                </div>
                
                <div class="flex gap-4">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Category</label>
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
                    <input type="text" name="description" id="accDesc" class="form-control">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="acc-form" class="btn btn-primary">Save Account</button>
        </div>
    </div>
</div>

<script>
// Dynamic mappings based on your system layout
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
    
    document.getElementById('modalDesc').innerText = `Fill in the details. [${codeGuides[category]}]`;
}

// Watch for Category modifications to apply predictive prefixes
document.getElementById('accCat').addEventListener('change', function() {
    updateSubCategories(this.value);
    document.getElementById('accCode').value = prefixMap[this.value] || '';
});

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
        document.getElementById('accCode').value = '1-'; 
        document.getElementById('accName').value = '';
        document.getElementById('accCat').value = 'Assets';
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
</script>

<?php require_once '../includes/footer.php'; ?>