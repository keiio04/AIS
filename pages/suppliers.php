<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';
require_once '../includes/transaction_poster.php';

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
            code VARCHAR(20) NULL,
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

// Defensive: add the `code` column for installs where the table already
// existed before this feature was added.
try { $db->query("ALTER TABLE suppliers ADD COLUMN code VARCHAR(20) NULL AFTER company_id"); } catch (Exception $e) {}

$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    require_once '../includes/header.php';
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to manage suppliers.</div>';
    require_once '../includes/footer.php';
    exit;
}

// Generate a Supplier ID (e.g. SUPP-0001) for any existing rows that
// don't have one yet, in creation order, so nothing is left blank.
try {
    $stmtBackfill = $db->prepare("SELECT id FROM suppliers WHERE company_id = ? AND (code IS NULL OR code = '') ORDER BY id ASC");
    $stmtBackfill->bind_param('i', $company_id);
    $stmtBackfill->execute();
    $needsCode = $stmtBackfill->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($needsCode) > 0) {
        $stmtMax = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) as max_num FROM suppliers WHERE company_id = ? AND code LIKE 'SUPP-%'");
        $stmtMax->bind_param('i', $company_id);
        $stmtMax->execute();
        $nextNum = (int)($stmtMax->get_result()->fetch_assoc()['max_num'] ?? 0) + 1;
        $stmtSetCode = $db->prepare("UPDATE suppliers SET code = ? WHERE id = ?");
        foreach ($needsCode as $row) {
            $newCode = sprintf('SUPP-%04d', $nextNum);
            $stmtSetCode->bind_param('si', $newCode, $row['id']);
            $stmtSetCode->execute();
            $nextNum++;
        }
    }
} catch (Exception $e) {}

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
        $terms = $_POST['terms'] ?? 'Cash';
        $terms = in_array($terms, ['Cash', 'Credit']) ? $terms : 'Cash';
        $opening_balance = $_POST['opening_balance'] !== '' ? (float)$_POST['opening_balance'] : 0;
        $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        $notes = trim($_POST['notes'] ?? '');

        if ($name === '') {
            $error = "Supplier name is required.";
        } else if ($action === 'add') {
            // Generate the next sequential Supplier ID for this company.
            $stmtMax = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) as max_num FROM suppliers WHERE company_id = ? AND code LIKE 'SUPP-%'");
            $stmtMax->bind_param('i', $company_id);
            $stmtMax->execute();
            $nextNum = (int)($stmtMax->get_result()->fetch_assoc()['max_num'] ?? 0) + 1;
            $supplier_code = sprintf('SUPP-%04d', $nextNum);

            $stmt = $db->prepare("INSERT INTO suppliers (company_id, code, name, contact_person, email, phone, address, tin, terms, opening_balance, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssssssdss', $company_id, $supplier_code, $name, $contact_person, $email, $phone, $address, $tin, $terms, $opening_balance, $status, $notes);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Create', 'Suppliers', "Added supplier: $name ($supplier_code)");
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

    if ($action === 'record_transaction') {
        $txData = [
            'entity_type'       => 'supplier',
            'entity_id'         => (int)($_POST['supplier_id'] ?? 0),
            'date'              => $_POST['date'] ?? date('Y-m-d'),
            'terms'             => $_POST['terms'] ?? 'Cash',
            'amount'            => (float)($_POST['amount'] ?? 0),
            'is_vatable'        => !empty($_POST['is_vatable']),
            'is_vat_inclusive'  => !empty($_POST['is_vat_inclusive']),
            'target_account_id' => !empty($_POST['expense_account_id']) ? (int)$_POST['expense_account_id'] : null,
            'description'       => trim($_POST['description'] ?? '')
        ];
        $res = post_student_transaction($db, $company_id, $_SESSION['user_id'], $txData);
        if ($res['success']) {
            $targetJournalUrl = ($res['journal_id'] === 'PJ') ? 'purchases_journal.php' : 'cash_disbursements_journal.php';
            header("Location: {$targetJournalUrl}?posted=1&ref=" . urlencode($res['reference_no']));
            exit;
        } else {
            $error = $res['error'];
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

// Fetch company's tax registration status
$stmtCo = $db->prepare("SELECT tax_registered FROM companies WHERE id = ?");
$stmtCo->bind_param('i', $company_id);
$stmtCo->execute();
$companyIsTaxRegistered = (bool)($stmtCo->get_result()->fetch_assoc()['tax_registered'] ?? false);

// Fetch expense / supplies accounts for supplier transactions
$stmtExp = $db->prepare("SELECT id, code, name, category FROM accounts WHERE company_id = ? AND (category = 'Expenses' OR (category = 'Assets' AND (name LIKE '%supplies%' OR name LIKE '%inventory%' OR name LIKE '%merchandise%'))) AND name NOT LIKE '%cash%' AND name NOT LIKE '%receivable%' AND name NOT LIKE '%payable%' ORDER BY category DESC, code ASC");
$stmtExp->bind_param('i', $company_id);
$stmtExp->execute();
$expenseAccounts = $stmtExp->get_result()->fetch_all(MYSQLI_ASSOC);

$stdAccts = get_company_standard_accounts($db, $company_id);
// Re-fetch to include any auto-provisioned standard accounts
$stmtExp->execute();
$expenseAccounts = $stmtExp->get_result()->fetch_all(MYSQLI_ASSOC);

$defaultExpenseId = $stdAccts['purchases']['id'] ?? ($expenseAccounts[0]['id'] ?? 0);

require_once '../includes/header.php';

// Search Filter
$search = $_GET['search'] ?? '';
$query = "SELECT s.*,
    /* AP Balance = Opening Balance + net credit movement on AP account linked to this supplier */
    (
        s.opening_balance +
        COALESCE((
            SELECT SUM(l.credit - l.debit)
            FROM journal_entry_lines l
            JOIN journal_entries e ON l.journal_entry_id = e.id
            JOIN accounts a ON l.account_id = a.id
            WHERE e.entity_id = s.id AND e.entity_type = 'supplier'
              AND e.deleted_at IS NULL
              AND a.name LIKE '%payable%'
        ), 0)
    ) AS current_balance,
    /* Total Purchases = sum of debits on Expense/Cost accounts from PJ/CDJ entries for this supplier */
    COALESCE((
        SELECT SUM(l.debit)
        FROM journal_entry_lines l
        JOIN journal_entries e ON l.journal_entry_id = e.id
        JOIN accounts a ON l.account_id = a.id
        WHERE e.entity_id = s.id AND e.entity_type = 'supplier'
          AND e.deleted_at IS NULL
          AND e.journal_id IN ('PJ', 'CDJ')
          AND (a.category = 'Expenses' OR (a.category = 'Assets' AND (a.name LIKE '%supplies%' OR a.name LIKE '%inventory%' OR a.name LIKE '%purchases%')))
    ), 0) AS total_purchases,
    /* Total Input VAT = sum of debits on Input VAT accounts from PJ/CDJ entries for this supplier */
    COALESCE((
        SELECT SUM(l.debit)
        FROM journal_entry_lines l
        JOIN journal_entries e ON l.journal_entry_id = e.id
        JOIN accounts a ON l.account_id = a.id
        WHERE e.entity_id = s.id AND e.entity_type = 'supplier'
          AND e.deleted_at IS NULL
          AND e.journal_id IN ('PJ', 'CDJ')
          AND a.name LIKE '%input vat%'
    ), 0) AS total_input_vat
FROM suppliers s WHERE s.company_id = ?";
$params = [$company_id];
$types = "i";

if ($search) {
    $query .= " AND (s.name LIKE ? OR s.contact_person LIKE ? OR s.email LIKE ? OR s.phone LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= "ssss";
}
$query .= " ORDER BY s.name ASC";

$stmt = $db->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$suppliers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<?php if (isset($error)): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="card" style="padding: 0; overflow: hidden; border: none; box-shadow: none;">
    <div class="flex items-center justify-between gap-3 flex-wrap" style="padding: 0.5rem 1rem; border-bottom: 1px solid var(--border-color);">
        <div class="flex items-center gap-3" style="flex: 1; max-width: 420px;">
            <form method="GET" style="position: relative; flex: 1; display: flex;">
                <i data-lucide="search" style="position: absolute; left: 0.65rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); width:13px; height:13px;"></i>
                <input type="text" name="search" class="form-control" placeholder="Search by name, contact, email, phone..." value="<?= htmlspecialchars($search) ?>" style="padding-left: 2rem; font-size: 0.8125rem; height: 32px;">
                <button type="submit" style="display:none;"></button>
            </form>
            <span class="text-xs text-muted nowrap"><?= count($suppliers) ?> suppliers</span>
        </div>
        <div class="flex gap-2">
            <button class="btn btn-success" onclick="openTxModal()" style="background: #16a34a; border-color: #16a34a; color: white; font-size: 0.8125rem; padding: 0.35rem 0.75rem;">
                <i data-lucide="receipt" style="width:14px;height:14px;"></i> Record Transaction
            </button>
            <button class="btn btn-primary" onclick="openModal()" style="font-size: 0.8125rem; padding: 0.35rem 0.75rem;">
                <i data-lucide="plus" style="width:14px;height:14px;"></i> Add Supplier
            </button>
        </div>
    </div>

    <div class="table-container">
        <table class="table compact-table">
            <thead>
                <tr>
                    <th style="min-width: 95px;" class="nowrap">Supplier ID</th>
                    <th style="min-width: 140px;">Supplier Name</th>
                    <th style="min-width: 110px;">Contact Person</th>
                    <th style="min-width: 140px;">Email / Phone</th>
                    <th style="min-width: 80px;" class="nowrap">Terms</th>
                    <th class="text-right nowrap" style="min-width: 110px;">Total Purchases</th>
                    <th class="text-right nowrap" style="min-width: 100px;">Input VAT</th>
                    <th class="text-right nowrap" style="min-width: 110px;">AP Balance</th>
                    <th class="text-center nowrap" style="min-width: 75px;">Status</th>
                    <th class="text-center nowrap" style="min-width: 70px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($suppliers) === 0): ?>
                <tr><td colspan="10" class="text-center text-secondary" style="padding: 2rem; font-size: 0.8125rem;">No suppliers found.</td></tr>
                <?php else: foreach($suppliers as $s): ?>
                <tr>
                    <td style="font-family: monospace; font-size: 0.78rem; color: var(--text-primary);"><?= htmlspecialchars($s['code'] ?: '—') ?></td>
                    <td style="font-size: 0.8125rem;">
                        <a href="javascript:void(0)" onclick="openHistoryModal(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode(($s['code'] ? '[' . $s['code'] . '] ' : '') . $s['name']), ENT_QUOTES, 'UTF-8') ?>)" title="View transaction history" style="color: var(--primary-color); text-decoration: none; font-weight: 500;">
                            <?= htmlspecialchars($s['name']) ?>
                        </a>
                    </td>
                    <td style="font-size: 0.8125rem; color: var(--text-secondary);"><?= htmlspecialchars($s['contact_person'] ?: '—') ?></td>
                    <td style="font-size: 0.78rem; line-height: 1.3;">
                        <?= htmlspecialchars($s['email'] ?: '—') ?><br>
                        <span style="color: var(--text-muted); font-size: 0.72rem;"><?= htmlspecialchars($s['phone'] ?: '') ?></span>
                    </td>
                    <td style="font-size: 0.8125rem;"><?= htmlspecialchars($s['terms'] ?: '—') ?></td>
                    <td class="text-right" style="font-weight: 600; font-size: 0.8125rem; font-variant-numeric: tabular-nums;">₱<?= number_format($s['total_purchases'], 2) ?></td>
                    <td class="text-right" style="font-weight: 600; font-size: 0.8125rem; font-variant-numeric: tabular-nums;">₱<?= number_format($s['total_input_vat'], 2) ?></td>
                    <td class="text-right" style="font-weight: 700; font-size: 0.8125rem; font-variant-numeric: tabular-nums;">
                        <?php
                            $bal = $s['current_balance'];
                        ?>
                        <span>₱<?= number_format($bal, 2) ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= $s['status'] === 'Active' ? 'badge-success' : 'badge-neutral' ?>" style="font-size: 0.65rem; padding: 2px 7px;"><?= htmlspecialchars($s['status']) ?></span>
                    </td>
                    <td class="nowrap text-center">
                        <div class="flex items-center justify-center gap-1">
                            <button class="icon-btn" title="Edit Supplier" onclick='openModal(<?= json_encode($s) ?>)' style="padding: 3px 5px; border-radius: 4px; border: 1px solid var(--border-color); background: var(--bg-secondary);">
                                <i data-lucide="edit-2" style="width:13px;height:13px;"></i>
                            </button>
                            <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Delete this supplier? This will not affect past transactions, which reference suppliers by name only.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button type="submit" class="icon-btn text-danger" title="Delete Supplier" style="padding: 3px 5px; border-radius: 4px; border: 1px solid #fee2e2; background: #fef2f2;">
                                    <i data-lucide="trash-2" style="width:13px;height:13px;"></i>
                                </button>
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

                <div class="form-group" id="supCodeGroup" style="display:none;">
                    <label class="form-label">Supplier ID</label>
                    <input type="text" id="supCode" class="form-control" readonly disabled style="background: var(--bg-secondary); font-family: monospace; font-weight: 600; color: var(--primary-color);">
                </div>

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

                <div class="form-group" style="max-width: 260px;">
                    <label class="form-label">Payment Terms</label>
                    <select name="terms" id="supTerms" class="form-control">
                        <option value="Cash">Cash</option>
                        <option value="Credit">Credit</option>
                    </select>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="sup-form" class="btn btn-primary">Save Supplier</button>
        </div>
    </div>
</div>

<!-- Record Supplier Transaction Modal -->
<div id="txModal" class="modal-overlay hidden">
    <div class="modal" style="width: 580px; max-width: 95vw;">
        <div class="modal-header" style="padding: 0.65rem 1rem;">
            <div>
                <h2 style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.4rem;" id="txModalTitle">
                    <i data-lucide="receipt" style="width: 16px; height: 16px; color: #16a34a;"></i>
                    Record Supplier Transaction
                </h2>
            </div>
            <button class="icon-btn" onclick="closeTxModal()"><i data-lucide="x" style="width:16px;height:16px;"></i></button>
        </div>
        <div class="modal-body" style="padding: 0.75rem 1rem;">
            <form id="tx-form" method="POST">
                <input type="hidden" name="action" value="record_transaction">

                <!-- Terms toggle -->
                <div style="margin-bottom: 0.5rem;">
                    <label class="form-label" style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem;">Payment Terms <span class="required">*</span></label>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" id="txTermsCashBtn" class="btn btn-primary" onclick="setTxTerms('Cash')" style="flex: 1; padding: 0.35rem 0.6rem; font-size: 0.78rem; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 0.35rem;">
                            <i data-lucide="banknote" style="width:14px;height:14px;"></i> Cash
                        </button>
                        <button type="button" id="txTermsCreditBtn" class="btn btn-secondary" onclick="setTxTerms('Credit')" style="flex: 1; padding: 0.35rem 0.6rem; font-size: 0.78rem; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 0.35rem;">
                            <i data-lucide="credit-card" style="width:14px;height:14px;"></i> Credit
                        </button>
                    </div>
                    <input type="hidden" name="terms" id="txTerms" value="Cash">
                </div>

                <div class="flex gap-3" style="margin-bottom: 0.5rem;">
                    <div class="form-group" style="flex: 2; margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.2rem;">Supplier <span class="required">*</span></label>
                        <!-- Searchable supplier combo -->
                        <div style="position: relative;">
                            <input type="text" id="txSupplierSearch" class="form-control" autocomplete="off"
                                style="font-size: 0.78rem; padding: 0.35rem 0.55rem; height: 32px;"
                                oninput="filterSupplierList()" onfocus="showSupplierList()">
                            <input type="hidden" name="supplier_id" id="txSupplierId" required>
                            <div id="txSupplierDropdown" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid var(--border-color); border-top:none; border-radius:0 0 6px 6px; max-height:180px; overflow-y:auto; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,0.1);">
                                <?php foreach($suppliers as $sup): ?>
                                <div class="supp-opt" data-id="<?= $sup['id'] ?>" data-terms="<?= htmlspecialchars($sup['terms'] ?? 'Cash') ?>"
                                    data-label="<?= htmlspecialchars(($sup['code'] ? '[' . $sup['code'] . '] ' : '') . $sup['name']) ?>"
                                    style="padding:5px 8px; cursor:pointer; font-size:0.78rem;"
                                    onmousedown="selectSupplier(this)">
                                    <?= htmlspecialchars(($sup['code'] ? '[' . $sup['code'] . '] ' : '') . $sup['name']) ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.2rem;">Date <span class="required">*</span></label>
                        <input type="date" name="date" id="txDate" class="form-control" value="<?= date('Y-m-d') ?>" required onchange="updateTxPreview()" style="font-size: 0.78rem; padding: 0.35rem 0.55rem; height: 32px;">
                    </div>
                </div>

                <div class="flex gap-3" style="margin-bottom: 0.5rem;">
                    <div class="form-group" style="flex: 2; margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.2rem;">Expense / Supplies Account <span class="required">*</span></label>
                        <select name="expense_account_id" id="txExpenseAccountId" class="form-control" required onchange="updateTxPreview()" style="font-size: 0.78rem; padding: 0.35rem 0.55rem; height: 32px;">
                            <?php foreach ($expenseAccounts as $exp): ?>
                            <option value="<?= $exp['id'] ?>" data-name="<?= htmlspecialchars($exp['name']) ?>" <?= $exp['id'] == $defaultExpenseId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($exp['code'] . ' - ' . $exp['name']) ?>
                            </option>
                            <?php endforeach; ?>
                            <?php if (empty($expenseAccounts)): ?>
                            <option value="0" data-name="Purchases / Expenses">Purchases / Expenses (Default)</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.2rem;">Amount (₱) <span class="required">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="txAmount" class="form-control" placeholder="0.00" required oninput="updateTxPreview()" style="font-size: 0.78rem; padding: 0.35rem 0.55rem; height: 32px;">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0.5rem;">
<label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.2rem;">Description / Particulars</label>
<input type="text" name="description" id="txDescription" class="form-control" placeholder="Description / Particulars..." style="font-size: 0.78rem; padding: 0.35rem 0.55rem; height: 32px;">
                </div>

                <!-- Tax / VAT Settings -->
                <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 6px; padding: 0.45rem 0.75rem; margin-bottom: 0.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.4rem;">
                        <div>
                            <span style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">Tax Status:</span>
                            <span style="font-size: 0.7rem; color: var(--text-muted); margin-left: 0.2rem;">(Company: <?= $companyIsTaxRegistered ? 'VAT Registered' : 'Non-VAT' ?>)</span>
                        </div>
                        <div style="display: flex; gap: 0.75rem; align-items: center;">
                            <label style="display: flex; align-items: center; gap: 0.25rem; cursor: pointer; font-size: 0.75rem; font-weight: 500;">
                                <input type="radio" name="is_vatable" id="txVatableYes" value="1" <?= $companyIsTaxRegistered ? 'checked' : '' ?> onchange="updateTxPreview()">
                                VATable (12%)
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.25rem; cursor: pointer; font-size: 0.75rem; font-weight: 500;">
                                <input type="radio" name="is_vatable" id="txVatableNo" value="0" <?= !$companyIsTaxRegistered ? 'checked' : '' ?> onchange="updateTxPreview()">
                                Non-VAT (0%)
                            </label>
                        </div>
                    </div>
                    <div id="txVatInclusiveGroup" style="margin-top: 0.35rem; padding-top: 0.35rem; border-top: 1px dashed var(--border-color); display: flex; align-items: center; gap: 0.4rem;">
                        <input type="checkbox" name="is_vat_inclusive" id="txVatInclusive" value="1" onchange="updateTxPreview()">
                        <label for="txVatInclusive" style="font-size: 0.72rem; color: var(--text-secondary); cursor: pointer;">Amount entered is VAT-inclusive (gross)</label>
                    </div>
                </div>

                <!-- Live Accounting Entry Preview -->
                <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.5rem 0.75rem;">
                    <div style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 0.35rem;">
                        <div id="txRoutingBadge" style="font-size: 0.7rem; font-weight: 700; padding: 2px 7px; border-radius: 5px; background: #fef3c7; color: #b45309;">
                            Cash Disbursements Journal (CDJ)
                        </div>
                    </div>

                    <table style="width: 100%; font-size: 0.78rem; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid #cbd5e1; color: #64748b; font-size: 0.7rem;">
                                <th style="text-align: left; padding: 3px 5px;">Account Title</th>
                                <th style="text-align: right; padding: 3px 5px; width: 100px;">Debit (₱)</th>
                                <th style="text-align: right; padding: 3px 5px; width: 100px;">Credit (₱)</th>
                            </tr>
                        </thead>
                        <tbody id="txPreviewLines">
                            <!-- Populated dynamically by JS -->
                        </tbody>
                        <tfoot>
                            <tr style="border-top: 1.5px solid #94a3b8; font-weight: 700;">
                                <td style="text-align: right; padding: 4px 5px; color: #334155; font-size: 0.75rem;">Total:</td>
                                <td id="txPreviewTotalDr" style="text-align: right; padding: 4px 5px; color: #0f172a; font-size: 0.78rem;">₱0.00</td>
                                <td id="txPreviewTotalCr" style="text-align: right; padding: 4px 5px; color: #0f172a; font-size: 0.78rem;">₱0.00</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </form>
        </div>
        <div class="modal-footer" style="padding: 0.55rem 1rem;">
            <button type="button" class="btn btn-secondary" onclick="closeTxModal()" style="font-size: 0.78rem; padding: 0.3rem 0.75rem;">Cancel</button>
            <button type="submit" form="tx-form" class="btn btn-success" style="background: #16a34a; border-color: #16a34a; color: white; font-size: 0.78rem; padding: 0.3rem 0.75rem; display: flex; align-items: center; gap: 0.35rem;">
                <i data-lucide="check" style="width:14px;height:14px;"></i> Save Transaction
            </button>
        </div>
    </div>
</div>

<!-- Supplier Transaction History Modal -->
<div id="historyModal" class="modal-overlay hidden">
    <div class="modal" style="width: 680px; max-width: 95vw;">
        <div class="modal-header" style="padding: 0.65rem 1rem;">
            <div>
                <h2 style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.4rem;">
                    <i data-lucide="history" style="width: 16px; height: 16px; color: var(--primary-color);"></i>
                    <span id="historySupName">Transaction History</span>
                </h2>
                <p class="text-xs text-muted mt-1">Purchases, payments, and running balance for this supplier.</p>
            </div>
            <button class="icon-btn" onclick="closeHistoryModal()"><i data-lucide="x" style="width:16px;height:16px;"></i></button>
        </div>
        <div class="modal-body" style="padding: 0.75rem 1rem; max-height: 65vh; overflow-y: auto;">

            <div style="display: flex; gap: 0.6rem; margin-bottom: 0.75rem;">
                <div style="flex: 1; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 6px; padding: 0.5rem 0.75rem;">
                    <div style="font-size: 0.68rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Outstanding Balance</div>
                    <div id="historyBalance" style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary);">₱0.00</div>
                </div>
            </div>

            <div id="historyLoading" style="text-align: center; padding: 1.5rem; color: var(--text-muted); font-size: 0.8125rem;">
                Loading transaction history…
            </div>

            <div id="historyContent" style="display: none;">
                <h3 style="font-size: 0.8rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.4rem;">
                    <i data-lucide="file-text" style="width: 13px; height: 13px; vertical-align: -2px;"></i> Purchases
                </h3>
                <div class="table-container" style="margin-bottom: 1rem;">
                    <table class="table compact-table">
                        <thead>
                            <tr>
                                <th style="min-width: 90px;">Date</th>
                                <th style="min-width: 130px;">Reference No.</th>
                                <th>Description</th>
                                <th class="text-right" style="min-width: 100px;">Amount</th>
                                <th class="text-center" style="min-width: 90px;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="historyPurchaseRows"></tbody>
                    </table>
                </div>

                <h3 style="font-size: 0.8rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.4rem;">
                    <i data-lucide="banknote" style="width: 13px; height: 13px; vertical-align: -2px;"></i> Payments
                </h3>
                <div class="table-container">
                    <table class="table compact-table">
                        <thead>
                            <tr>
                                <th style="min-width: 90px;">Date</th>
                                <th style="min-width: 130px;">Reference No.</th>
                                <th>Description</th>
                                <th class="text-right" style="min-width: 100px;">Amount</th>
                                <th class="text-center" style="min-width: 90px;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="historyPaymentRows"></tbody>
                    </table>
                </div>
            </div>

            <div id="historyError" style="display: none; background: #fee2e2; color: #991b1b; padding: 0.75rem; border-radius: 6px; font-size: 0.8125rem;"></div>
        </div>
        <div class="modal-footer" style="padding: 0.55rem 1rem;">
            <button type="button" class="btn btn-secondary" onclick="closeHistoryModal()" style="font-size: 0.78rem; padding: 0.3rem 0.75rem;">Close</button>
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
        document.getElementById('supCodeGroup').style.display = 'block';
        document.getElementById('supCode').value = s.code || '—';
        document.getElementById('supName').value = s.name;
        document.getElementById('supStatus').value = s.status;
        document.getElementById('supContact').value = s.contact_person || '';
        document.getElementById('supEmail').value = s.email || '';
        document.getElementById('supPhone').value = s.phone || '';
        document.getElementById('supAddress').value = s.address || '';
        document.getElementById('supTin').value = s.tin || '';
        document.getElementById('supTerms').value = s.terms && ['Cash','Credit'].includes(s.terms) ? s.terms : 'Cash';
        document.getElementById('supBal').value = s.opening_balance;
        document.getElementById('supNotes').value = s.notes || '';
    } else {
        document.getElementById('modalTitle').innerText = 'New Supplier';
        document.getElementById('modalDesc').innerText = "Fill in the supplier's details.";
        document.getElementById('formAction').value = 'add';
        document.getElementById('supId').value = '';
        document.getElementById('supCodeGroup').style.display = 'none';
        document.getElementById('sup-form').reset();
        document.getElementById('supBal').value = '0';
        document.getElementById('supStatus').value = 'Active';
        document.getElementById('supTerms').value = 'Cash';
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

// Supplier Transaction History Modal
function statusBadge(status) {
    const colors = {
        'Outstanding': ['#fef9c3', '#a16207'],
        'Paid':        ['#dcfce7', '#15803d'],
        'Voided':      ['#f1f5f9', '#64748b']
    };
    const [bg, fg] = colors[status] || ['#f1f5f9', '#64748b'];
    return `<span class="badge" style="font-size: 0.65rem; padding: 2px 7px; background:${bg}; color:${fg};">${status}</span>`;
}

function renderHistoryRows(tbodyId, rows) {
    const tbody = document.getElementById(tbodyId);
    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-secondary" style="padding: 1rem; font-size: 0.8125rem;">None recorded.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(r => `
        <tr>
            <td style="font-size: 0.78rem;">${r.date}</td>
            <td style="font-family: monospace; font-size: 0.75rem;">${r.reference_no}</td>
            <td style="font-size: 0.78rem; color: var(--text-secondary);">${r.description ? r.description.replace(/</g, '&lt;') : '—'}</td>
            <td class="text-right" style="font-weight: 600; font-size: 0.8125rem; font-variant-numeric: tabular-nums;">₱${Number(r.amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
            <td class="text-center">${statusBadge(r.status)}</td>
        </tr>
    `).join('');
}

function openHistoryModal(supplierId, supplierLabel) {
    const modal = document.getElementById('historyModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    if (window.lucide) lucide.createIcons();

    document.getElementById('historySupName').innerText = supplierLabel || 'Transaction History';
    document.getElementById('historyLoading').style.display = 'block';
    document.getElementById('historyContent').style.display = 'none';
    document.getElementById('historyError').style.display = 'none';
    document.getElementById('historyBalance').innerText = '₱0.00';

    fetch('supplier_history_api.php?id=' + encodeURIComponent(supplierId))
        .then(r => r.json())
        .then(data => {
            document.getElementById('historyLoading').style.display = 'none';
            if (!data.success) {
                document.getElementById('historyError').style.display = 'block';
                document.getElementById('historyError').innerText = data.error || 'Unable to load transaction history.';
                return;
            }
            document.getElementById('historyContent').style.display = 'block';
            document.getElementById('historyBalance').innerText = '₱' + Number(data.outstanding_balance).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            renderHistoryRows('historyPurchaseRows', data.purchases);
            renderHistoryRows('historyPaymentRows', data.payments);
            if (window.lucide) lucide.createIcons();
        })
        .catch(() => {
            document.getElementById('historyLoading').style.display = 'none';
            document.getElementById('historyError').style.display = 'block';
            document.getElementById('historyError').innerText = 'Unable to load transaction history.';
        });
}

function closeHistoryModal() {
    const modal = document.getElementById('historyModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}
document.getElementById('historyModal').addEventListener('click', function(e) {
    if (e.target === this) closeHistoryModal();
});

// Transaction Modal Handlers for Suppliers
function openTxModal(s = null) {
    const modal = document.getElementById('txModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';

    // Reset search combo
    document.getElementById('txSupplierSearch').value = '';
    document.getElementById('txSupplierId').value = '';
    document.getElementById('txDescription').value = '';
    hideSupplierList();

    if (s) {
        // Pre-fill from row click
        const label = (s.code ? '[' + s.code + '] ' : '') + s.name;
        document.getElementById('txSupplierSearch').value = label;
        document.getElementById('txSupplierId').value = s.id;
        if (s.terms && ['Cash', 'Credit'].includes(s.terms)) {
            setTxTerms(s.terms);
        } else {
            setTxTerms('Cash');
        }
    } else {
        setTxTerms('Cash');
    }
    updateTxPreview();
    if (window.lucide) lucide.createIcons();
}

function closeTxModal() {
    const modal = document.getElementById('txModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

document.getElementById('txModal').addEventListener('click', function(e) {
    if (e.target === this) closeTxModal();
});

function setTxTerms(terms) {
    document.getElementById('txTerms').value = terms;
    const cashBtn = document.getElementById('txTermsCashBtn');
    const creditBtn = document.getElementById('txTermsCreditBtn');

    if (terms === 'Cash') {
        cashBtn.className = 'btn btn-primary';
        creditBtn.className = 'btn btn-secondary';
    } else {
        cashBtn.className = 'btn btn-secondary';
        creditBtn.className = 'btn btn-primary';
    }
    updateTxPreview();
}

// Searchable supplier combo functions
function showSupplierList() {
    filterSupplierList();
    document.getElementById('txSupplierDropdown').style.display = 'block';
}
function hideSupplierList() {
    setTimeout(() => { document.getElementById('txSupplierDropdown').style.display = 'none'; }, 150);
}
function filterSupplierList() {
    const q = document.getElementById('txSupplierSearch').value.toLowerCase();
    const opts = document.querySelectorAll('#txSupplierDropdown .sup-opt');
    let any = false;
    opts.forEach(opt => {
        const match = opt.dataset.label.toLowerCase().includes(q);
        opt.style.display = match ? 'block' : 'none';
        if (match) any = true;
    });
    document.getElementById('txSupplierDropdown').style.display = any ? 'block' : 'none';
    // Clear hidden ID if user is typing a new search
    document.getElementById('txSupplierId').value = '';
}
function selectSupplier(el) {
    document.getElementById('txSupplierSearch').value = el.dataset.label;
    document.getElementById('txSupplierId').value = el.dataset.id;
    document.getElementById('txSupplierDropdown').style.display = 'none';
    if (el.dataset.terms && ['Cash', 'Credit'].includes(el.dataset.terms)) {
        setTxTerms(el.dataset.terms);
    }
    updateTxPreview();
}
document.getElementById('txSupplierSearch').addEventListener('blur', hideSupplierList);
// Hover highlight
document.addEventListener('mouseover', function(e) {
    if (e.target.classList.contains('sup-opt')) e.target.style.background = '#f1f5f9';
});
document.addEventListener('mouseout', function(e) {
    if (e.target.classList.contains('sup-opt')) e.target.style.background = '';
});

function updateTxPreview() {
    const terms = document.getElementById('txTerms').value || 'Cash';
    const amountVal = parseFloat(document.getElementById('txAmount').value) || 0;
    const isVatable = document.getElementById('txVatableYes').checked;
    const isInclusive = document.getElementById('txVatInclusive').checked;

    // Toggle inclusive group visibility
    const inclusiveGroup = document.getElementById('txVatInclusiveGroup');
    if (inclusiveGroup) {
        inclusiveGroup.style.display = isVatable ? 'flex' : 'none';
    }

    // Auto Journal Routing:
    // Supplier + Cash   => Cash Disbursements Journal (CDJ)
    // Supplier + Credit => Purchases Journal (PJ)
    const badge = document.getElementById('txRoutingBadge');
    if (terms === 'Cash') {
        badge.innerText = 'Cash Disbursements Journal (CDJ)';
        badge.style.background = '#fef3c7';
        badge.style.color = '#b45309';
    } else {
        badge.innerText = 'Purchases Journal (PJ)';
        badge.style.background = '#f1f5f9';
        badge.style.color = '#334155';
    }

    // Calculations
    let net = 0;
    let vat = 0;
    let total = 0;

    if (isVatable) {
        if (isInclusive) {
            net = Math.round((amountVal / 1.12) * 100) / 100;
            vat = Math.round((amountVal - net) * 100) / 100;
            total = amountVal;
        } else {
            net = Math.round(amountVal * 100) / 100;
            vat = Math.round((net * 0.12) * 100) / 100;
            total = Math.round((net + vat) * 100) / 100;
        }
    } else {
        net = Math.round(amountVal * 100) / 100;
        vat = 0;
        total = net;
    }

    // Expense / Supplies Account Title
    const expSelect = document.getElementById('txExpenseAccountId');
    let expName = 'Expense / Supplies';
    if (expSelect && expSelect.options.length > 0 && expSelect.selectedIndex >= 0) {
        expName = expSelect.options[expSelect.selectedIndex].text.replace(/^[0-9-]+\s*-\s*/, '').replace(/\s*\([^)]*\)$/, '') || 'Expense / Supplies';
    }

    const linesBody = document.getElementById('txPreviewLines');
    let html = '';

    // Supplier Accounting:
    // Cash:
    // Debit Expense/Supplies
    // Debit Input VAT if VATable
    // Credit Cash
    // Credit:
    // Debit Expense/Supplies
    // Debit Input VAT if VATable
    // Credit Accounts Payable
    const creditAccountName = (terms === 'Cash') ? 'Cash on Hand' : 'Accounts Payable';

    // Debit Expense Line
    html += `<tr>
        <td style="padding: 3px 5px; font-weight: 600; color: #1e293b; font-size: 0.78rem;">${expName}</td>
        <td style="text-align: right; padding: 3px 5px; font-weight: 600; color: #0f172a; font-size: 0.78rem;">₱${net.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
        <td style="text-align: right; padding: 3px 5px; color: #94a3b8; font-size: 0.78rem;">—</td>
    </tr>`;

    // Debit Input VAT Line (if VATable)
    if (isVatable && vat > 0) {
        html += `<tr>
            <td style="padding: 3px 5px; color: #d97706; font-weight: 500; font-size: 0.78rem;">Input VAT (12%)</td>
            <td style="text-align: right; padding: 3px 5px; color: #d97706; font-weight: 500; font-size: 0.78rem;">₱${vat.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
            <td style="text-align: right; padding: 3px 5px; color: #94a3b8; font-size: 0.78rem;">—</td>
        </tr>`;
    }

    // Credit Line (Cash or AP)
    html += `<tr>
        <td style="padding: 3px 5px; padding-left: 1.25rem; color: #475569; font-size: 0.78rem;">${creditAccountName}</td>
        <td style="text-align: right; padding: 3px 5px; color: #94a3b8; font-size: 0.78rem;">—</td>
        <td style="text-align: right; padding: 3px 5px; font-weight: 600; color: #0f172a; font-size: 0.78rem;">₱${total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
    </tr>`;

    linesBody.innerHTML = html;

    const totalDebit = isVatable ? (net + vat) : net;
    document.getElementById('txPreviewTotalDr').innerText = '₱' + totalDebit.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('txPreviewTotalCr').innerText = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
</script>

<?php require_once '../includes/footer.php'; ?>