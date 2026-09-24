<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';
require_once '../includes/transaction_poster.php';

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
            INDEX idx_customers_company (company_id)
        )
    ");
} catch (Exception $e) {}

// Defensive: add the `code` column for installs where the table already
// existed before this feature was added.
try { $db->query("ALTER TABLE customers ADD COLUMN code VARCHAR(20) NULL AFTER company_id"); } catch (Exception $e) {}

$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    require_once '../includes/header.php';
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to manage customers.</div>';
    require_once '../includes/footer.php';
    exit;
}

// Generate a Customer ID (e.g. CUST-0001) for any existing rows that
// don't have one yet, in creation order, so nothing is left blank.
try {
    $stmtBackfill = $db->prepare("SELECT id FROM customers WHERE company_id = ? AND (code IS NULL OR code = '') ORDER BY id ASC");
    $stmtBackfill->bind_param('i', $company_id);
    $stmtBackfill->execute();
    $needsCode = $stmtBackfill->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($needsCode) > 0) {
        $stmtMax = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) as max_num FROM customers WHERE company_id = ? AND code LIKE 'CUST-%'");
        $stmtMax->bind_param('i', $company_id);
        $stmtMax->execute();
        $nextNum = (int)($stmtMax->get_result()->fetch_assoc()['max_num'] ?? 0) + 1;
        $stmtSetCode = $db->prepare("UPDATE customers SET code = ? WHERE id = ?");
        foreach ($needsCode as $row) {
            $newCode = sprintf('CUST-%04d', $nextNum);
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
            $error = "Customer name is required.";
        } else if ($action === 'add') {
            // Generate the next sequential Customer ID for this company.
            $stmtMax = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) as max_num FROM customers WHERE company_id = ? AND code LIKE 'CUST-%'");
            $stmtMax->bind_param('i', $company_id);
            $stmtMax->execute();
            $nextNum = (int)($stmtMax->get_result()->fetch_assoc()['max_num'] ?? 0) + 1;
            $customer_code = sprintf('CUST-%04d', $nextNum);

            $stmt = $db->prepare("INSERT INTO customers (company_id, code, name, contact_person, email, phone, address, tin, terms, opening_balance, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssssssdss', $company_id, $customer_code, $name, $contact_person, $email, $phone, $address, $tin, $terms, $opening_balance, $status, $notes);
            $stmt->execute();
            log_activity($db, $_SESSION['user_id'], 'Create', 'Customers', "Added customer: $name ($customer_code)");
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

    if ($action === 'record_transaction') {
        $txData = [
            'entity_type'       => 'customer',
            'entity_id'         => (int)($_POST['customer_id'] ?? 0),
            'date'              => $_POST['date'] ?? date('Y-m-d'),
            'terms'             => 'Credit', // All customer sales are recorded as Accounts Receivable (Credit)
            'amount'            => (float)($_POST['amount'] ?? 0),
            'is_vatable'        => !empty($_POST['is_vatable']),
            'is_vat_inclusive'  => !empty($_POST['is_vat_inclusive']),
            'target_account_id' => !empty($_POST['revenue_account_id']) ? (int)$_POST['revenue_account_id'] : null,
            'description'       => trim($_POST['description'] ?? '')
        ];
        $res = post_student_transaction($db, $company_id, $_SESSION['user_id'], $txData);
        if ($res['success']) {
            $targetJournalUrl = ($res['journal_id'] === 'SJ') ? 'sales_journal.php' : 'cash_receipts_journal.php';
            header("Location: {$targetJournalUrl}?posted=1&ref=" . urlencode($res['reference_no']));
            exit;
        } else {
            $error = $res['error'];
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

// Fetch company's tax registration status
$stmtCo = $db->prepare("SELECT tax_registered FROM companies WHERE id = ?");
$stmtCo->bind_param('i', $company_id);
$stmtCo->execute();
$companyIsTaxRegistered = (bool)($stmtCo->get_result()->fetch_assoc()['tax_registered'] ?? false);

// Fetch revenue accounts for customer transactions
$stmtRev = $db->prepare("SELECT id, code, name, category FROM accounts WHERE company_id = ? AND category = 'Revenue' ORDER BY code ASC");
$stmtRev->bind_param('i', $company_id);
$stmtRev->execute();
$revenueAccounts = $stmtRev->get_result()->fetch_all(MYSQLI_ASSOC);

$stdAccts = get_company_standard_accounts($db, $company_id);
// Re-fetch to include any auto-provisioned standard revenue accounts
$stmtRev->execute();
$revenueAccounts = $stmtRev->get_result()->fetch_all(MYSQLI_ASSOC);

$defaultRevenueId = $stdAccts['service_revenue']['id'] ?? ($revenueAccounts[0]['id'] ?? 0);

require_once '../includes/header.php';

// Search Filter
$search = $_GET['search'] ?? '';
$query = "SELECT c.*,
    /* AR Balance = Opening Balance + net debit movement on AR account linked to this customer */
    (
        c.opening_balance +
        COALESCE((
            SELECT SUM(l.debit - l.credit)
            FROM journal_entry_lines l
            JOIN journal_entries e ON l.journal_entry_id = e.id
            JOIN accounts a ON l.account_id = a.id
            WHERE e.entity_id = c.id AND e.entity_type = 'customer'
              AND e.deleted_at IS NULL
              AND a.name LIKE '%receivable%'
        ), 0)
    ) AS current_balance,
    /* Total Revenue = sum of credits on Revenue accounts from SJ/CRJ entries for this customer */
    COALESCE((
        SELECT SUM(l.credit)
        FROM journal_entry_lines l
        JOIN journal_entries e ON l.journal_entry_id = e.id
        JOIN accounts a ON l.account_id = a.id
        WHERE e.entity_id = c.id AND e.entity_type = 'customer'
          AND e.deleted_at IS NULL
          AND e.journal_id IN ('SJ', 'CRJ')
          AND a.category = 'Revenue'
    ), 0) AS total_revenue,
    /* Total Output VAT = sum of credits on Output VAT accounts from SJ/CRJ entries for this customer */
    COALESCE((
        SELECT SUM(l.credit)
        FROM journal_entry_lines l
        JOIN journal_entries e ON l.journal_entry_id = e.id
        JOIN accounts a ON l.account_id = a.id
        WHERE e.entity_id = c.id AND e.entity_type = 'customer'
          AND e.deleted_at IS NULL
          AND e.journal_id IN ('SJ', 'CRJ')
          AND a.name LIKE '%output vat%'
    ), 0) AS total_output_vat
FROM customers c WHERE c.company_id = ?";
$params = [$company_id];
$types = "i";

if ($search) {
    $query .= " AND (c.name LIKE ? OR c.contact_person LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= "ssss";
}
$query .= " ORDER BY c.name ASC";

$stmt = $db->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
            <span class="text-xs text-muted nowrap"><?= count($customers) ?> customers</span>
        </div>
        <div class="flex gap-2">
            <button class="btn btn-success" onclick="openTxModal()" style="background: #16a34a; border-color: #16a34a; color: white; font-size: 0.8125rem; padding: 0.35rem 0.75rem;">
                <i data-lucide="receipt" style="width:14px;height:14px;"></i> Record Transaction
            </button>
            <button class="btn btn-primary" onclick="openModal()" style="font-size: 0.8125rem; padding: 0.35rem 0.75rem;">
                <i data-lucide="plus" style="width:14px;height:14px;"></i> Add Customer
            </button>
        </div>
    </div>

    <div class="table-container">
        <table class="table compact-table">
            <thead>
                <tr>
                    <th style="min-width: 95px;" class="nowrap">Customer ID</th>
                    <th style="min-width: 140px;">Customer Name</th>
                    <th style="min-width: 110px;">Contact Person</th>
                    <th style="min-width: 140px;">Email / Phone</th>
                    <th style="min-width: 80px;" class="nowrap">Terms</th>
                    <th class="text-right nowrap" style="min-width: 110px;">Total Revenue</th>
                    <th class="text-right nowrap" style="min-width: 100px;">Output VAT</th>
                    <th class="text-right nowrap" style="min-width: 110px;">AR Balance</th>
                    <th class="text-center nowrap" style="min-width: 75px;">Status</th>
                    <th class="text-center nowrap" style="min-width: 70px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($customers) === 0): ?>
                <tr><td colspan="10" class="text-center text-secondary" style="padding: 2rem; font-size: 0.8125rem;">No customers found.</td></tr>
                <?php else: foreach($customers as $c): ?>
                <tr style="color: var(--text-primary);">
                    <td style="font-family: monospace; font-size: 0.78rem; color: var(--text-primary);"><?= htmlspecialchars($c['code'] ?: '—') ?></td>
                    <td style="font-size: 0.8125rem;">
                        <a href="javascript:void(0)" onclick="openHistoryModal(<?= (int)$c['id'] ?>, <?= htmlspecialchars(json_encode(($c['code'] ? '[' . $c['code'] . '] ' : '') . $c['name']), ENT_QUOTES, 'UTF-8') ?>)" title="View transaction history" style="color: var(--primary-color); text-decoration: none; font-weight: 500;">
                            <?= htmlspecialchars($c['name']) ?>
                        </a>
                    </td>
                    <td style="font-size: 0.8125rem; color: var(--text-secondary);"><?= htmlspecialchars($c['contact_person'] ?: '—') ?></td>
                    <td style="font-size: 0.78rem; line-height: 1.3;">
                        <?= htmlspecialchars($c['email'] ?: '—') ?><br>
                        <span style="color: var(--text-muted); font-size: 0.72rem;"><?= htmlspecialchars($c['phone'] ?: '') ?></span>
                    </td>
                    <td style="font-size: 0.8125rem;"><?= htmlspecialchars($c['terms'] ?: '—') ?></td>
                    <td class="text-right" style="font-weight: 600; color: var(--text-primary); font-size: 0.8125rem; font-variant-numeric: tabular-nums;">₱<?= number_format($c['total_revenue'], 2) ?></td>
                    <td class="text-right" style="font-weight: 600; color: var(--text-primary); font-size: 0.8125rem; font-variant-numeric: tabular-nums;">₱<?= number_format($c['total_output_vat'], 2) ?></td>
                    <td class="text-right" style="font-weight: 700; color: var(--text-primary); font-size: 0.8125rem; font-variant-numeric: tabular-nums;">
                        <span>₱<?= number_format($c['current_balance'], 2) ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= $c['status'] === 'Active' ? 'badge-success' : 'badge-neutral' ?>" style="font-size: 0.65rem; padding: 2px 7px;"><?= htmlspecialchars($c['status']) ?></span>
                    </td>
                    <td class="nowrap text-center">
                        <div class="flex items-center justify-center gap-1">
                            <button class="icon-btn" title="Edit Customer" onclick='openModal(<?= json_encode($c) ?>)' style="padding: 3px 5px; border-radius: 4px; border: 1px solid var(--border-color); background: var(--bg-secondary);">
                                <i data-lucide="edit-2" style="width:13px;height:13px;"></i>
                            </button>
                            <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Delete this customer? This will not affect past transactions, which reference customers by name only.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="icon-btn text-danger" title="Delete Customer" style="padding: 3px 5px; border-radius: 4px; border: 1px solid #fee2e2; background: #fef2f2;">
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

                <div class="form-group" id="custCodeGroup" style="display:none;">
                    <label class="form-label">Customer ID</label>
                    <input type="text" id="custCode" class="form-control" readonly disabled style="background: var(--bg-secondary); font-family: monospace; font-weight: 600; color: var(--primary-color);">
                </div>

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

                <div class="form-group" style="max-width: 260px;">
                    <label class="form-label">Payment Terms</label>
                    <select name="terms" id="custTerms" class="form-control">
                        <option value="Cash">Cash</option>
                        <option value="Credit">Credit</option>
                    </select>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="cust-form" class="btn btn-primary">Save Customer</button>
        </div>
    </div>
</div>

<!-- Record Customer Transaction Modal -->
<div id="txModal" class="modal-overlay hidden">
    <div class="modal" style="width: 580px; max-width: 95vw;">
        <div class="modal-header" style="padding: 0.65rem 1rem;">
            <div>
                <h2 style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.4rem;" id="txModalTitle">
                    <i data-lucide="file-text" style="width: 16px; height: 16px; color: #1d4ed8;"></i>
                    New Sales Invoice (Accounts Receivable)
                </h2>
            </div>
            <button class="icon-btn" onclick="closeTxModal()"><i data-lucide="x" style="width:16px;height:16px;"></i></button>
        </div>
        <div class="modal-body" style="padding: 0.75rem 1rem;">
            <form id="tx-form" method="POST">
                <input type="hidden" name="action" value="record_transaction">

                <!-- All sales go through AR (Credit/SJ) only -->
                <input type="hidden" name="terms" id="txTerms" value="Credit">
                <div style="margin-bottom: 0.5rem;">
                    <div style="background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; border-radius: 6px; padding: 0.35rem 0.75rem; font-size: 0.78rem; font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                        <i data-lucide="file-text" style="width:13px;height:13px;"></i>
                        All sales are recorded as Accounts Receivable (Credit) &rarr; Sales Journal (SJ)
                    </div>
                </div>

                <div class="flex gap-3" style="margin-bottom: 0.5rem;">
                    <div class="form-group" style="flex: 2; margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.2rem;">Customer <span class="required">*</span></label>
                        <!-- Searchable customer combo -->
                        <div style="position: relative;">
                            <input type="text" id="txCustomerSearch" class="form-control" autocomplete="off"
                                style="font-size: 0.78rem; padding: 0.35rem 0.55rem; height: 32px;"
                                oninput="filterCustomerList()" onfocus="showCustomerList()">
                            <input type="hidden" name="customer_id" id="txCustomerId" required>
                            <div id="txCustomerDropdown" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid var(--border-color); border-top:none; border-radius:0 0 6px 6px; max-height:180px; overflow-y:auto; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,0.1);">
                                <?php foreach($customers as $cust): ?>
                                <div class="cust-opt" data-id="<?= $cust['id'] ?>" data-terms="<?= htmlspecialchars($cust['terms'] ?? 'Cash') ?>"
                                    data-label="<?= htmlspecialchars(($cust['code'] ? '[' . $cust['code'] . '] ' : '') . $cust['name']) ?>"
                                    style="padding:5px 8px; cursor:pointer; font-size:0.78rem;"
                                    onmousedown="selectCustomer(this)">
                                    <?= htmlspecialchars(($cust['code'] ? '[' . $cust['code'] . '] ' : '') . $cust['name']) ?>
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
                        <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.2rem;">Service / Revenue Account <span class="required">*</span></label>
                        <select name="revenue_account_id" id="txRevenueAccountId" class="form-control" required onchange="updateTxPreview()" style="font-size: 0.78rem; padding: 0.35rem 0.55rem; height: 32px;">
                            <?php foreach ($revenueAccounts as $rev): ?>
                            <option value="<?= $rev['id'] ?>" data-name="<?= htmlspecialchars($rev['name']) ?>" <?= $rev['id'] == $defaultRevenueId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($rev['code'] . ' - ' . $rev['name']) ?>
                            </option>
                            <?php endforeach; ?>
                            <?php if (empty($revenueAccounts)): ?>
                            <option value="0" data-name="Service Revenue">Service Revenue (Default)</option>
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
                        <div id="txRoutingBadge" style="font-size: 0.7rem; font-weight: 700; padding: 2px 7px; border-radius: 5px; background: #dbeafe; color: #1d4ed8;">
                            Sales Journal (SJ)
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

<!-- Customer Transaction History Modal -->
<div id="historyModal" class="modal-overlay hidden">
    <div class="modal" style="width: 680px; max-width: 95vw;">
        <div class="modal-header" style="padding: 0.65rem 1rem;">
            <div>
                <h2 style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.4rem;">
                    <i data-lucide="history" style="width: 16px; height: 16px; color: var(--primary-color);"></i>
                    <span id="historyCustName">Transaction History</span>
                </h2>
                <p class="text-xs text-muted mt-1">Sales invoices, payments, and running balance for this customer.</p>
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
                    <i data-lucide="file-text" style="width: 13px; height: 13px; vertical-align: -2px;"></i> Sales Invoices
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
                        <tbody id="historyInvoiceRows"></tbody>
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
function openModal(c = null) {
    const modal = document.getElementById('custModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    if (c) {
        document.getElementById('modalTitle').innerText = 'Edit Customer';
        document.getElementById('modalDesc').innerText = 'Update customer details below.';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('custId').value = c.id;
        document.getElementById('custCodeGroup').style.display = 'block';
        document.getElementById('custCode').value = c.code || '—';
        document.getElementById('custName').value = c.name;
        document.getElementById('custStatus').value = c.status;
        document.getElementById('custContact').value = c.contact_person || '';
        document.getElementById('custEmail').value = c.email || '';
        document.getElementById('custPhone').value = c.phone || '';
        document.getElementById('custAddress').value = c.address || '';
        document.getElementById('custTin').value = c.tin || '';
        document.getElementById('custTerms').value = c.terms && ['Cash','Credit'].includes(c.terms) ? c.terms : 'Cash';
        document.getElementById('custBal').value = c.opening_balance;
        document.getElementById('custNotes').value = c.notes || '';
    } else {
        document.getElementById('modalTitle').innerText = 'New Customer';
        document.getElementById('modalDesc').innerText = "Fill in the customer's details.";
        document.getElementById('formAction').value = 'add';
        document.getElementById('custId').value = '';
        document.getElementById('custCodeGroup').style.display = 'none';
        document.getElementById('cust-form').reset();
        document.getElementById('custBal').value = '0';
        document.getElementById('custStatus').value = 'Active';
        document.getElementById('custTerms').value = 'Cash';
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

// Customer Transaction History Modal
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

function openHistoryModal(customerId, customerLabel) {
    const modal = document.getElementById('historyModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    if (window.lucide) lucide.createIcons();

    document.getElementById('historyCustName').innerText = customerLabel || 'Transaction History';
    document.getElementById('historyLoading').style.display = 'block';
    document.getElementById('historyContent').style.display = 'none';
    document.getElementById('historyError').style.display = 'none';
    document.getElementById('historyBalance').innerText = '₱0.00';

    fetch('customer_history_api.php?id=' + encodeURIComponent(customerId))
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
            renderHistoryRows('historyInvoiceRows', data.invoices);
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

// Transaction Modal Handlers
function openTxModal(c = null) {
    const modal = document.getElementById('txModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';

    // Reset search combo
    document.getElementById('txCustomerSearch').value = '';
    document.getElementById('txCustomerId').value = '';
    document.getElementById('txDescription').value = '';
    hideCustomerList();

    if (c) {
        const label = (c.code ? '[' + c.code + '] ' : '') + c.name;
        document.getElementById('txCustomerSearch').value = label;
        document.getElementById('txCustomerId').value = c.id;
    }
    // Always default to Credit (AR) - no cash sales
    setTxTerms('Credit');
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
    // Always force Credit (AR) - no cash sales allowed
    document.getElementById('txTerms').value = 'Credit';
    updateTxPreview();
}

// Searchable customer combo functions
function showCustomerList() {
    filterCustomerList();
    document.getElementById('txCustomerDropdown').style.display = 'block';
}
function hideCustomerList() {
    setTimeout(() => { document.getElementById('txCustomerDropdown').style.display = 'none'; }, 150);
}
function filterCustomerList() {
    const q = document.getElementById('txCustomerSearch').value.toLowerCase();
    const opts = document.querySelectorAll('#txCustomerDropdown .cust-opt');
    let any = false;
    opts.forEach(opt => {
        const match = opt.dataset.label.toLowerCase().includes(q);
        opt.style.display = match ? 'block' : 'none';
        if (match) any = true;
    });
    document.getElementById('txCustomerDropdown').style.display = any ? 'block' : 'none';
    document.getElementById('txCustomerId').value = '';
}
function selectCustomer(el) {
    document.getElementById('txCustomerSearch').value = el.dataset.label;
    document.getElementById('txCustomerId').value = el.dataset.id;
    document.getElementById('txCustomerDropdown').style.display = 'none';
    // Always use Credit (AR) regardless of customer default terms
    setTxTerms('Credit');
    updateTxPreview();
}
document.getElementById('txCustomerSearch').addEventListener('blur', hideCustomerList);
document.addEventListener('mouseover', function(e) {
    if (e.target.classList.contains('cust-opt')) e.target.style.background = '#f1f5f9';
});
document.addEventListener('mouseout', function(e) {
    if (e.target.classList.contains('cust-opt')) e.target.style.background = '';
});

function updateTxPreview() {
    const terms = 'Credit'; // Always AR/Credit
    const amountVal = parseFloat(document.getElementById('txAmount').value) || 0;
    const isVatable = document.getElementById('txVatableYes').checked;
    const isInclusive = document.getElementById('txVatInclusive').checked;

    // Toggle inclusive group visibility
    const inclusiveGroup = document.getElementById('txVatInclusiveGroup');
    if (inclusiveGroup) {
        inclusiveGroup.style.display = isVatable ? 'flex' : 'none';
    }

    // Always Sales Journal (SJ)
    const badge = document.getElementById('txRoutingBadge');
    if (badge) {
        badge.innerText = 'Sales Journal (SJ)';
        badge.style.background = '#dbeafe';
        badge.style.color = '#1d4ed8';
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

    // Revenue Account Title
    const revSelect = document.getElementById('txRevenueAccountId');
    let revName = 'Service Revenue';
    if (revSelect && revSelect.options.length > 0 && revSelect.selectedIndex >= 0) {
        revName = revSelect.options[revSelect.selectedIndex].text.replace(/^[0-9-]+\s*-\s*/, '') || 'Service Revenue';
    }

    const linesBody = document.getElementById('txPreviewLines');
    let html = '';

    // Customer Accounting (always AR/Credit):
    // Debit Accounts Receivable
    // Credit Service Revenue
    // Credit Output VAT if VATable
    const debitAccountName = 'Accounts Receivable';

    // Debit Line
    html += `<tr>
        <td style="padding: 3px 5px; font-weight: 600; color: #1e293b; font-size: 0.78rem;">${debitAccountName}</td>
        <td style="text-align: right; padding: 3px 5px; font-weight: 600; color: #0f172a; font-size: 0.78rem;">₱${total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
        <td style="text-align: right; padding: 3px 5px; color: #94a3b8; font-size: 0.78rem;">—</td>
    </tr>`;

    // Credit Revenue Line
    html += `<tr>
        <td style="padding: 3px 5px; padding-left: 1.25rem; color: #475569; font-size: 0.78rem;">${revName}</td>
        <td style="text-align: right; padding: 3px 5px; color: #94a3b8; font-size: 0.78rem;">—</td>
        <td style="text-align: right; padding: 3px 5px; color: #0f172a; font-size: 0.78rem;">₱${net.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
    </tr>`;

    // Credit Output VAT Line (if VATable)
    if (isVatable && vat > 0) {
        html += `<tr>
            <td style="padding: 3px 5px; padding-left: 1.25rem; color: #d97706; font-weight: 500; font-size: 0.78rem;">Output VAT (12%)</td>
            <td style="text-align: right; padding: 3px 5px; color: #94a3b8; font-size: 0.78rem;">—</td>
            <td style="text-align: right; padding: 3px 5px; color: #d97706; font-weight: 500; font-size: 0.78rem;">₱${vat.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
        </tr>`;
    }

    linesBody.innerHTML = html;

    const totalCredit = isVatable ? (net + vat) : net;
    document.getElementById('txPreviewTotalDr').innerText = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('txPreviewTotalCr').innerText = '₱' + totalCredit.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
</script>

<?php require_once '../includes/footer.php'; ?>