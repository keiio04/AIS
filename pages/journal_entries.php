<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';
require_once '../includes/journal_vat.php';

$db = get_db();
journal_vat_ensure_schema($db);

$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to view journal entries.</div>';
    require_once '../includes/footer.php';
    exit;
}

// Fetch all accounts for the dropdowns
$stmt = $db->prepare("SELECT id, code, name, category FROM accounts WHERE company_id = ? ORDER BY code ASC");
$stmt->bind_param('i', $company_id);
$stmt->execute();
$accountsList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch all customers for AR dropdown
$stmtCust = $db->prepare("SELECT id, name, 'customer' as type FROM customers WHERE company_id = ? ORDER BY name ASC");
$stmtCust->bind_param('i', $company_id);
$stmtCust->execute();
$customersList = $stmtCust->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch all suppliers for AP dropdown
$stmtSupp = $db->prepare("SELECT id, name, 'supplier' as type FROM suppliers WHERE company_id = ? ORDER BY name ASC");
$stmtSupp->bind_param('i', $company_id);
$stmtSupp->execute();
$suppliersList = $stmtSupp->get_result()->fetch_all(MYSQLI_ASSOC);

$entitiesList = array_merge($customersList, $suppliersList);
usort($entitiesList, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

// Hanapin ang IDs ng Input VAT at Output VAT accounts ng company
$vatAccountIds = journal_vat_account_ids($accountsList);
$inputVatId = $vatAccountIds['input'];
$outputVatId = $vatAccountIds['output'];

// Fetch company's tax registration status
$companyIsTaxRegistered = journal_vat_company_registration($db, (int)$company_id);
if ($companyIsTaxRegistered && (!$inputVatId || !$outputVatId)) {
    $accountsList = journal_vat_ensure_accounts($db, (int)$company_id, $accountsList);
    $vatAccountIds = journal_vat_account_ids($accountsList);
    $inputVatId = $vatAccountIds['input'];
    $outputVatId = $vatAccountIds['output'];
}

$saveError = journal_vat_save($db, [
    'company_id' => (int)$company_id,
    'journal_id' => 'GJ',
    'reference_prefix' => 'JV',
    'vat_mode' => 'auto',
    'redirect' => 'journal_entries.php',
], $accountsList, $companyIsTaxRegistered);
if ($saveError !== null) {
    $error = $saveError;
}

journal_vat_delete($db, (int)$company_id, 'GJ', 'journal_entries.php', 'General Journal');

// Fetch existing journal entries (including resolved entity name)
$query = "
    SELECT e.*,
           (SELECT SUM(debit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_debit,
           (SELECT SUM(credit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_credit,
           CASE
               WHEN e.entity_type = 'customer' THEN (SELECT name FROM customers WHERE id = e.entity_id)
               WHEN e.entity_type = 'supplier' THEN (SELECT name FROM suppliers WHERE id = e.entity_id)
               ELSE e.vendor_name
           END as entity_name
    FROM journal_entries e
    WHERE e.company_id = ? AND e.deleted_at IS NULL AND e.journal_id = 'GJ'
    ORDER BY e.date DESC, e.id DESC
";
$stmt = $db->prepare($query);
$stmt->bind_param('i', $company_id);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

require_once '../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">General Journal</h1>
    </div>
    <button class="btn btn-primary" onclick="openModal()">
        <i data-lucide="plus" style="width:15px;height:15px;"></i> New Entry
    </button>
</div>

<?php if (isset($error)): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 10%">Date</th>
                    <th style="width: 28%">Account Title</th>
                    <th style="width: 20%">Description</th>
                    <th style="width: 15%">Ref No. / Account Code</th>
                    <th class="text-right" style="width: 11%">Debit</th>
                    <th class="text-right" style="width: 11%">Credit</th>
                    <th style="width: 5%"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($transactions as $tx): 
                    $stmtLine = $db->prepare("SELECT l.*, a.code, a.name FROM journal_entry_lines l JOIN accounts a ON l.account_id = a.id WHERE l.journal_entry_id = ?");
                    $stmtLine->bind_param('i', $tx['id']);
                    $stmtLine->execute();
                    $lines = $stmtLine->get_result()->fetch_all(MYSQLI_ASSOC);
                    $editLines = journal_vat_edit_lines($lines, $tx, $vatAccountIds);
                    $totalDebit = 0;
                    $totalCredit = 0;
                ?>
                <?php foreach($lines as $index => $line): 
                    $totalDebit += $line['debit'];
                    $totalCredit += $line['credit'];
                ?>
                <tr>
                    <td>
                        <?php if ($index === 0): ?>
                            <strong><?= date('M d, Y', strtotime($tx['date'])) ?></strong>
                            <?php if (!empty($tx['entity_name'])): ?>
                                <br><span style="font-size:0.78rem; color: var(--primary-color); font-weight:600;"><?= htmlspecialchars($tx['entity_name']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="padding-left: <?= $line['credit'] > 0 ? '2.5rem' : '1rem' ?>; font-weight: 500;">
                        <?= htmlspecialchars($line['name']) ?>
                    </td>
                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        <?= $index === 0 ? nl2br(htmlspecialchars($tx['description'] ?? '')) : '' ?>
                    </td>
                    <td style="font-family: monospace; font-size: 0.85rem;">
                        <?php if ($index === 0): ?>
                            <span style="background: #e2e8f0; color: #475569; padding: 2px 4px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; margin-bottom: 2px; display: inline-block;" title="Journal Type"><?= htmlspecialchars($tx['journal_id']) ?></span>
                            <?php require '../includes/journal_tax_badge.php'; ?><br>

                            <?= $tx['reference_no'] ? '<strong>'.htmlspecialchars($tx['reference_no']).'</strong><br>' : '' ?>
                        <?php endif; ?>
                        <span style="color: var(--primary-color)"><?= htmlspecialchars($line['code']) ?></span>
                    </td>
                    <td class="text-right"><?= $line['debit'] > 0 ? '₱'.number_format($line['debit'], 2) : '' ?></td>
                    <td class="text-right"><?= $line['credit'] > 0 ? '₱'.number_format($line['credit'], 2) : '' ?></td>
                    <td class="text-center" style="vertical-align: middle;">
                        <?php if ($index === 0): ?>
                        <?php require '../includes/journal_edit_actions.php'; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr style="background-color: #f8fafc;">
                    <td colspan="4" class="text-right" style="font-weight: 600; padding-right: 1rem;">Total</td>
                    <td class="text-right" style="font-weight: 600;">₱<?= number_format($totalDebit, 2) ?></td>
                    <td class="text-right" style="font-weight: 600;">₱<?= number_format($totalCredit, 2) ?></td>
                    <td></td>
                </tr>
                <tr><td colspan="7" style="border-bottom: 2px solid var(--border-color); padding: 0;"></td></tr>
                <?php endforeach; ?>
                <?php if(count($transactions) === 0): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted" style="padding: 2rem;">No journal entries found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="entryModal" class="modal-overlay hidden">
    <div class="modal" style="width: 1100px; max-width: 95vw;">
        <div class="modal-header">
            <h2 id="modalTitle">New Journal Entry</h2>
            <button class="icon-btn" onclick="closeModal()"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="entry-form" method="POST">
                <input type="hidden" name="action" id="formAction" value="add_entry">
                <input type="hidden" name="entry_id" id="entryId" value="">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label class="form-label">Date</label>
                        <input type="date" name="date" id="entryDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ref No.</label>
                        <input type="text" name="reference_no" id="entryRefNo" class="form-control">
                    </div>
                </div>

                <!-- Header-level Name (Customer/Vendor) — optional -->
                <div class="form-group" style="margin-bottom: 1rem; position: relative;">
                    <label class="form-label">Name <span style="font-weight:400; color: var(--text-muted); font-size:0.78rem;">(Customer / Vendor — optional)</span></label>
                    <input type="text" name="vendor_name" id="entitySearchInput" class="form-control" placeholder="Search customer or vendor..." autocomplete="off"
                           oninput="onEntitySearchInput()"
                           onfocus="onEntitySearchInput()"
                           onkeydown="onEntitySearchKeydown(event)"
                           onblur="onEntitySearchBlur()">
                    <input type="hidden" name="entity_id" id="entityIdInput" value="">
                    <input type="hidden" name="entity_type" id="entityTypeInput" value="">
                </div>

                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="entryDescription" class="form-control" rows="2" style="resize: vertical;"></textarea>
                </div>

                <?php require '../includes/journal_vat_field.php'; ?>

                <div class="card" style="margin-bottom: 1.5rem; background-color: var(--bg-secondary); padding: 1rem;">
                    <table class="table" style="margin: 0;">
                        <thead>
                            <tr>
                                <th style="width: 55%;">Account</th>
                                <th style="width: 20%;" class="text-right">Debit</th>
                                <th style="width: 20%;" class="text-right">Credit</th>
                                <th style="width: 5%;"></th>
                            </tr>
                        </thead>
                        <tbody id="lines-container"></tbody>
                        <tfoot>
                            <tr>
                                <td>
                                    <button type="button" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;" onclick="addLine()">
                                        <i data-lucide="plus" style="width:14px;height:14px;"></i> Add Line
                                    </button>
                                </td>
                                <td class="text-right" style="font-weight: 600;" id="total-dr">₱0.00</td>
                                <td class="text-right" style="font-weight: 600;" id="total-cr">₱0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php require '../includes/journal_vat_summary.php'; ?>

                <div id="balance-warning" style="color: #ef4444; font-size: 0.875rem; margin-bottom: 1rem; text-align: right; display: none;">
                    Debits and Credits must balance. Difference: ₱<span id="diff-amount">0.00</span>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="entry-form" id="save-btn" class="btn btn-primary" disabled>Save Entry</button>
        </div>
    </div>
</div>

<!-- Quick Add Customer Modal -->
<div id="quickAddModal" class="modal-overlay hidden" style="z-index:10000;">
    <div class="modal" style="width: 440px; max-width: 95vw;">
        <div class="modal-header">
            <h2 id="quickAddTitle">Add New</h2>
            <button class="icon-btn" onclick="closeQuickAdd()"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Name <span style="color:#ef4444;">*</span></label>
                <input type="text" id="quickAddName" class="form-control" placeholder="Enter name...">
                <div id="quickAddError" style="color:#ef4444; font-size:0.82rem; margin-top:0.35rem; display:none;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeQuickAdd()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="saveQuickAdd()" id="quickAddSaveBtn">Save</button>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/journal-vat.js"></script>
<script>
const accounts = <?= json_encode($accountsList) ?>;
let entitiesList = <?= json_encode($entitiesList) ?>;
const companyIsTaxRegistered = <?= $companyIsTaxRegistered ? 'true' : 'false' ?>;
initJournalVat({ companyRegistered: companyIsTaxRegistered, rate: <?= json_encode(VAT_RATE) ?>, mode: 'auto', accounts });

let lineCount = 0;
let _quickAddType = null; // 'customer' or 'supplier'


function formatNumber(val) {
    const num = parseFloat(val);
    if (isNaN(num)) return '';
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function parseNumber(str) {
    if (!str) return 0;
    const cleaned = String(str).replace(/,/g, '');
    const num = parseFloat(cleaned);
    return isNaN(num) ? 0 : num;
}

function restrictDecimalInput(el) {
    let val = el.value.replace(/,/g, '');
    val = val.replace(/[^0-9.]/g, '');
    const parts = val.split('.');
    if (parts.length > 2) {
        val = parts[0] + '.' + parts.slice(1).join('');
    }
    el.value = val;
}

function unformatCurrencyInput(el) {
    if (el.value) el.value = el.value.replace(/,/g, '');
}

function formatCurrencyInput(el) {
    if (el.value === '') return;
    el.value = formatNumber(parseNumber(el.value));
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// Smart Account Dropdown
let activeAccountRow = null;
let accountDropdownEl = null;

function getAccountDropdownEl() {
    if (!accountDropdownEl) {
        accountDropdownEl = document.createElement('div');
        accountDropdownEl.id = 'account-dropdown-global';
        accountDropdownEl.style.cssText = 'display:none; position:fixed; z-index:9999; background:#fff; border:1px solid #cbd5e1; border-radius:6px; max-height:220px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.12);';
        document.body.appendChild(accountDropdownEl);
    }
    return accountDropdownEl;
}

function positionAccountDropdown(inputEl) {
    const rect = inputEl.getBoundingClientRect();
    const el = getAccountDropdownEl();
    el.style.left = rect.left + 'px';
    el.style.top = (rect.bottom + 2) + 'px';
    el.style.width = rect.width + 'px';
}

function getAccountMatches(query) {
    const q = query.trim().toLowerCase();
    if (!q) return accounts.slice(0, 50);
    return accounts.filter(acc =>
        acc.code.toLowerCase().includes(q) || acc.name.toLowerCase().includes(q)
    ).slice(0, 50);
}

function renderAccountList(tr, matches, highlightIndex = -1) {
    activeAccountRow = tr;
    const inputEl = tr.querySelector('.account-search-input');
    const listEl = getAccountDropdownEl();
    positionAccountDropdown(inputEl);
    if (matches.length === 0) {
        listEl.innerHTML = '<div style="padding:0.6rem 0.75rem; color:#64748b; font-size:0.85rem;">No matching accounts</div>';
    } else {
        listEl.innerHTML = matches.map((acc, idx) => `
            <div class="account-option" data-idx="${idx}" data-id="${acc.id}"
                 style="padding:0.5rem 0.75rem; cursor:pointer; font-size:0.85rem;"
                 onmousedown="selectAccountOption(this)">
                <span style="color:#2563eb; font-family:monospace; font-weight:600;">${escapeHtml(acc.code)}</span>
                &nbsp;—&nbsp;${escapeHtml(acc.name)}
            </div>
        `).join('');
    }
    listEl.style.display = 'block';
    listEl._matches = matches;
    updateAccountHighlight(highlightIndex);
}

function updateAccountHighlight(highlightIndex) {
    const listEl = getAccountDropdownEl();
    listEl._highlightIndex = highlightIndex;
    listEl.querySelectorAll('.account-option').forEach(opt => {
        const idx = parseInt(opt.dataset.idx, 10);
        opt.style.background = (idx === highlightIndex) ? '#f1f5f9' : '';
    });
}

function onAccountSearchInput(inputEl) {
    const tr = inputEl.closest('tr');
    tr.querySelector('.account-id-input').value = '';
    const matches = getAccountMatches(inputEl.value);
    renderAccountList(tr, matches, matches.length ? 0 : -1);
}

function selectAccountOption(optEl) {
    const tr = activeAccountRow;
    if (!tr) return;
    const idx = parseInt(optEl.dataset.idx, 10);
    const listEl = getAccountDropdownEl();
    const acc = listEl._matches[idx];
    if (!acc) return;
    tr.querySelector('.account-search-input').value = `${acc.code} - ${acc.name}`;
    tr.querySelector('.account-id-input').value = acc.id;
    listEl.style.display = 'none';
    calcTotals();
}

function onAccountSearchKeydown(e, inputEl) {
    const tr = inputEl.closest('tr');
    const listEl = getAccountDropdownEl();
    if (listEl.style.display === 'none') {
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            onAccountSearchInput(inputEl);
        }
        return;
    }
    …312 tokens truncated…n addLine(prefill = null) {
    const tr = document.createElement('tr');
    tr.id = `line-${lineCount}`;
    tr.innerHTML = `
        <td style="position: relative;">
            <input type="text" class="form-control account-search-input" placeholder="Type code or name..." autocomplete="off"
                   oninput="onAccountSearchInput(this)"
                   onfocus="onAccountSearchInput(this)"
                   onkeydown="onAccountSearchKeydown(event, this)"
                   onblur="onAccountSearchBlur(this)">
            <input type="hidden" name="account_id[]" class="account-id-input" value="">
        </td>
        <td style="min-width: 140px;">
            <input type="text" inputmode="decimal" name="debit[]" class="form-control text-right dr-input" style="min-width: 130px; font-size: 0.95rem; padding: 0.5rem 0.6rem;" placeholder="0.00" oninput="restrictDecimalInput(this); autoZero(this, 'cr'); calcTotals()" onfocus="unformatCurrencyInput(this)" onblur="formatCurrencyInput(this); calcTotals()">
        </td>
        <td style="min-width: 140px;">
            <input type="text" inputmode="decimal" name="credit[]" class="form-control text-right cr-input" style="min-width: 130px; font-size: 0.95rem; padding: 0.5rem 0.6rem;" placeholder="0.00" oninput="restrictDecimalInput(this); autoZero(this, 'dr'); calcTotals()" onfocus="unformatCurrencyInput(this)" onblur="formatCurrencyInput(this); calcTotals()">
        </td>
        <td class="text-center">
            <button type="button" class="icon-btn text-danger remove-btn" onclick="removeLine('${tr.id}')">
                <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
            </button>
        </td>
    `;
    document.getElementById('lines-container').appendChild(tr);

    if (prefill) {
        const acc = accounts.find(a => String(a.id) === String(prefill.account_id));
        tr.querySelector('.account-id-input').value = prefill.account_id ?? '';
        tr.querySelector('.account-search-input').value = acc ? `${acc.code} - ${acc.name}` : '';
        if (prefill.debit > 0) tr.querySelector('.dr-input').value = formatNumber(prefill.debit);
        if (prefill.credit > 0) tr.querySelector('.cr-input').value = formatNumber(prefill.credit);
    }

    lucide.createIcons();
    lineCount++;
    updateRemoveButtons();
    calcTotals();
    return tr;
}

function removeLine(id) {
    const lines = document.querySelectorAll('#lines-container tr');
    if (lines.length <= 2) return;
    document.getElementById(id).remove();
    updateRemoveButtons();
    calcTotals();
}

function updateRemoveButtons() {
    const lines = document.querySelectorAll('#lines-container tr');
    const btns = document.querySelectorAll('.remove-btn');
    if (lines.length <= 2) {
        btns.forEach(btn => btn.style.display = 'none');
    } else {
        btns.forEach(btn => btn.style.display = 'inline-flex');
    }
}

function autoZero(el, otherClassPrefix) {
    const val = parseNumber(el.value);
    const tr = el.closest('tr');
    const otherInput = tr.querySelector(`.${otherClassPrefix}-input`);
    if (val > 0) {
        otherInput.value = '';
    }
}

function calcTotals() {
    let drTotal = 0;
    let crTotal = 0;
    let allAccountsSelected = true;

    document.querySelectorAll('.dr-input').forEach(el => drTotal += parseNumber(el.value));
    document.querySelectorAll('.cr-input').forEach(el => crTotal += parseNumber(el.value));
    document.querySelectorAll('.account-id-input').forEach(el => { if (!el.value) allAccountsSelected = false; });

    document.getElementById('total-dr').innerText = '₱' + drTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('total-cr').innerText = '₱' + crTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});

    updateVatSummary();
    const balWarn = document.getElementById('balance-warning');
    const saveBtn = document.getElementById('save-btn');
    const isBalanced = (drTotal > 0 && Math.abs(drTotal - crTotal) < 0.01);

    if (isBalanced && allAccountsSelected) {
        balWarn.style.display = 'none';
        saveBtn.removeAttribute('disabled');
    } else {
        if (drTotal > 0 || crTotal > 0) {
            balWarn.style.display = 'block';
            document.getElementById('diff-amount').innerText = Math.abs(drTotal - crTotal).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
        }
        saveBtn.setAttribute('disabled', 'true');
    }
}

// ── Entity (Customer/Vendor) search at header level ─────────────────
let entityDropdownEl = null;
let _entityMatches = [];
let _entityHighlight = -1;

function getEntityDropdownEl() {
    if (!entityDropdownEl) {
        entityDropdownEl = document.createElement('div');
        entityDropdownEl.style.cssText = 'display:none;position:fixed;z-index:9998;background:#fff;border:1px solid #cbd5e1;border-radius:6px;max-height:220px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.12);';
        document.body.appendChild(entityDropdownEl);
    }
    return entityDropdownEl;
}

function positionEntityDropdown() {
    const inp = document.getElementById('entitySearchInput');
    if (!inp) return;
    const rect = inp.getBoundingClientRect();
    const el = getEntityDropdownEl();
    el.style.left = rect.left + 'px';
    el.style.top = (rect.bottom + 2) + 'px';
    el.style.width = rect.width + 'px';
}

function renderEntityList(matches, highlightIndex = -1) {
    _entityMatches = matches;
    _entityHighlight = highlightIndex;
    const el = getEntityDropdownEl();
    positionEntityDropdown();
    const addBtns = `<div style="padding:0.4rem 0.75rem; display:flex; gap:0.4rem; border-top:1px solid #f1f5f9; margin-top:2px;">
        <button type="button" onmousedown="openQuickAdd('customer')" style="flex:1; padding:0.35rem; font-size:0.8rem; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; border-radius:5px; cursor:pointer;">+ Add Customer</button>
        <button type="button" onmousedown="openQuickAdd('supplier')" style="flex:1; padding:0.35rem; font-size:0.8rem; background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; border-radius:5px; cursor:pointer;">+ Add Vendor</button>
    </div>`;
    if (matches.length === 0) {
        el.innerHTML = `<div style="padding:0.6rem 0.75rem; color:#64748b; font-size:0.85rem;">No records found</div>` + addBtns;
    } else {
        el.innerHTML = matches.map((e, idx) => `
            <div class="entity-opt" data-idx="${idx}"
                 style="padding:0.5rem 0.75rem; cursor:pointer; font-size:0.85rem; background:${idx === highlightIndex ? '#f1f5f9' : '#fff'};display:flex;align-items:center;gap:0.5rem;"
                 onmousedown="selectEntityOption(${idx})">
                <span style="font-size:0.7rem; padding:1px 6px; border-radius:20px; font-weight:600; background:${e.type==='customer'?'#dbeafe':'#dcfce7'}; color:${e.type==='customer'?'#1d4ed8':'#15803d'};">${e.type==='customer'?'C':'V'}</span>
                ${escapeHtml(e.name)}
            </div>`).join('') + addBtns;
    }
    el.style.display = 'block';
}

function onEntitySearchInput() {
    const q = (document.getElementById('entitySearchInput').value || '').trim().toLowerCase();
    document.getElementById('entityIdInput').value = '';
    document.getElementById('entityTypeInput').value = '';
    const matches = q ? entitiesList.filter(e => e.name.toLowerCase().includes(q)) : entitiesList.slice(0, 50);
    renderEntityList(matches, matches.length ? 0 : -1);
}

function selectEntityOption(idx) {
    const e = _entityMatches[idx];
    if (!e) return;
    document.getElementById('entitySearchInput').value = e.name;
    document.getElementById('entityIdInput').value = e.id;
    document.getElementById('entityTypeInput').value = e.type;
    getEntityDropdownEl().style.display = 'none';
}

function onEntitySearchKeydown(event) {
    const el = getEntityDropdownEl();
    if (el.style.display === 'none') {
        if (event.key === 'ArrowDown' || event.key === 'Enter') onEntitySearchInput();
        return;
    }
    if (event.key === 'ArrowDown') { event.preventDefault(); _entityHighlight = Math.min(_entityHighlight+1, _entityMatches.length-1); renderEntityList(_entityMatches, _entityHighlight); }
    else if (event.key === 'ArrowUp') { event.preventDefault(); _entityHighlight = Math.max(_entityHighlight-1, 0); renderEntityList(_entityMatches, _entityHighlight); }
    else if (event.key === 'Enter') { event.preventDefault(); if (_entityHighlight >= 0) selectEntityOption(_entityHighlight); }
    else if (event.key === 'Escape') { el.style.display = 'none'; }
}

function onEntitySearchBlur() {
    setTimeout(() => {
        getEntityDropdownEl().style.display = 'none';
        // If user typed but did not pick a valid option, clear the field
        if (!document.getElementById('entityIdInput').value) {
            document.getElementById('entitySearchInput').value = '';
            document.getElementById('entityTypeInput').value = '';
        }
    }, 180);
}

// ── Quick-Add Customer/Vendor from within Journal modal ──────────────
function openQuickAdd(type) {
    _quickAddType = type;
    document.getElementById('quickAddTitle').innerText = type === 'customer' ? 'Add New Customer' : 'Add New Vendor';
    document.getElementById('quickAddName').value = document.getElementById('entitySearchInput').value;
    document.getElementById('quickAddError').style.display = 'none';
    document.getElementById('quickAddModal').classList.remove('hidden');
    document.getElementById('quickAddModal').style.display = 'flex';
    setTimeout(() => document.getElementById('quickAddName').focus(), 50);
}

function closeQuickAdd() {
    document.getElementById('quickAddModal').classList.add('hidden');
    document.getElementById('quickAddModal').style.display = 'none';
}

async function saveQuickAdd() {
    const name = document.getElementById('quickAddName').value.trim();
    if (!name) {
        document.getElementById('quickAddError').innerText = 'Name is required.';
        document.getElementById('quickAddError').style.display = 'block';
        return;
    }
    const btn = document.getElementById('quickAddSaveBtn');
    btn.disabled = true; btn.innerText = 'Saving...';
    try {
        const endpoint = _quickAddType === 'customer' ? '../api_add_customer.php' : '../api_add_vendor.php';
        const fd = new FormData();
        fd.append('name', name);
        const res = await fetch(endpoint, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const newEntity = { id: data.id, name: data.name, type: _quickAddType === 'customer' ? 'customer' : 'supplier' };
            entitiesList.push(newEntity);
            entitiesList.sort((a,b) => a.name.localeCompare(b.name));
            document.getElementById('entitySearchInput').value = data.name;
            document.getElementById('entityIdInput').value = data.id;
            document.getElementById('entityTypeInput').value = newEntity.type;
            closeQuickAdd();
        } else {
            document.getElementById('quickAddError').innerText = data.error || 'Failed to save.';
            document.getElementById('quickAddError').style.display = 'block';
        }
    } catch(err) {
        document.getElementById('quickAddError').innerText = 'Network error. Please try again.';
        document.getElementById('quickAddError').style.display = 'block';
    }
    btn.disabled = false; btn.innerText = 'Save';
}

function openModal() {
    const modal = document.getElementById('entryModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    document.getElementById('modalTitle').innerText = 'New Journal Entry';
    document.getElementById('save-btn').innerText = 'Save Entry';
    document.getElementById('formAction').value = 'add_entry';
    document.getElementById('entryId').value = '';
    document.getElementById('entryRefNo').value = '';
    document.getElementById('entryDescription').value = '';
    document.getElementById('entitySearchInput').value = '';
    document.getElementById('entityIdInput').value = '';
    document.getElementById('entityTypeInput').value = '';
    setJournalVatStatus('taxable');
    document.getElementById('lines-container').innerHTML = '';
    lineCount = 0;
    addLine();
    addLine();
    calcTotals();
}

function openEditModal(tx) {
    const modal = document.getElementById('entryModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    document.getElementById('modalTitle').innerText = 'Edit Journal Entry';
    document.getElementById('save-btn').innerText = 'Update Entry';
    document.getElementById('formAction').value = 'edit_entry';
    document.getElementById('entryId').value = tx.id;
    document.getElementById('entryDate').value = tx.date;
    document.getElementById('entryRefNo').value = tx.reference_no;
    document.getElementById('entryDescription').value = tx.description || '';
    // Pre-fill entity
    document.getElementById('entitySearchInput').value = tx.entity_name || tx.vendor_name || '';
    document.getElementById('entityIdInput').value = tx.entity_id || '';
    document.getElementById('entityTypeInput').value = tx.entity_type || '';
    setJournalVatStatus(tx.tax_status || 'non_taxable');
    document.getElementById('lines-container').innerHTML = '';
    lineCount = 0;
    tx.lines.forEach(line => addLine(line));
    calcTotals();
}

function closeModal() {
    const modal = document.getElementById('entryModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

window.addEventListener('scroll', function() {
    const listEl = accountDropdownEl;
    if (listEl && listEl.style.display === 'block' && activeAccountRow) {
        const inputEl = activeAccountRow.querySelector('.account-search-input');
        if (inputEl) positionAccountDropdown(inputEl);
    }
}, true);

// ── Journal Type Detection & Toast (General Journal Guard) ──────────
function _isCash(acc)       { return acc.category === 'Assets' && /cash|bank/i.test(acc.name); }
function _isRevenue(acc)    { return acc.category === 'Revenue'; }
function _isExpense(acc)    { return acc.category === 'Expenses'; }
function _isPayable(acc)    { return acc.category === 'Liabilities'; }
function _isReceivable(acc) { return acc.category === 'Assets' && /receivable/i.test(acc.name); }

function _getLines() {
    const lines = [];
    document.querySelectorAll('#lines-container tr').forEach(tr => {
        const accId = tr.querySelector('.account-id-input')?.value;
        const dr = parseFloat(tr.querySelector('.dr-input')?.value) || 0;
        const cr = parseFloat(tr.querySelector('.cr-input')?.value) || 0;
        if (accId) {
            const acc = accounts.find(a => String(a.id) === String(accId));
            if (acc) lines.push({ acc, dr, cr });
        }
    });
    return lines;
}

function _detectMismatch(lines) {
    if (!lines.length) return null;
    const hasCash  = lines.some(l => _isCash(l.acc));
    const hasPay   = lines.some(l => _isPayable(l.acc));
    const hasExp   = lines.some(l => _isExpense(l.acc));
    const hasRev   = lines.some(l => _isRevenue(l.acc));
    const hasRec   = lines.some(l => _isReceivable(l.acc));

    // Cash + Revenue => Cash Receipts
    if (hasCash && hasRev)
        return { journal: 'Cash Receipts Journal', url: 'cash_receipts_journal.php', reason: 'It looks like you\'re recording a cash sale. This belongs in the', hint: 'Use the General Journal only for adjusting entries, accruals, or depreciation.' };
    // Cash + Expense => Cash Disbursements
    if (hasCash && hasExp)
        return { journal: 'Cash Disbursements Journal', url: 'cash_disbursements_journal.php', reason: 'It looks like you\'re recording a cash purchase. This belongs in the', hint: 'Use the General Journal only for adjusting entries, accruals, or depreciation.' };
    // Any other cash entry
    if (hasCash)
        return { journal: 'Cash Receipts Journal', url: 'cash_receipts_journal.php', reason: 'Entries with a Cash or Bank account belong in the', hint: 'The General Journal does not handle cash transactions.' };
    // Revenue + Receivable => Sales Journal
    if (hasRev && hasRec)
        return { journal: 'Sales Journal', url: 'sales_journal.php', reason: 'It looks like you\'re recording a credit sale. This belongs in the', hint: 'If this is an adjusting entry, please check your account selections.' };
    // Revenue only => Sales Journal
    if (hasRev)
        return { journal: 'Sales Journal', url: 'sales_journal.php', reason: 'Entries with a Revenue account belong in the', hint: 'Use the General Journal only for adjusting entries, accruals, or depreciation.' };
    // Expense + Payable => Purchases Journal
    if (hasExp && hasPay)
        return { journal: 'Purchases Journal', url: 'purchases_journal.php', reason: 'It looks like you\'re recording a credit purchase. This belongs in the', hint: 'If this is an adjusting entry, please check your account selections.' };

    return null; // OK — likely an adjusting entry (depreciation, accruals, etc.)
}

function _showJournalToast(mismatch) {
    const old = document.getElementById('jt-toast'); if (old) old.remove();
    const t = document.createElement('div');
    t.id = 'jt-toast';
    t.style.cssText = 'position:fixed;top:1.5rem;right:1.5rem;z-index:999999;background:#1e293b;color:#f1f5f9;padding:1rem 1.25rem;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.45);display:flex;flex-direction:column;gap:.5rem;max-width:420px;border-left:4px solid #ef4444;font-family:inherit;font-size:.875rem;';
    const redirectBtn = mismatch.url ? `<a href=\"${mismatch.url}\" style=\"background:transparent;color:#94a3b8;border:1px solid #475569;padding:.35rem .9rem;border-radius:6px;font-size:.78rem;font-weight:600;text-decoration:none;white-space:nowrap;transition:all 0.2s;\" onmouseover=\"this.style.color='#f8fafc';this.style.borderColor='#94a3b8'\" onmouseout=\"this.style.color='#94a3b8';this.style.borderColor='#475569'\">Move to ${mismatch.journal}</a>` : '';
    const bodyText = mismatch.url
        ? `${mismatch.reason} <strong style="color:#93c5fd;">${mismatch.journal}</strong>.` + (mismatch.hint ? ` <span style="color:#94a3b8;display:block;margin-top:4px;">${mismatch.hint}</span>` : '')
        : mismatch.reason;
    t.innerHTML = `<style>@keyframes jtIn{from{transform:translateX(110%);opacity:0}to{transform:translateX(0);opacity:1}}#jt-toast{animation:jtIn .3s cubic-bezier(.22,1,.36,1)}</style>
        <div style="display:flex;align-items:center;gap:.5rem;font-weight:700;color:#fca5a5;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            Wrong Journal
        </div>
        <div style="color:#cbd5e1;line-height:1.5;margin-top:0.25rem;">${bodyText}</div>
        <div style="display:flex;gap:.5rem;margin-top:.5rem;flex-wrap:wrap;align-items:center;">
            ${redirectBtn}
            <button onclick="document.getElementById('jt-toast').remove();" style="background:transparent;color:#6b7280;padding:.35rem .5rem;border-radius:6px;font-size:.9rem;border:none;cursor:pointer;margin-left:auto;">✕</button>
        </div>`;
    document.body.appendChild(t);
    setTimeout(() => { if (t.parentElement) t.remove(); }, 12000);
}

document.getElementById('entry-form').addEventListener('submit', function(e) {
    document.querySelectorAll('.dr-input, .cr-input').forEach(el => {
        el.value = el.value.replace(/,/g, '');
    });
    const mismatch = _detectMismatch(_getLines());
    if (mismatch) { e.preventDefault(); _showJournalToast(mismatch); }
});
</script>

<script>window.AUTOSAVE_KEY = 'autosave_journal_entries';</script>
<script src="<?= BASE_URL ?>includes/autosave.js"></script>

<?php require_once '../includes/footer.php'; ?>
