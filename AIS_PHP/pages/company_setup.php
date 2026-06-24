<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/session_guard.php';
require_once '../includes/account_seeds.php';

$db      = get_db();
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

        if (!$name) {
            $message = 'Company name is required.';
            $msgType = 'danger';
        } else {
            $ins = $db->prepare("INSERT INTO companies (user_id, name, address, business_type, fiscal_year_end) VALUES (?, ?, ?, ?, 'December 31')");
            $ins->bind_param('isss', $userId, $name, $addr, $btype);
            $ins->execute();
            $newId = $db->insert_id;

            // Seed default accounts for this business type
            seed_accounts($db, $newId, $btype);

            // Auto-set as active (always set new company as active)
            $stmtAct = $db->prepare("UPDATE users SET active_company_id = ? WHERE id = ?");
            $stmtAct->bind_param('ii', $newId, $userId);
            $stmtAct->execute();
            $_SESSION['active_company_id'] = $newId;
            $activeCompanyId = $newId;

            // Log
            $logMsg = "Created company: $name ($btype)";
            $logStmt = $db->prepare("INSERT INTO activity_logs (user_id, action) VALUES (?, ?)");
            $logStmt->bind_param('is', $userId, $logMsg);
            $logStmt->execute();

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

        if ($cid && $name) {
            $upd = $db->prepare("UPDATE companies SET name=?, address=? WHERE id=? AND user_id=?");
            $upd->bind_param('ssii', $name, $addr, $cid, $userId);
            $upd->execute();
            $logMsg = "Updated company: $name";
            $logStmt = $db->prepare("INSERT INTO activity_logs (user_id, action) VALUES (?, ?)");
            $logStmt->bind_param('is', $userId, $logMsg);
            $logStmt->execute();
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
            // Update session immediately
            $_SESSION['active_company_id'] = $cid;
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
            $logMsg = "Deleted company: $cname";
            $logStmt = $db->prepare("INSERT INTO activity_logs (user_id, action) VALUES (?, ?)");
            $logStmt->bind_param('is', $userId, $logMsg);
            $logStmt->execute();
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
  <button class="btn btn-primary" onclick="openModal('addModal')">
    <i data-lucide="plus" style="width:15px;height:15px;"></i> Add Company
  </button>
</div>

<?php if (empty($companies)): ?>
<div class="card" style="text-align: center; padding: 4rem 2rem;">
  <div style="font-size: 4rem; margin-bottom: 1rem;">🏢</div>
  <h3 style="color: var(--text-muted); margin-bottom: 0.5rem;">No Companies Yet</h3>
  <p style="color: var(--text-muted); font-size: 0.9rem;">Create your first company to start the accounting cycle.</p>
  <button class="btn btn-primary" onclick="openModal('addModal')" style="margin-top: 1.5rem;">Add Your First Company</button>
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
        <td style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($co['name']) ?></td>
        <td>
          <span style="font-size: 0.78rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-secondary);">
            <?= htmlspecialchars($co['business_type']) ?>
          </span>
        </td>
        <td style="color: var(--text-muted); font-size: 0.9rem;"><?= htmlspecialchars($co['address'] ?? '—') ?></td>
        <td>
          <div class="flex gap-2" style="align-items: center;">
            <button onclick="openEditModal(<?= $co['id'] ?>, '<?= htmlspecialchars(addslashes($co['name'])) ?>', '<?= htmlspecialchars(addslashes($co['address'] ?? '')) ?>')"
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
          <input type="text" name="name" class="form-input" placeholder="e.g. LSPU Enterprises" required>
        </div>
        <div class="form-group">
          <label class="form-label">Business Type <span class="required">*</span></label>
          <select name="business_type" class="form-input" id="btypeSelect" onchange="updateBtypeHint(this.value)">
            <option value="Service">Service Business (e.g. Consulting, Repair)</option>
            <option value="Merchandising">Merchandising Business (Buy and Sell)</option>
            <option value="Manufacturing">Manufacturing Business (Raw Materials → Products)</option>
          </select>
          <p class="form-hint" id="btypeHint">This determines the default Chart of Accounts that will be loaded.</p>
        </div>
        <div class="form-group">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-input" placeholder="e.g. San Pablo City, Laguna">
        </div>
        <div class="form-group">
          <label class="form-label">Accounting Period</label>
          <input type="text" class="form-input" value="Calendar Year — Ends December 31" disabled style="background:var(--bg-secondary);color:var(--text-secondary);">
          <p class="form-hint">As per requirements, period follows the Calendar Year ending December 31.</p>
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
        <div class="alert alert-info" style="font-size:0.8rem;">
          ℹ Business Type cannot be changed after creation (it would affect the Chart of Accounts).
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

function openEditModal(id, name, address) {
  document.getElementById('editId').value      = id;
  document.getElementById('editName').value    = name;
  document.getElementById('editAddress').value = address;
  openModal('editModal');
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
