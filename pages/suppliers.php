<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';

$db = get_db();

// ------------------------------------------------------------------
// Auto-migration: create the suppliers table if it doesn't exist yet.
// Follows the same defensive pattern used elsewhere in the app.
// ------------------------------------------------------------------
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

$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    require_once '../includes/header.php';
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to manage suppliers.</div>';
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
            $error = "Supplier name is required.";
        } else if ($action === 'add') {
            $stmt = $db->prepare("INSERT INTO suppliers (company_id, name, contact_person, email, phone, address, tin, terms, opening_balance, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssssdsss', $company_id, $name, $contact_person, $email, $phone, $address, $tin, $terms, $opening_balance, $status, $notes);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Create', 'Suppliers', "Added supplier: $name");
            $success = "Supplier added successfully.";
            header("Location: suppliers.php");
            exit;
        } else if ($action === 'edit' && $id) {
            $stmt = $db->prepare("UPDATE suppliers SET name=?, contact_person=?, email=?, phone=?, address=?, tin=?, terms=?, opening_balance=?, status=?, notes=? WHERE id=? AND company_id=?");
            $stmt->bind_param('sssssssdssii', $name, $contact_person, $email, $phone, $address, $tin, $terms, $opening_balance, $status, $notes, $id, $company_id);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Update', 'Suppliers', "Updated supplier: $name");
            $success = "Supplier updated successfully.";
            header("Location: suppliers.php");
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];

        // Warn (but don't hard-block) if this supplier's name appears in existing
        // transactions, since transactions currently reference suppliers by
        // free-text name rather than a foreign key.
        $stmt = $db->prepare("SELECT name FROM suppliers WHERE id = ? AND company_id = ?");
        $stmt->bind_param('ii', $id, $company_id);
        $stmt->execute();
        $sup = $stmt->get_result()->fetch_assoc();

        if ($sup) {
            $stmtDel = $db->prepare("DELETE FROM suppliers WHERE id = ? AND company_id = ?");
            $stmtDel->bind_param('ii', $id, $company_id);
            if ($stmtDel->execute()) {
                log_activity($db, $_SESSION['user_id'], 'Delete', 'Suppliers', "Deleted supplier: {$sup['name']}");
            }
        }
        header("Location: suppliers.php");
        exit;
    }
}

require_once '../includes/header.php';

// Search Filter
$search = $_GET['search'] ?? '';
$query = "SELECT * FROM suppliers WHERE company_id = ?";
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
$suppliers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Supplier List</h1>
        <p class="page-subtitle">Manage supplier contact details and account terms.</p>
    </div>
    <div class="flex gap-2">
        <button class="btn btn-primary" onclick="openModal()">
            <i data-lucide="plus" style="width:15px;height:15px;"></i> Add Supplier
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
        <span class="text-sm text-muted"><?= count($suppliers) ?> suppliers</span>
    </div>

    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Supplier Name</th>
                    <th>Contact Person</th>
                    <th>Email / Phone</th>
                    <th>Terms</th>
                    <th class="text-right">Opening Balance</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($suppliers) === 0): ?>
                <tr><td colspan="7" class="text-center text-secondary" style="padding: 2rem;">No suppliers found.</td></tr>
                <?php else: foreach($suppliers as $s): ?>
                <tr style="color: #000;">
                    <td style="font-weight: 600;"><?= htmlspecialchars($s['name']) ?></td>
                    <td style="font-size: 0.875rem;"><?= htmlspecialchars($s['contact_person'] ?: '—') ?></td>
                    <td style="font-size: 0.8125rem;">
                        <?= htmlspecialchars($s['email'] ?: '—') ?><br>
                        <span style="color: var(--text-muted);"><?= htmlspecialchars($s['phone'] ?: '') ?></span>
                    </td>
                    <td style="font-size: 0.8125rem;"><?= htmlspecialchars($s['terms'] ?: '—') ?></td>
                    <td class="text-right" style="font-weight: 600;">₱<?= number_format($s['opening_balance'], 2) ?></td>
                    <td class="text-center">
                        <span class="badge <?= $s['status'] === 'Active' ? 'badge-success' : 'badge-neutral' ?>"><?= htmlspecialchars($s['status']) ?></span>
                    </td>
                    <td>
                        <div class="flex justify-center gap-2">
                            <button class="icon-btn" onclick='openModal(<?= json_encode($s) ?>)'><i data-lucide="edit-2" style="width:16px;height:16px;"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this supplier? This will not affect past transactions, which reference suppliers by name only.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
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
<div id="supModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 620px;">
        <div class="modal-header">
            <div>
                <h2 style="font-size: 1.125rem;" id="modalTitle">New Supplier</h2>
                <p class="text-xs text-muted mt-1" id="modalDesc">Fill in the supplier's details.</p>
            </div>
            <button class="icon-btn" onclick="closeModal()"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="sup-form" method="POST">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="supId" value="">

                <div class="flex gap-4">
                    <div class="form-group" style="flex: 2;">
                        <label class="form-label">Supplier Name <span class="required">*</span></label>
                        <input type="text" name="name" id="supName" class="form-control" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Status</label>
                        <select name="status" id="supStatus" class="form-control">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Contact Person</label>
                    <input type="text" name="contact_person" id="supContact" class="form-control">
                </div>

                <div class="flex gap-4">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="supEmail" class="form-control">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" id="supPhone" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" id="supAddress" class="form-control">
                </div>

                <div class="flex gap-4">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">TIN</label>
                        <input type="text" name="tin" id="supTin" class="form-control">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Payment Terms</label>
                        <select name="terms" id="supTerms" class="form-control">
                            <option value="COD">COD</option>
                            <option value="Net 15">Net 15</option>
                            <option value="Net 30">Net 30</option>
                            <option value="Net 60">Net 60</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Opening Balance (AP)</label>
                        <input type="number" step="0.01" name="opening_balance" id="supBal" class="form-control" value="0">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" id="supNotes" class="form-control" rows="2"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="sup-form" class="btn btn-primary">Save Supplier</button>
        </div>
    </div>
</div>

<script>
function openModal(s = null) {
    const modal = document.getElementById('supModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    if (s) {
        document.getElementById('modalTitle').innerText = 'Edit Supplier';
        document.getElementById('modalDesc').innerText = 'Update supplier details below.';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('supId').value = s.id;
        document.getElementById('supName').value = s.name;
        document.getElementById('supStatus').value = s.status;
        document.getElementById('supContact').value = s.contact_person || '';
        document.getElementById('supEmail').value = s.email || '';
        document.getElementById('supPhone').value = s.phone || '';
        document.getElementById('supAddress').value = s.address || '';
        document.getElementById('supTin').value = s.tin || '';
        document.getElementById('supTerms').value = s.terms || 'COD';
        document.getElementById('supBal').value = s.opening_balance;
        document.getElementById('supNotes').value = s.notes || '';
    } else {
        document.getElementById('modalTitle').innerText = 'New Supplier';
        document.getElementById('modalDesc').innerText = "Fill in the supplier's details.";
        document.getElementById('formAction').value = 'add';
        document.getElementById('supId').value = '';
        document.getElementById('sup-form').reset();
        document.getElementById('supBal').value = '0';
        document.getElementById('supStatus').value = 'Active';
        document.getElementById('supTerms').value = 'COD';
    }
}
function closeModal() {
    const modal = document.getElementById('supModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}
document.getElementById('supModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php require_once '../includes/footer.php'; ?>