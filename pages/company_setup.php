<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';
require_once '../includes/account_seeds.php';

$db      = get_db();
try { $db->query("ALTER TABLE companies ADD COLUMN tax_registered TINYINT(1) NOT NULL DEFAULT 0 AFTER business_type"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE companies ADD COLUMN tax_type ENUM('VAT','Percentage Tax') DEFAULT NULL AFTER tax_registered"); } catch (Exception $e) {}
$userId  = $_SESSION['user_id'];
$message = '';
$msgType = 'success';

// ── Fetch all companies of this user ──────────────────────
$stmt = $db->prepare("SELECT * FROM companies WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param('i', $userId);
$stmt->execute();
$companies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Get active company id ─────────────────────────────────
$stmtU = $db->prepare("SELECT active_company_id FROM users WHERE id = ?");
$stmtU->bind_param('i', $userId);
$stmtU->execute();
$activeCompanyId = $stmtU->get_result()->fetch_assoc()['active_company_id'] ?? null;

// ── Handle POST actions ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ADD COMPANY
    if ($action === 'add') {
        $name  = trim($_POST['name']          ?? '');
        $btype = $_POST['business_type']      ?? 'Service';
        $addr  = trim($_POST['address']       ?? '');
        $tax_registered = ($_POST['tax_registered'] ?? 'no') === 'yes' ? 1 : 0;
        $tax_type = null;
        if ($tax_registered) {
            $raw_type = $_POST['tax_type'] ?? '';
            if (in_array($raw_type, ['VAT', 'Percentage Tax'])) {
                $tax_type = $raw_type;
            }
        }
        $period_type = $_POST['period_type']  ?? 'Calendar';
        $fiscal_month = $_POST['fiscal_start_month'] ?? null;
        $fiscal_date  = $_POST['fiscal_start_date'] ?? null;

        $fiscal_end = 'December 31';
        if ($period_type === 'Fiscal' && $fiscal_month && $fiscal_date) {
            $start_str = $fiscal_month . ' ' . $fiscal_date . ' 2023';
            $end_time = strtotime($start_str . ' +1 year -1 day');
            $fiscal_end = date('F j', $end_time);
        } else {
            $period_type = 'Calendar';
            $fiscal_month = null;
            $fiscal_date = null;
        }

        if (!$name) {
            $message = 'Company name is required.';
            $msgType = 'danger';
        } else {
            $ins = $db->prepare("INSERT INTO companies (user_id, name, address, business_type, tax_registered, tax_type, period_type, fiscal_start_month, fiscal_start_date, fiscal_year_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->bind_param('isssisssss', $userId, $name, $addr, $btype, $tax_registered, $tax_type, $period_type, $fiscal_month, $fiscal_date, $fiscal_end);
            $ins->execute();
            $newId = $db->insert_id;

            // Seed default accounts for this business type
            seed_accounts($db, $newId, $btype);

            // Auto-set as active (always set new company as active)
            $stmtAct = $db->prepare("UPDATE users SET active_company_id = ? WHERE id = ?");
            $stmtAct->bind_param('ii', $newId, $userId);
            $stmtAct->execute();
            $_SESSION['active_company_id'] = $newId;
            $_SESSION['company_tax_registered'] = $tax_registered;
            $_SESSION['company_tax_type'] = $tax_type;
            $activeCompanyId = $newId;

            // Log
            log_activity($db, $userId, 'Create', 'Company Setup', "Created company: $name ($btype)");

            $message = "Company \"$name\" created successfully! Chart of Accounts has been loaded.";
            header('Location: ' . BASE_URL . 'pages/company_setup.php?msg=created');
            exit;
        }
    }

    // EDIT COMPANY
    if ($action === 'edit') {
        $cid   = (int)($_POST['company_id']   ?? 0);
        $name  = trim($_POST['name']          ?? '');
        $addr  = trim($_POST['address']       ?? '');
        $tax_registered = ($_POST['tax_registered'] ?? 'no') === 'yes' ? 1 : 0;
        $tax_type = null;
        if ($tax_registered) {
            $raw_type = $_POST['tax_type'] ?? '';
            if (in_array($raw_type, ['VAT', 'Percentage Tax'])) {
                $tax_type = $raw_type;
            }
        }
        $period_type = $_POST['period_type']  ?? 'Calendar';
        $fiscal_month = $_POST['fiscal_start_month'] ?? null;
        $fiscal_date  = $_POST['fiscal_start_date'] ?? null;

        $fiscal_end = 'December 31';
        if ($period_type === 'Fiscal' && $fiscal_month && $fiscal_date) {
            $start_str = $fiscal_month . ' ' . $fiscal_date . ' 2023';
            $end_time = strtotime($start_str . ' +1 year -1 day');
            $fiscal_end = date('F j', $end_time);
        } else {
            $period_type = 'Calendar';
            $fiscal_month = null;
            $fiscal_date = null;
        }

        if ($cid && $name) {
            $upd = $db->prepare("UPDATE companies SET name=?, address=?, tax_registered=?, tax_type=?, period_type=?, fiscal_start_month=?, fiscal_start_date=?, fiscal_year_end=? WHERE id=? AND user_id=?");
            $upd->bind_param('ssisssssii', $name, $addr, $tax_registered, $tax_type, $period_type, $fiscal_month, $fiscal_date, $fiscal_end, $cid, $userId);
            $upd->execute();
            // Update session if editing active company
            if ($cid == $activeCompanyId) {
                $_SESSION['company_tax_registered'] = $tax_registered;
                $_SESSION['company_tax_type'] = $tax_type;
            }
            log_activity($db, $userId, 'Update', 'Company Setup', "Updated company: $name");
            header('Location: ' . BASE_URL . 'pages/company_setup.php?msg=updated');
            exit;
        }
    }

    // SWITCH COMPANY
    if ($action === 'switch') {
        $cid = (int)($_POST['company_id'] ?? 0);
        if ($cid) {
            $sw = $db->prepare("UPDATE users SET active_company_id = ? WHERE id = ?");
            $sw->bind_param('ii', $cid, $userId);
            $sw->execute();
            // Load tax info for switched company into session
            $taxStmt = $db->prepare("SELECT tax_registered, tax_type FROM companies WHERE id = ?");
            $taxStmt->bind_param('i', $cid);
            $taxStmt->execute();
            $taxRow = $taxStmt->get_result()->fetch_assoc();
            $_SESSION['active_company_id'] = $cid;
            $_SESSION['company_tax_registered'] = $taxRow['tax_registered'] ?? 0;
            $_SESSION['company_tax_type'] = $taxRow['tax_type'] ?? null;
            header('Location: ' . BASE_URL . 'pages/company_setup.php?msg=switched');
            exit;
        }
    }

    // DELETE COMPANY
    if ($action === 'delete') {
        $cid = (int)($_POST['company_id'] ?? 0);
        if ($cid) {
            // Get name first for log
            $r = $db->prepare("SELECT name FROM companies WHERE id=? AND user_id=?");
            $r->bind_param('ii', $cid, $userId);
            $r->execute();
            $cname = $r->get_result()->fetch_assoc()['name'] ?? 'Unknown';

            $del = $db->prepare("DELETE FROM companies WHERE id=? AND user_id=?");
            $del->bind_param('ii', $cid, $userId);
            $del->execute();

            // If deleted was active, clear it from session too
            if ($activeCompanyId == $cid) {
                $clrStmt = $db->prepare("UPDATE users SET active_company_id = NULL WHERE id = ?");
                $clrStmt->bind_param('i', $userId);
                $clrStmt->execute();
                $_SESSION['active_company_id'] = null;
                unset($_SESSION['active_company_id']);
            }
            log_activity($db, $userId, 'Delete', 'Company Setup', "Deleted company: $cname");
            header('Location: ' . BASE_URL . 'pages/company_setup.php?msg=deleted');
            exit;
        }
    }
}

// Flash messages from redirect
if (isset($_GET['msg'])) {
    $msgs = [
        'created'  => ['Company created and Chart of Accounts loaded!', 'success'],
        'updated'  => ['Company information updated.', 'success'],
        'switched' => ['Active company switched.', 'success'],
        'deleted'  => ['Company deleted.', 'danger'],
    ];
    if (isset($msgs[$_GET['msg']])) {
        [$message, $msgType] = $msgs[$_GET['msg']];
    }
}

// Reload companies list after any changes
$stmt = $db->prepare("SELECT * FROM companies WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param('i', $userId);
$stmt->execute();
$companies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmtU = $db->prepare("SELECT active_company_id FROM users WHERE id = ?");
$stmtU->bind_param('i', $userId);
$stmtU->execute();
$activeCompanyId = $stmtU->get_result()->fetch_assoc()['active_company_id'] ?? null;

// Page setup
$pageTitle    = 'Company Setup';
$pageSubtitle = 'Manage your companies and switch between them';
$activePage   = 'company_setup';
require_once '../includes/header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $msgType ?>" style="margin-bottom: 1rem;"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>


<div class="page-header">
  <div>
    <h1 class="page-title">Company Setup</h1>
    <p class="page-subtitle">Manage your businesses, select business types, and switch active companies.</p>
  </div>
  <?php if (count($companies) > 0): ?>
  <button class="btn btn-primary" onclick="openModal('addModal')">
    <i data-lucide="plus" style="width:15px;height:15px;"></i> Add Company
  </button>
  <?php endif; ?>
</div>

<?php if (count($companies) === 0): ?>
<div style="text-align: center; padding: 3rem 1rem;">
    <h3 style="color: var(--text-muted); margin-bottom: 0.5rem; font-weight: 500;">No companies found</h3>
    <p style="color: var(--text-muted); font-size: 0.9rem;">Get started by adding your first company.</p>
    <button type="button" class="btn btn-primary" style="margin-top: 1.5rem;" onclick="openModal('addModal')">
        <i data-lucide="plus" style="width:15px;height:15px;"></i> Create Company
    </button>
</div>
<?php else: ?>

<div class="card" style="padding: 0; overflow: hidden;">
  <table class="table" style="width: 100%;">
    <thead>
      <tr>
        <th style="width: 12%; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.08em; color: var(--text-muted);">Status</th>
        <th style="width: 28%; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.08em; color: var(--text-muted);">Company Name</th>
        <th style="width: 20%; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.08em; color: var(--text-muted);">Business Type</th>
        <th style="width: 28%; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.08em; color: var(--text-muted);">Address</th>
        <th style="width: 12%; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.08em; color: var(--text-muted);">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($companies as $co):
        $isActive = ($co['id'] == $activeCompanyId);
      ?>
      <tr>
        <td>
          <?php if ($isActive): ?>
            <span style="display: inline-flex; align-items: center; gap: 4px; color: #16a34a; font-weight: 600; font-size: 0.8rem;">
              <i data-lucide="check-circle" style="width:14px;height:14px;"></i> ACTIVE
            </span>
          <?php else: ?>
            <form method="POST" style="display: inline;">
              <input type="hidden" name="action" value="switch">
              <input type="hidden" name="company_id" value="<?= $co['id'] ?>">
              <button type="submit" style="background: none; border: 1px solid var(--border-color); border-radius: 6px; padding: 3px 10px; font-size: 0.75rem; color: var(--text-muted); cursor: pointer;">
                Switch
              </button>
            </form>
          <?php endif; ?>
        </td>
        <td>
          <div style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($co['name']) ?></div>
          <div style="font-size: 0.75rem; font-weight: normal; color: var(--text-muted); margin-top: 4px;">
            <i data-lucide="calendar" style="width:12px;height:12px;display:inline-block;vertical-align:middle;margin-right:2px;"></i>
            <?php if ($co['period_type'] === 'Fiscal'): ?>
                Fiscal Year (Starts <?= htmlspecialchars($co['fiscal_start_month'] . ' ' . $co['fiscal_start_date']) ?>)
            <?php else: ?>
                Calendar Year
            <?php endif; ?>
          </div>
        </td>
        <td>
          <span style="font-size: 0.78rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-secondary);">
            <?= htmlspecialchars($co['business_type']) ?>
          </span>
          <?php if (!empty($co['tax_registered'])): ?>
            <br>
            <?php if ($co['tax_type'] === 'VAT'): ?>
              <span style="font-size: 0.7rem; font-weight: 700; color: #1e40af; background: #dbeafe; padding: 1px 8px; border-radius: 4px; display: inline-block; margin-top: 4px; letter-spacing: 0.03em;">VAT Registered</span>
            <?php elseif ($co['tax_type'] === 'Percentage Tax'): ?>
              <span style="font-size: 0.7rem; font-weight: 700; color: #92400e; background: #fef3c7; padding: 1px 8px; border-radius: 4px; display: inline-block; margin-top: 4px; letter-spacing: 0.03em;">% Tax Registered</span>
            <?php else: ?>
              <span style="font-size: 0.7rem; font-weight: 600; color: #166534; background: #dcfce7; padding: 1px 8px; border-radius: 4px; display: inline-block; margin-top: 4px;">Tax Registered</span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td style="color: var(--text-muted); font-size: 0.9rem;"><?= htmlspecialchars($co['address'] ?? '—') ?></td>

<td>
          <div class="flex gap-2" style="align-items: center;">
            <button onclick="openEditModal(<?= $co['id'] ?>, '<?= htmlspecialchars(addslashes($co['name'])) ?>', '<?= htmlspecialchars(addslashes($co['address'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($co['period_type'] ?? 'Calendar')) ?>', '<?= htmlspecialchars(addslashes($co['fiscal_start_month'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($co['fiscal_start_date'] ?? '')) ?>', <?= (int)($co['tax_registered'] ?? 0) ?>, '<?= htmlspecialchars(addslashes($co['tax_type'] ?? '')) ?>')"
              style="background: none; border: none; cursor: pointer; color: var(--text-muted); padding: 4px;" title="Edit">
              <i data-lucide="pencil" style="width:16px;height:16px;"></i>
            </button>
            <?php if (!$isActive): ?>
            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this company and ALL its data? This cannot be undone.')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="company_id" value="<?= $co['id'] ?>">
              <button type="submit" style="background: none; border: none; cursor: pointer; color: #ef4444; padding: 4px;" title="Delete">
                <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>


<!-- ── Add Company Modal ── -->
<div class="modal-overlay hidden" id="addModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Add New Company</h3>
      <button class="icon-btn" onclick="closeModal('addModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Company Name <span class="required">*</span></label>
          <input type="text" name="name" class="form-input" required>
        </div>
        <div class="form-group">
          <label class="form-label">Business Type <span class="required">*</span></label>
          <select name="business_type" class="form-input" id="btypeSelect" onchange="updateBtypeHint(this.value)">
            <option value="Service">Service Business (e.g. Consulting, Repair)</option>
            <option value="Merchandising">Merchandising Business (Buy and Sell)</option>
            <option value="Manufacturing">Manufacturing Business (Raw Materials → Products)</option>
          </select>

        </div>
        <div class="form-group">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-input">
        </div>
        <div class="form-group">
          <label class="form-label">Tax Registered?</label>
          <div style="display: flex; gap: 1.5rem; margin-top: 0.35rem;">
            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 500;">
              <input type="radio" name="tax_registered" value="no" id="addTaxNo" onchange="toggleTaxOptions('add')" checked style="width: auto;"> No
            </label>
            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 500;">
              <input type="radio" name="tax_registered" value="yes" id="addTaxYes" onchange="toggleTaxOptions('add')" style="width: auto;"> Yes
            </label>
          </div>
        </div>
        <div class="form-group hidden" id="addTaxTypeGroup">
          <label class="form-label">Tax Type</label>
          <div style="display: flex; gap: 1.5rem; margin-top: 0.35rem;">
            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 500;">
              <input type="radio" name="tax_type" value="VAT" id="addTaxVAT" checked style="width: auto;"> VAT (12%)
            </label>
            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 500;">
              <input type="radio" name="tax_type" value="Percentage Tax" id="addTaxPCT" style="width: auto;"> Percentage Tax (3%)
            </label>
          </div>

        </div>
        <div class="form-group">
          <label class="form-label">Period Type <span class="required">*</span></label>
          <select name="period_type" class="form-input" onchange="toggleFiscal(this, 'addFiscalFields')">
            <option value="Calendar">Calendar Year</option>
            <option value="Fiscal">Fiscal Year</option>
          </select>

        </div>
        <div class="form-group hidden" id="addFiscalFields">
          <label class="form-label">Fiscal Year Start <span class="required">*</span></label>
          <div style="display: flex; gap: 10px;">
            <select name="fiscal_start_month" class="form-input">

<option value="">Month</option>
              <option value="January">January</option>
              <option value="February">February</option>
              <option value="March">March</option>
              <option value="April">April</option>
              <option value="May">May</option>
              <option value="June">June</option>
              <option value="July">July</option>
              <option value="August">August</option>
              <option value="September">September</option>
              <option value="October">October</option>
              <option value="November">November</option>
              <option value="December">December</option>
            </select>
            <input type="number" name="fiscal_start_date" class="form-input" min="1" max="31" placeholder="Day (e.g. 1)">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Company</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Company Modal ── -->
<div class="modal-overlay hidden" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Edit Company</h3>
      <button class="icon-btn" onclick="closeModal('editModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="company_id" id="editId">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Company Name <span class="required">*</span></label>
          <input type="text" name="name" id="editName" class="form-input" required>
        </div>
        <div class="form-group">
          <label class="form-label">Address</label>
          <input type="text" name="address" id="editAddress" class="form-input">
        </div>
        <div class="form-group">
          <label class="form-label">Tax Registered?</label>
          <div style="display: flex; gap: 1.5rem; margin-top: 0.35rem;">
            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 500;">
              <input type="radio" name="tax_registered" value="no" id="editTaxNo" onchange="toggleTaxOptions('edit')" style="width: auto;"> No
            </label>
            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 500;">
              <input type="radio" name="tax_registered" value="yes" id="editTaxYes" onchange="toggleTaxOptions('edit')" style="width: auto;"> Yes
            </label>
          </div>
        </div>
        <div class="form-group hidden" id="editTaxTypeGroup">
          <label class="form-label">Tax Type</label>
          <div style="display: flex; gap: 1.5rem; margin-top: 0.35rem;">
            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 500;">
              <input type="radio" name="tax_type" value="VAT" id="editTaxVAT" style="width: auto;"> VAT (12%)
            </label>
            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 500;">
              <input type="radio" name="tax_type" value="Percentage Tax" id="editTaxPCT" style="width: auto;"> Percentage Tax (3%)
            </label>
          </div>

        </div>
        <div class="form-group">
          <label class="form-label">Period Type <span class="required">*</span></label>
          <select name="period_type" id="editPeriodType" class="form-input" onchange="toggleFiscal(this, 'editFiscalFields')">
            <option value="Calendar">Calendar Year</option>
            <option value="Fiscal">Fiscal Year</option>
          </select>
        </div>
        <div class="form-group hidden" id="editFiscalFields">
          <label class="form-label">Fiscal Year Start <span class="required">*</span></label>
          <div style="display: flex; gap: 10px;">
            <select name="fiscal_start_month" id="editFiscalMonth" class="form-input">
              <option value="">Month</option>
              <option value="January">January</option>
              <option value="February">February</option>
              <option value="March">March</option>
              <option value="April">April</option>
              <option value="May">May</option>
              <option value="June">June</option>
              <option value="July">July</option>
              <option value="August">August</option>
              <option value="September">September</option>
              <option value="October">October</option>
              <option value="November">November</option>
              <option value="December">December</option>
            </select>
            <input type="number" name="fiscal_start_date" id="editFiscalDate" class="form-input" min="1" max="31" placeholder="Day (e.g. 1)">
          </div>
        </div>
      </div>
      <div class="modal-footer">

<button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id) {
  const el = document.getElementById(id);
  el.classList.remove('hidden');
  el.style.display = 'flex';
}

function closeModal(id) {
  const el = document.getElementById(id);
  el.classList.add('hidden');
  el.style.display = 'none';
}

// Close modal when clicking overlay background
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.add('hidden');
    e.target.style.display = 'none';
  }
});

function openEditModal(id, name, address, period_type = 'Calendar', fmonth = '', fdate = '', taxRegistered = 0, taxType = '') {
  document.getElementById('editId').value      = id;
  document.getElementById('editName').value    = name;
  document.getElementById('editAddress').value = address;

  // Set tax registered radio
  const isRegistered = !!Number(taxRegistered);
  document.getElementById('editTaxNo').checked  = !isRegistered;
  document.getElementById('editTaxYes').checked = isRegistered;

  // Show/hide tax type group
  const taxGroup = document.getElementById('editTaxTypeGroup');
  if (isRegistered) {
    taxGroup.classList.remove('hidden');
    // Set tax type radio
    if (taxType === 'Percentage Tax') {
      document.getElementById('editTaxPCT').checked = true;
    } else {
      document.getElementById('editTaxVAT').checked = true;
    }
  } else {
    taxGroup.classList.add('hidden');
  }

  document.getElementById('editPeriodType').value = period_type;
  document.getElementById('editFiscalMonth').value = fmonth;
  document.getElementById('editFiscalDate').value = fdate;
  toggleFiscal(document.getElementById('editPeriodType'), 'editFiscalFields');
  openModal('editModal');
}

function toggleTaxOptions(prefix) {
  const isYes = document.getElementById(prefix + 'TaxYes').checked;
  const group = document.getElementById(prefix + 'TaxTypeGroup');
  if (isYes) {
    group.classList.remove('hidden');
  } else {
    group.classList.add('hidden');
  }
}

function toggleFiscal(selectEl, targetId) {
    const target = document.getElementById(targetId);
    if (selectEl.value === 'Fiscal') {
        target.classList.remove('hidden');
    } else {
        target.classList.add('hidden');
    }
}



function updateBtypeHint(val) {
  const hints = {
    'Service':       'Service businesses: consulting firms, repair shops, salons. Accounts: Service Revenue, Professional Fees.',
    'Merchandising': 'Buy-and-sell businesses. Accounts: Merchandise Inventory, Purchases, Sales, COGS.',
    'Manufacturing': 'Converts raw materials to finished products. Accounts: Raw Materials, WIP, Finished Goods, Factory Overhead.',
  };
  document.getElementById('btypeHint').textContent = hints[val] || '';
}
</script>

<?php require_once '../includes/footer.php'; ?>
