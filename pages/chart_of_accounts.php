<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/admin_auth.php';
require_once '../includes/header.php';

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
        $opening_balance = $_POST['opening_balance'] ?: 0;

        if ($action === 'add') {
            $stmt = $db->prepare("INSERT INTO accounts (company_id, code, name, category, sub_category, description, opening_balance) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssssd', $company_id, $code, $name, $category, $sub_category, $description, $opening_balance);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Create', 'Chart of Accounts', "Added account: $code - $name");
            $success = "Account added successfully.";
        } else if ($action === 'edit' && $id) {
            $stmt = $db->prepare("UPDATE accounts SET code=?, name=?, category=?, sub_category=?, description=?, opening_balance=? WHERE id=? AND company_id=?");
            $stmt->bind_param('sssssdii', $code, $name, $category, $sub_category, $description, $opening_balance, $id, $company_id);
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
        a.*,
        a.opening_balance + SUM(IFNULL(l.debit, 0)) - SUM(IFNULL(l.credit, 0)) as raw_balance
    FROM accounts a
    LEFT JOIN journal_entry_lines l ON a.id = l.account_id
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

$query .= " GROUP BY a.id ORDER BY a.code ASC";

$stmt = $db->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function getBal($cat, $raw) {
    if ($cat === 'Assets' || $cat === 'Expenses') return $raw;
    return -$raw;
}
?>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Chart of Accounts</h1>
        <p class="page-subtitle">Manage your accounts and opening balances.</p>
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
    <div class="flex items-center gap-3" style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-color);">
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
                    <th>Code</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th class="text-right">Current Balance</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $grouped_accounts = [];
                foreach($accounts as $acc) {
                    $grouped_accounts[$acc['category']][] = $acc;
                }
                
                if (count($accounts) === 0): ?>
                <tr><td colspan="5" class="text-center text-secondary" style="padding: 2rem;">No accounts found.</td></tr>
                <?php else: 
                    foreach($grouped_accounts as $catName => $catAccounts): 
                ?>
                <tr style="background-color: #f1f5f9;">
                    <td colspan="5" style="font-weight: 700; font-size: 0.95rem; color: #334155; padding-top: 0.75rem; padding-bottom: 0.75rem; letter-spacing: 0.05em;">
                        <?= htmlspecialchars(strtoupper($catName)) ?>
                    </td>
                </tr>
                <?php foreach($catAccounts as $acc): 
                    $bal = getBal($acc['category'], $acc['raw_balance']);
                ?>
                <tr style="color: #000;">
                    <td style="font-family: monospace; font-weight: 600; font-size: 0.875rem; padding-left: 2rem;"><?= htmlspecialchars($acc['code']) ?></td>
                    <td style="font-weight: 500;"><?= htmlspecialchars($acc['name']) ?></td>
                    <td style="font-size: 0.8125rem;"><?= htmlspecialchars($acc['sub_category'] ?: '—') ?></td>
                    <td class="text-right" style="font-weight: 600;">₱<?= number_format(abs($bal), 2) ?></td>
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
                <?php endforeach; ?>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
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
                        <input type="text" name="code" id="accCode" class="form-control" required>
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
                            <option value="Current Assets">Current Assets</option>
                            <option value="Non-Current Assets">Non-Current Assets</option>
                            <option value="Current Liabilities">Current Liabilities</option>
                            <option value="Non-Current Liabilities">Non-Current Liabilities</option>
                            <option value="Owner's Capital">Owner's Capital</option>
                            <option value="Withdrawals">Withdrawals</option>
                            <option value="Retained Earnings">Retained Earnings</option>
                            <option value="Sales Revenue">Sales Revenue</option>
                            <option value="Service Revenue">Service Revenue</option>
                            <option value="Operating Expenses">Operating Expenses</option>
                            <option value="Other Expenses">Other Expenses</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" id="accDesc" class="form-control">
                </div>

                <div class="form-group">
                    <label class="form-label">Initial / Opening Balance</label>
                    <input type="number" step="0.01" name="opening_balance" id="accBal" class="form-control" value="0">
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
function openModal(acc = null) {
    const modal = document.getElementById('accModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    if (acc) {
        document.getElementById('modalTitle').innerText = 'Edit Account';
        document.getElementById('modalDesc').innerText = 'Update account details below.';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('accId').value = acc.id;
        document.getElementById('accCode').value = acc.code;
        document.getElementById('accName').value = acc.name;
        document.getElementById('accCat').value = acc.category;
        document.getElementById('accSub').value = acc.sub_category || '';
        document.getElementById('accDesc').value = acc.description || '';
        document.getElementById('accBal').value = acc.opening_balance;
    } else {
        document.getElementById('modalTitle').innerText = 'New Account';
        document.getElementById('modalDesc').innerText = 'Fill in the details to create a new account.';
        document.getElementById('formAction').value = 'add';
        document.getElementById('accId').value = '';
        document.getElementById('accCode').value = '';
        document.getElementById('accName').value = '';
        document.getElementById('accCat').value = 'Assets';
        document.getElementById('accSub').value = 'Current Assets';
        document.getElementById('accDesc').value = '';
        document.getElementById('accBal').value = '0';
    }
}
function closeModal() {
    const modal = document.getElementById('accModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}
// Close on overlay click
document.getElementById('accModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php require_once '../includes/footer.php'; ?>
