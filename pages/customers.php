<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';

$db = get_db();

// ------------------------------------------------------------------
// Auto-migration: create the customers table if it doesn't exist yet.
// Follows the same defensive pattern used elsewhere in the app.
// ------------------------------------------------------------------
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

$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    require_once '../includes/header.php';
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to manage customers.</div>';
    require_once '../includes/footer.php';
    exit;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add' || $action === 'edit') {
        $id = $_POST['id'] ?? null;
        $name = trim($_POST['name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $tin = trim($_POST['tin'] ?? '');
        $terms = $_POST['terms'] ?? 'COD';
        $opening_balance = $_POST['opening_balance'] !== '' ? (float)$_POST['opening_balance'] : 0;
        $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        $notes = trim($_POST['notes'] ?? '');

        if ($name === '') {
            $error = "Customer name is required.";
        } else if ($action === 'add') {
            $stmt = $db->prepare("INSERT INTO customers (company_id, name, contact_person, email, phone, address, tin, terms, opening_balance, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssssdsss', $company_id, $name, $contact_person, $email, $phone, $address, $tin, $terms, $opening_balance, $status, $notes);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Create', 'Customers', "Added customer: $name");
            $success = "Customer added successfully.";
            header("Location: customers.php");
            exit;
        } else if ($action === 'edit' && $id) {
            $stmt = $db->prepare("UPDATE customers SET name=?, contact_person=?, email=?, phone=?, address=?, tin=?, terms=?, opening_balance=?, status=?, notes=? WHERE id=? AND company_id=?");
            $stmt->bind_param('sssssssdssii', $name, $contact_person, $email, $phone, $address, $tin, $terms, $opening_balance, $status, $notes, $id, $company_id);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Update', 'Customers', "Updated customer: $name");
            $success = "Customer updated successfully.";
            header("Location: customers.php");
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];

        // Warn (but don't hard-block) if this customer's name appears in existing
        // transactions, since transactions currently reference customers by
        // free-text name rather than a foreign key.
        $stmt = $db->prepare("SELECT name FROM customers WHERE id = ? AND company_id = ?");
        $stmt->bind_param('ii', $id, $company_id);
        $stmt->execute();
        $cust = $stmt->get_result()->fetch_assoc();

        if ($cust) {
            $stmtDel = $db->prepare("DELETE FROM customers WHERE id = ? AND company_id = ?");
            $stmtDel->bind_param('ii', $id, $company_id);
            if ($stmtDel->execute()) {
                log_activity($db, $_SESSION['user_id'], 'Delete', 'Customers', "Deleted customer: {$cust['name']}");
            }
        }
        header("Location: customers.php");
        exit;
    }
}

require_once '../includes/header.php';

// Search Filter
$search = $_GET['search'] ?? '';
$query = "SELECT * FROM customers WHERE company_id = ?";
$params = [$company_id];
$types = "i";

if ($search) {
    $query .= " AND (name LIKE ? OR contact_person LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= "ssss";
}
$query .= " ORDER BY name ASC";

$stmt = $db->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Customer List</h1>
        <p class="page-subtitle">Manage customer contact details and account terms.</p>
    </div>
    <div class="flex gap-2">
        <button class="btn btn-primary" onclick="openModal()">
            <i data-lucide="plus" style="width:15px;height:15px;"></i> Add Customer
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
            <input type="text" name="search" class="form-control" placeholder="Search by name, contact, email, phone..." value="<?= htmlspecialchars($search) ?>" style="padding-left: 2.25rem;">
            <button type="submit" style="display:none;"></button>
        </form>
        <span class="text-sm text-muted"><?= count($customers) ?> customers</span>
    </div>

    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Customer Name</th>
                    <th>Contact Person</th>
                    <th>Email / Phone</th>
                    <th>Terms</th>
                    <th class="text-right">Opening Balance</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($customers) === 0): ?>
                <tr><td colspan="7" class="text-center text-secondary" style="padding: 2rem;">No customers found.</td></tr>
                <?php else: foreach($customers as $c): ?>
                <tr style="color: #000;">
                    <td style="font-weight: 600;"><?= htmlspecialchars($c['name']) ?></td>
                    <td style="font-size: 0.875rem;"><?= htmlspecialchars($c['contact_person'] ?: '—') ?></td>
                    <td style="font-size: 0.8125rem;">
                        <?= htmlspecialchars($c['email'] ?: '—') ?><br>
                        <span style="color: var(--text-muted);"><?= htmlspecialchars($c['phone'] ?: '') ?></span>
                    </td>
                    <td style="font-size: 0.8125rem;"><?= htmlspecialchars($c['terms'] ?: '—') ?></td>
                    <td class="text-right" style="font-weight: 600;">₱<?= number_format($c['opening_balance'], 2) ?></td>
                    <td class="text-center">
                        <span class="badge <?= $c['status'] === 'Active' ? 'badge-success' : 'badge-neutral' ?>"><?= htmlspecialchars($c['status']) ?></span>
                    </td>
                    <td>
                        <div class="flex justify-center gap-2">
                            <button class="icon-btn" onclick='openModal(<?= json_encode($c) ?>)'><i data-lucide="edit-2" style="width:16px;height:16px;"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this customer? This will not affect past transactions, which reference customers by name only.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="icon-btn text-danger"><i data-lucide="trash-2" style="width:16px;height:16px;"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div id="custModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 620px;">
        <div class="modal-header">
            <div>
                <h2 style="font-size: 1.125rem;" id="modalTitle">New Customer</h2>
                <p class="text-xs text-muted mt-1" id="modalDesc">Fill in the customer's details.</p>
            </div>
            <button class="icon-btn" onclick="closeModal()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="cust-form" method="POST">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="custId" value="">

                <div class="flex gap-4">
                    <div class="form-group" style="flex: 2;">
                        <label class="form-label">Customer Name <span class="required">*</span></label>
                        <input type="text" name="name" id="custName" class="form-control" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Status</label>
                        <select name="status" id="custStatus" class="form-control">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Contact Person</label>
                    <input type="text" name="contact_person" id="custContact" class="form-control">
                </div>

                <div class="flex gap-4">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="custEmail" class="form-control">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" id="custPhone" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" id="custAddress" class="form-control">
                </div>

                <div class="flex gap-4">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">TIN</label>
                        <input type="text" name="tin" id="custTin" class="form-control">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Payment Terms</label>
                        <select name="terms" id="custTerms" class="form-control">
                            <option value="COD">COD</option>
                            <option value="Net 15">Net 15</option>
                            <option value="Net 30">Net 30</option>
                            <option value="Net 60">Net 60</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Opening Balance (AR)</label>
                        <input type="number" step="0.01" name="opening_balance" id="custBal" class="form-control" value="0">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" id="custNotes" class="form-control" rows="2"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="cust-form" class="btn btn-primary">Save Customer</button>
        </div>
    </div>
</div>

<script>
function openModal(c = null) {
    const modal = document.getElementById('custModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    if (c) {
        document.getElementById('modalTitle').innerText = 'Edit Customer';
        document.getElementById('modalDesc').innerText = 'Update customer details below.';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('custId').value = c.id;
        document.getElementById('custName').value = c.name;
        document.getElementById('custStatus').value = c.status;
        document.getElementById('custContact').value = c.contact_person || '';
        document.getElementById('custEmail').value = c.email || '';
        document.getElementById('custPhone').value = c.phone || '';
        document.getElementById('custAddress').value = c.address || '';
        document.getElementById('custTin').value = c.tin || '';
        document.getElementById('custTerms').value = c.terms || 'COD';
        document.getElementById('custBal').value = c.opening_balance;
        document.getElementById('custNotes').value = c.notes || '';
    } else {
        document.getElementById('modalTitle').innerText = 'New Customer';
        document.getElementById('modalDesc').innerText = "Fill in the customer's details.";
        document.getElementById('formAction').value = 'add';
        document.getElementById('custId').value = '';
        document.getElementById('cust-form').reset();
        document.getElementById('custBal').value = '0';
        document.getElementById('custStatus').value = 'Active';
        document.getElementById('custTerms').value = 'COD';
    }
}
function closeModal() {
    const modal = document.getElementById('custModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}
document.getElementById('custModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php require_once '../includes/footer.php'; ?>