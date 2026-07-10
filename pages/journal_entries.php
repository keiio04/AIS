<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';

$db = get_db();
// Siguraduhing nandiyan ang 'is_taxable' column sa journal_entries mo base sa schema mo
try { $db->query("ALTER TABLE journal_entries ADD COLUMN is_taxable TINYINT(1) NOT NULL DEFAULT 0 AFTER description"); } catch (Exception $e) {}

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

// Hanapin ang IDs ng Input VAT at Output VAT accounts ng company
$inputVatId = null;
$outputVatId = null;
foreach ($accountsList as $acc) {
    if (strpos(strtolower($acc['name']), 'input vat') !== false) {
        $inputVatId = $acc['id'];
    } elseif (strpos(strtolower($acc['name']), 'output vat') !== false) {
        $outputVatId = $acc['id'];
    }
}

// Fetch company's tax registration status
$stmtCo = $db->prepare("SELECT tax_registered FROM companies WHERE id = ?");
$stmtCo->bind_param('i', $company_id);
$stmtCo->execute();
$companyIsTaxRegistered = (bool)($stmtCo->get_result()->fetch_assoc()['tax_registered'] ?? false);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_entry' || $_POST['action'] === 'edit_entry') {
        $action = $_POST['action'];
        $entry_id = ($action === 'edit_entry') ? (int)($_POST['entry_id'] ?? 0) : null;
        
        $date = $_POST['date'];
        $ref_no = $_POST['reference_no'];
        if (empty(trim($ref_no))) {
            $ref_no = 'JV-' . str_replace('-', '', $date) . '-' . rand(1000, 9999);
        }
        $description = trim($_POST['description'] ?? '');
        $is_taxable = ($companyIsTaxRegistered && isset($_POST['is_taxable']) && $_POST['is_taxable'] === '1') ? 1 : 0;
        $particulars = '';
        $type = 'Operating';
        $vendor_name = trim($_POST['vendor_name'] ?? '');
        if ($vendor_name === '') $vendor_name = null;

        $account_ids = $_POST['account_id'] ?? [];
        $debits = $_POST['debit'] ?? [];
        $credits = $_POST['credit'] ?? [];

        if ($action === 'edit_entry') {
            $check = $db->prepare("SELECT id FROM journal_entries WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
            $check->bind_param('ii', $entry_id, $company_id);
            $check->execute();
            if (!$check->get_result()->fetch_assoc()) {
                $error = "Journal entry not found.";
                $action = 'error';
            }
        }

        if ($action !== 'error') {
            $db->begin_transaction();
            try {
                if ($action === 'add_entry') {
                    $journal_id = 'GJ';
                    $stmt = $db->prepare("INSERT INTO journal_entries (company_id, reference_no, date, description, is_taxable, particulars, type, vendor_name, journal_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('isssissss', $company_id, $ref_no, $date, $description, $is_taxable, $particulars, $type, $vendor_name, $journal_id);
                    $stmt->execute();
                    $entry_id = $stmt->insert_id;
                } else {
                    $stmt = $db->prepare("UPDATE journal_entries SET reference_no = ?, date = ?, description = ?, is_taxable = ?, vendor_name = ? WHERE id = ? AND company_id = ?");
                    $stmt->bind_param('sssisii', $ref_no, $date, $description, $is_taxable, $vendor_name, $entry_id, $company_id);
                    $stmt->execute();

                    $stmtDelLines = $db->prepare("DELETE FROM journal_entry_lines WHERE journal_entry_id = ?");
                    $stmtDelLines->bind_param('i', $entry_id);
                    $stmtDelLines->execute();
                }

                // --- BACKEND VAT LOGIC START ---
                $final_lines = [];
                for ($i = 0; $i < count($account_ids); $i++) {
                    $acc_id = (int)$account_ids[$i];
                    $dr = (float)str_replace(',', '', $debits[$i] ?: 0);
                    $cr = (float)str_replace(',', '', $credits[$i] ?: 0);
                    if ($acc_id > 0 && ($dr > 0 || $cr > 0)) {
                        $final_lines[] = ['account_id' => $acc_id, 'debit' => $dr, 'credit' => $cr];
                    }
                }

                if ($is_taxable) {
                    $added_input_vat = 0;
                    $added_output_vat = 0;

                    foreach ($final_lines as &$line) {
                        $cat = '';
                        foreach ($accountsList as $a) {
                            if ($a['id'] == $line['account_id']) { $cat = $a['category']; break; }
                        }
                        
                        if ($cat === 'Expenses' || $cat === 'Assets') {
                            if ($line['debit'] > 0 && $inputVatId && $line['account_id'] != $inputVatId && $line['account_id'] != $outputVatId) {
                                // Exclude Cash and AR from input VAT calculation
                                $name_lower = '';
                                foreach ($accountsList as $a) {
                                    if ($a['id'] == $line['account_id']) { $name_lower = strtolower($a['name']); break; }
                                }
                                if (strpos($name_lower, 'cash') === false && strpos($name_lower, 'receivable') === false) {
                                    $vat = $line['debit'] * 0.12;
                                    $added_input_vat += $vat;
                                }
                            }
                        } elseif ($cat === 'Revenue') {
                            if ($line['credit'] > 0 && $outputVatId && $line['account_id'] != $inputVatId && $line['account_id'] != $outputVatId) {
                                $vat = $line['credit'] * 0.12;
                                $added_output_vat += $vat;
                            }
                        }
                    }
                    unset($line);

                    if ($added_input_vat > 0) {
                        $final_lines[] = ['account_id' => $inputVatId, 'debit' => $added_input_vat, 'credit' => 0];
                        foreach ($final_lines as &$line) {
                            if ($line['credit'] > 0 && $line['account_id'] != $inputVatId && $line['account_id'] != $outputVatId) {
                                $line['credit'] += $added_input_vat;
                                break;
                            }
                        }
                        unset($line);
                    }
                    
                    if ($added_output_vat > 0) {
                        $final_lines[] = ['account_id' => $outputVatId, 'debit' => 0, 'credit' => $added_output_vat];
                        foreach ($final_lines as &$line) {
                            if ($line['debit'] > 0 && $line['account_id'] != $inputVatId && $line['account_id'] != $outputVatId) {
                                $line['debit'] += $added_output_vat;
                                break;
                            }
                        }
                        unset($line);
                    }
                }
                
                $stmtLine = $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
                $total_debit = 0;
                foreach ($final_lines as $line) {
                    $dr = $line['debit'];
                    $cr = $line['credit'];
                    $total_debit += $dr;
                    $stmtLine->bind_param('iidd', $entry_id, $line['account_id'], $dr, $cr);
                    $stmtLine->execute();
                }
                // --- BACKEND VAT LOGIC END ---

                $user_id = $_SESSION['user_id'];
                $log_action = ($action === 'add_entry') 
                    ? "Created Journal Entry | Ref: $ref_no | Amount: ₱" . number_format($total_debit, 2)
                    : "Edited Journal Entry #$entry_id | Ref: $ref_no | Amount: ₱" . number_format($total_debit, 2);
                
                $logStmt = $db->prepare("INSERT INTO activity_logs (company_id, user_id, action) VALUES (?, ?, ?)");
                $logStmt->bind_param('iis', $company_id, $user_id, $log_action);
                $logStmt->execute();

                $db->commit();
                header("Location: journal_entries.php");
                exit;
            } catch (Exception $e) {
                $db->rollback();
                $error = "Failed to save journal entry: " . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $delete_id = (int)$_POST['id'];
        $stmtDel = $db->prepare("UPDATE journal_entries SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND company_id = ?");
        $stmtDel->bind_param('ii', $delete_id, $company_id);
        $stmtDel->execute();
        
        $user_id = $_SESSION['user_id'];
        $log_action = "Moved Journal Entry #$delete_id to Trash";
        $stmtLog = $db->prepare("INSERT INTO activity_logs (company_id, user_id, action) VALUES (?, ?, ?)");
        $stmtLog->bind_param('iis', $company_id, $user_id, $log_action);
        $stmtLog->execute();
        
        header("Location: journal_entries.php");
        exit;
    }
}

// Fetch existing journal entries
$query = "
    SELECT e.*, 
           (SELECT SUM(debit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_debit,
           (SELECT SUM(credit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_credit
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
        <p class="page-subtitle"><?= count($transactions) ?> entries recorded. Double-entry bookkeeping enforced.</p>
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
                    <th style="width: 22%">Account Title</th>
                    <th style="width: 15%">Name</th>
                    <th style="width: 18%">Description</th>
                    <th style="width: 13%">Ref No. / Account Code</th>
                    <th class="text-right" style="width: 10%">Debit</th>
                    <th class="text-right" style="width: 10%">Credit</th>
                    <th style="width: 2%"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($transactions as $tx): 
                    $stmtLine = $db->prepare("SELECT l.*, a.code, a.name FROM journal_entry_lines l JOIN accounts a ON l.account_id = a.id WHERE l.journal_entry_id = ?");
                    $stmtLine->bind_param('i', $tx['id']);
                    $stmtLine->execute();
                    $lines = $stmtLine->get_result()->fetch_all(MYSQLI_ASSOC);
                    $totalDebit = 0;
                    $totalCredit = 0;
                ?>
                <?php foreach($lines as $index => $line): 
                    $totalDebit += $line['debit'];
                    $totalCredit += $line['credit'];
                ?>
                <tr>
                    <td>
                        <?= $index === 0 ? '<strong>' . date('M d, Y', strtotime($tx['date'])) . '</strong><br>' : '' ?>
                    </td>
                    <td style="padding-left: <?= $line['credit'] > 0 ? '2.5rem' : '1rem' ?>; font-weight: 500;">
                        <?= htmlspecialchars($line['name']) ?>
                    </td>
                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        <?= $index === 0 ? htmlspecialchars($tx['vendor_name'] ?? '') : '' ?>
                    </td>
                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        <?= $index === 0 ? nl2br(htmlspecialchars($tx['description'] ?? '')) : '' ?>
                    </td>
                    <td style="font-family: monospace; font-size: 0.85rem;">
                        <?php if ($index === 0): ?>
                            <span style="background: #e2e8f0; color: #475569; padding: 2px 4px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; margin-bottom: 2px; display: inline-block;" title="Journal Type"><?= htmlspecialchars($tx['journal_id']) ?></span>
                            <span style="background: <?= (isset($tx['is_taxable']) && $tx['is_taxable']) ? '#fef9c3' : '#f1f5f9' ?>; color: <?= (isset($tx['is_taxable']) && $tx['is_taxable']) ? '#854d0e' : '#64748b' ?>; padding: 2px 4px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; margin-bottom: 2px; display: inline-block;" title="Taxability"><?= (isset($tx['is_taxable']) && $tx['is_taxable']) ? 'TAXABLE' : 'NOT TAXABLE' ?></span><br>
                            <?= $tx['reference_no'] ? '<strong>'.htmlspecialchars($tx['reference_no']).'</strong><br>' : '' ?>
                        <?php endif; ?>
                        <span style="color: var(--primary-color)"><?= htmlspecialchars($line['code']) ?></span>
                    </td>
                    <td class="text-right"><?= $line['debit'] > 0 ? '₱'.number_format($line['debit'], 2) : '' ?></td>
                    <td class="text-right"><?= $line['credit'] > 0 ? '₱'.number_format($line['credit'], 2) : '' ?></td>
                    <td class="text-center" style="vertical-align: middle;">
                        <?php if ($index === 0): ?>
                        <div class="flex gap-1" style="justify-content: center;">
                            <button type="button" style="background: none; border: none; cursor: pointer; color: var(--primary-color);" title="Edit Entry" onclick='openEditModal(<?= json_encode([
                                "id" => $tx['id'],
                                "date" => $tx['date'],
                                "reference_no" => $tx['reference_no'],
                                "description" => $tx['description'],
                                "is_taxable" => $tx['is_taxable'] ?? 0,
                                "vendor_name" => $tx['vendor_name'],
                                "lines" => array_map(function($l) {
                                    return [
                                        "account_id" => $l['account_id'],
                                        "debit" => $l['debit'],
                                        "credit" => $l['credit'],
                                    ];
                                }, $lines)
                            ]) ?>)'>
                                <i data-lucide="edit-2" style="width:15px;height:15px;"></i>
                            </button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Move this entry to Trash Bin?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $tx['id'] ?>">
                                <button type="submit" style="background: none; border: none; cursor: pointer; color: #ef4444;" title="Move to Trash">
                                    <i data-lucide="trash-2" style="width:15px;height:15px;"></i>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr style="background-color: #f8fafc;">
                    <td colspan="5" class="text-right" style="font-weight: 600; padding-right: 1rem;">Total</td>
                    <td class="text-right" style="font-weight: 600;">₱<?= number_format($totalDebit, 2) ?></td>
                    <td class="text-right" style="font-weight: 600;">₱<?= number_format($totalCredit, 2) ?></td>
                    <td></td>
                </tr>
                <tr><td colspan="8" style="border-bottom: 2px solid var(--border-color); padding: 0;"></td></tr>
                <?php endforeach; ?>
                <?php if(count($transactions) === 0): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted" style="padding: 2rem;">No journal entries found.</td>
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
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                    <div class="form-group">
                        <label class="form-label">Date</label>
                        <input type="date" name="date" id="entryDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ref No.</label>
                        <input type="text" name="reference_no" id="entryRefNo" class="form-control">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label class="form-label">Name</label>
                    <input type="text" name="vendor_name" id="entryVendor" class="form-control">
                </div>

                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="entryDescription" class="form-control" rows="3" style="resize: vertical;"></textarea>
                </div>

                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label class="form-label">Taxability</label>
                    <div style="display: flex; gap: 1.5rem; align-items: center;">
                        <label style="display: flex; align-items: center; gap: 0.4rem; font-weight: 500; cursor: pointer;">
                            <input type="radio" name="is_taxable" id="taxableYes" value="1" style="width: auto;" onchange="onTaxChange()" <?= $companyIsTaxRegistered ? 'checked' : 'disabled' ?>>
                            Taxable (12% VAT Auto-Compute)
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.4rem; font-weight: 500; <?= $companyIsTaxRegistered ? 'opacity: 0.5; cursor: not-allowed;' : 'cursor: pointer;' ?>">
                            <input type="radio" name="is_taxable" id="taxableNo" value="0" style="width: auto;" onchange="onTaxChange()" <?= !$companyIsTaxRegistered ? 'checked' : '' ?>>
                            Not Taxable
                        </label>
                    </div>
                </div>

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
                        <tbody id="lines-container">
                            </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="1">
                                    <button type="button" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;" onclick="addLine()">
                                        <i data-lucide="plus" style="width:14px;height:14px;"></i> Add Line
                                    </button>
                                </td>
                                <td class="text-right" style="font-weight: 600;">Total</td>
                                <td class="text-right" id="total-dr" style="font-weight: 600;">₱0.00</td>
                                <td class="text-right" id="total-cr" style="font-weight: 600;">₱0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
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

<script>
const accounts = <?= json_encode($accountsList) ?>;
const companyIsTaxRegistered = <?= $companyIsTaxRegistered ? 'true' : 'false' ?>;

let lineCount = 0;


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
    const matches = listEl._matches || [];
    let idx = listEl._highlightIndex ?? -1;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        idx = Math.min(idx + 1, matches.length - 1);
        updateAccountHighlight(idx);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        idx = Math.max(idx - 1, 0);
        updateAccountHighlight(idx);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (idx >= 0 && matches[idx]) {
            const acc = matches[idx];
            inputEl.value = `${acc.code} - ${acc.name}`;
            tr.querySelector('.account-id-input').value = acc.id;
            listEl.style.display = 'none';
            
            calcTotals();
            const nextInput = tr.querySelector('.dr-input');
            if (nextInput) nextInput.focus();
        }
    } else if (e.key === 'Escape') {
        listEl.style.display = 'none';
    }
}

function onAccountSearchBlur(inputEl) {
    setTimeout(() => {
        const tr = inputEl.closest('tr');
        const listEl = getAccountDropdownEl();
        listEl.style.display = 'none';
        if (!tr.querySelector('.account-id-input').value) {
            inputEl.value = '';
        }
        calcTotals();
    }, 150);
}

function addLine(prefill = null) {
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

function onTaxChange() {
    calcTotals();
}

function calcTotals() {
    let drTotal = 0;
    let crTotal = 0;
    document.querySelectorAll('.dr-input').forEach(el => drTotal += parseNumber(el.value));
    document.querySelectorAll('.cr-input').forEach(el => crTotal += parseNumber(el.value));
    
    document.getElementById('total-dr').innerText = '₱' + drTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('total-cr').innerText = '₱' + crTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    
    const balWarn = document.getElementById('balance-warning');
    const saveBtn = document.getElementById('save-btn');
    
    if (drTotal > 0 && Math.abs(drTotal - crTotal) < 0.01) {
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

function openModal() {
    const modal = document.getElementById('entryModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    
    document.getElementById('modalTitle').innerText = 'New Journal Entry';
    document.getElementById('formAction').value = 'add_entry';
    document.getElementById('entryId').value = '';
    document.getElementById('entryRefNo').value = '';
    document.getElementById('entryVendor').value = '';
    document.getElementById('entryDescription').value = '';
    
    if (companyIsTaxRegistered) {
        document.getElementById('taxableYes').checked = true;
    } else {
        document.getElementById('taxableNo').checked = true;
    }

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
    document.getElementById('formAction').value = 'edit_entry';
    document.getElementById('entryId').value = tx.id;
    document.getElementById('entryDate').value = tx.date;
    document.getElementById('entryRefNo').value = tx.reference_no;
    document.getElementById('entryVendor').value = tx.vendor_name || '';
    document.getElementById('entryDescription').value = tx.description || '';
    
    if (tx.is_taxable == 1) {
        document.getElementById('taxableYes').checked = true;
    } else {
        document.getElementById('taxableNo').checked = true;
    }

    document.getElementById('lines-container').innerHTML = '';
    lineCount = 0;
    
    tx.lines.forEach(line => {
        addLine(line);
    });
    
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
</script>

<?php require_once '../includes/footer.php'; ?>
