<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';

$db = get_db();
try { $db->query("ALTER TABLE journal_entry_lines ADD COLUMN description VARCHAR(255) NULL AFTER account_id"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE journal_entry_lines ADD COLUMN vendor_name VARCHAR(100) NULL AFTER description"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE activity_logs ADD COLUMN company_id INT NULL AFTER id"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE journal_entries ADD COLUMN is_taxable TINYINT(1) NOT NULL DEFAULT 0 AFTER description"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE companies ADD COLUMN tax_registered TINYINT(1) NOT NULL DEFAULT 0 AFTER business_type"); } catch (Exception $e) {}


$db = get_db();
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

// Fetch company's tax registration status (drives the default Taxable/Not Taxable value)
$stmtCo = $db->prepare("SELECT tax_registered FROM companies WHERE id = ?");
$stmtCo->bind_param('i', $company_id);
$stmtCo->execute();
$companyIsTaxRegistered = (bool)($stmtCo->get_result()->fetch_assoc()['tax_registered'] ?? false);

// Convert formatted amounts such as "1,250.00" into numeric values.
function journalAmount($value) {
    return (float)str_replace(',', '', trim((string)$value));
}

// Validate balancing, account ownership, and the rules for this special journal.
function validateJournalLines($db, $company_id, $account_ids, $debits, $credits) {
    $validLines = [];
    $totalDebit = 0.0;
    $totalCredit = 0.0;

    $rowCount = max(count($account_ids), count($debits), count($credits));
    for ($i = 0; $i < $rowCount; $i++) {
        $accountId = (int)($account_ids[$i] ?? 0);
        $debit = journalAmount($debits[$i] ?? 0);
        $credit = journalAmount($credits[$i] ?? 0);

        if ($accountId > 0 && ($debit > 0 || $credit > 0)) {
            if ($debit > 0 && $credit > 0) {
                return ['error' => 'Each account line can contain either a debit or a credit amount, not both.'];
            }
            $validLines[] = [
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
            ];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }
    }

    if (count($validLines) < 2) {
        return ['error' => 'Please enter at least two valid account lines.'];
    }
    if ($totalDebit <= 0 || abs($totalDebit - $totalCredit) >= 0.01) {
        return ['error' => 'Debits and credits must be equal before the entry can be saved.'];
    }

    $accountIds = array_values(array_unique(array_column($validLines, 'account_id')));
    $idList = implode(',', array_map('intval', $accountIds));
    $result = $db->query("SELECT id, name, category FROM accounts WHERE company_id = " . (int)$company_id . " AND id IN ($idList)");
    $accountMap = [];
    while ($account = $result->fetch_assoc()) {
        $name = strtolower($account['name']);
        $accountMap[(int)$account['id']] = [
            'name' => $account['name'],
            'category' => $account['category'],
            'is_cash' => $account['category'] === 'Assets' && (bool)preg_match('/cash|bank/i', $name),
            'is_receivable' => (bool)preg_match('/receivable/i', $name),
            'is_payable' => (bool)preg_match('/payable/i', $name),
        ];
    }

    if (count($accountMap) !== count($accountIds)) {
        return ['error' => 'One or more selected accounts are invalid for the active company.'];
    }

    $hasCash = false;
    $hasRevenue = false;
    $hasExpense = false;
    $hasPayable = false;
    foreach ($validLines as $line) {
        $account = $accountMap[$line['account_id']];
        $hasCash = $hasCash || $account['is_cash'];
        $hasRevenue = $hasRevenue || $account['category'] === 'Revenue';
        $hasExpense = $hasExpense || $account['category'] === 'Expenses';
        $hasPayable = $hasPayable || $account['is_payable'] || $account['category'] === 'Liabilities';
    }
    if ($hasCash) {
        return ['error' => 'This entry contains a Cash/Bank account and belongs in the Cash Receipts Journal, not the Sales Journal.'];
    }
    if ($hasExpense && $hasPayable) {
        return ['error' => 'This entry looks like a credit purchase and belongs in the Purchases Journal, not the Sales Journal.'];
    }
    if (!$hasRevenue) {
        return ['error' => 'Sales Journal requires at least one Revenue account.'];
    }

    return [
        'lines' => $validLines,
        'total_debit' => $totalDebit,
        'total_credit' => $totalCredit,
    ];
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_entry' || $_POST['action'] === 'edit_entry') {
        $isEdit = $_POST['action'] === 'edit_entry';
        $entry_id = (int)($_POST['entry_id'] ?? 0);
        $date = trim($_POST['date'] ?? '');
        $ref_no = trim($_POST['reference_no'] ?? '');
        if ($ref_no === '') {
            $ref_no = 'SJ-' . str_replace('-', '', $date) . '-' . rand(1000, 9999);
        }

        $description = trim($_POST['description'] ?? '');
        $is_taxable = $companyIsTaxRegistered ? 1 : (((int)($_POST['is_taxable'] ?? 0) === 1) ? 1 : 0);
        $particulars = '';
        $type = 'Operating';
        $vendor_name = trim($_POST['vendor_name'] ?? '');
        if ($vendor_name === '') $vendor_name = null;

        $account_ids = $_POST['account_id'] ?? [];
        $debits = $_POST['debit'] ?? [];
        $credits = $_POST['credit'] ?? [];

        $validation = validateJournalLines($db, $company_id, $account_ids, $debits, $credits);
        if (isset($validation['error'])) {
            $error = $validation['error'];
        } else {
            if ($isEdit) {
                $check = $db->prepare("SELECT id FROM journal_entries WHERE id = ? AND company_id = ? AND journal_id = 'SJ' AND deleted_at IS NULL");
                $check->bind_param('ii', $entry_id, $company_id);
                $check->execute();
                if (!$entry_id || !$check->get_result()->fetch_assoc()) {
                    $error = 'Journal entry not found.';
                }
            }
        }

        if (!isset($error)) {
            $db->begin_transaction();
            try {
                if ($isEdit) {
                    $stmt = $db->prepare("UPDATE journal_entries SET reference_no = ?, date = ?, description = ?, is_taxable = ?, vendor_name = ? WHERE id = ? AND company_id = ? AND journal_id = 'SJ'");
                    $stmt->bind_param('sssisii', $ref_no, $date, $description, $is_taxable, $vendor_name, $entry_id, $company_id);
                    $stmt->execute();

                    $stmtDelLines = $db->prepare("DELETE FROM journal_entry_lines WHERE journal_entry_id = ?");
                    $stmtDelLines->bind_param('i', $entry_id);
                    $stmtDelLines->execute();
                } else {
                    $journal_id = 'SJ';
                    $stmt = $db->prepare("INSERT INTO journal_entries (company_id, reference_no, date, description, is_taxable, particulars, type, vendor_name, journal_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('isssissss', $company_id, $ref_no, $date, $description, $is_taxable, $particulars, $type, $vendor_name, $journal_id);
                    $stmt->execute();
                    $entry_id = $stmt->insert_id;
                }

                $stmtLine = $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
                foreach ($validation['lines'] as $line) {
                    $accountId = $line['account_id'];
                    $debit = $line['debit'];
                    $credit = $line['credit'];
                    $stmtLine->bind_param('iidd', $entry_id, $accountId, $debit, $credit);
                    $stmtLine->execute();
                }

                $user_id = $_SESSION['user_id'];
                $verb = $isEdit ? 'Edited' : 'Created';
                $log_action = "$verb Sales Journal Entry | Ref: $ref_no | Amount: ₱" . number_format($validation['total_debit'], 2);
                $logStmt = $db->prepare("INSERT INTO activity_logs (company_id, user_id, action) VALUES (?, ?, ?)");
                $logStmt->bind_param('iis', $company_id, $user_id, $log_action);
                $logStmt->execute();

                $db->commit();
                header("Location: sales_journal.php");
                exit;
            } catch (Exception $e) {
                $db->rollback();
                $error = ($isEdit ? 'Failed to update journal entry: ' : 'Failed to save journal entry: ') . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $delete_id = (int)($_POST['id'] ?? 0);
        $stmtDel = $db->prepare("UPDATE journal_entries SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND company_id = ? AND journal_id = 'SJ'");
        $stmtDel->bind_param('ii', $delete_id, $company_id);
        $stmtDel->execute();

        $user_id = $_SESSION['user_id'];
        $log_action = "Moved Sales Journal Entry #$delete_id to Trash";
        $stmtLog = $db->prepare("INSERT INTO activity_logs (company_id, user_id, action) VALUES (?, ?, ?)");
        $stmtLog->bind_param('iis', $company_id, $user_id, $log_action);
        $stmtLog->execute();

        header("Location: sales_journal.php");
        exit;
    }
}

// Fetch existing journal entries
$query = "
    SELECT e.*, 
           (SELECT SUM(debit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_debit,
           (SELECT SUM(credit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_credit
    FROM journal_entries e 
    WHERE e.company_id = ? AND e.deleted_at IS NULL AND e.journal_id = 'SJ'
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
        <h1 class="page-title">Sales Journal</h1>
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
                    $legacyVendor = '';
                    $legacyDescription = '';
                    foreach ($lines as $legacyLine) {
                        if ($legacyVendor === '' && !empty($legacyLine['vendor_name'])) $legacyVendor = $legacyLine['vendor_name'];
                        if ($legacyDescription === '' && !empty($legacyLine['description'])) $legacyDescription = $legacyLine['description'];
                    }
                    $displayVendor = trim((string)($tx['vendor_name'] ?? '')) !== '' ? $tx['vendor_name'] : $legacyVendor;
                    $displayDescription = trim((string)($tx['description'] ?? '')) !== '' ? $tx['description'] : $legacyDescription;
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
                        <?= $index === 0 ? htmlspecialchars($displayVendor) : '' ?>
                    </td>
                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        <?= $index === 0 ? nl2br(htmlspecialchars($displayDescription)) : '' ?>
                    </td>
                    <td style="font-family: monospace; font-size: 0.85rem;">
                        <?php if ($index === 0): ?>
                            <span style="background: #e2e8f0; color: #475569; padding: 2px 4px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; margin-bottom: 2px; display: inline-block;" title="Journal Type"><?= htmlspecialchars($tx['journal_id']) ?></span>
                            <span style="background: <?= $tx['is_taxable'] ? '#fef9c3' : '#f1f5f9' ?>; color: <?= $tx['is_taxable'] ? '#854d0e' : '#64748b' ?>; padding: 2px 4px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; margin-bottom: 2px; display: inline-block;" title="Taxability"><?= $tx['is_taxable'] ? 'TAXABLE' : 'NOT TAXABLE' ?></span><br>
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
                                "description" => $displayDescription,
                                "is_taxable" => $tx['is_taxable'],
                                "vendor_name" => $displayVendor,
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

<!-- Modal -->
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
                            <input type="radio" name="is_taxable" id="taxableYes" value="1" style="width: auto;">
                            Taxable
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.4rem; font-weight: 500; <?= $companyIsTaxRegistered ? 'opacity: 0.5; cursor: not-allowed;' : 'cursor: pointer;' ?>">
                            <input type="radio" name="is_taxable" id="taxableNo" value="0" style="width: auto;" <?= $companyIsTaxRegistered ? 'disabled' : '' ?>>
                            Not Taxable
                        </label>
                    </div>
                    <?php if ($companyIsTaxRegistered): ?>

                    <?php endif; ?>
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
                            <!-- JS injected rows -->
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>
                                    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
                                        <button type="button" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;" onclick="addLine()">
                                            <i data-lucide="plus" style="width:14px;height:14px;"></i> Add Line
                                        </button>
                                        <span style="font-weight:600;">Total</span>
                                    </div>
                                </td>
                                <td class="text-right" id="total-dr" style="font-weight: 600;">₱0.00</td>
                                <td class="text-right" id="total-cr" style="font-weight: 600;">₱0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div id="balance-warning" style="color: var(--danger-color); font-size: 0.875rem; margin-bottom: 1rem; text-align: right; display: none;">
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

// ── Number formatting helpers for Debit/Credit fields ──────────────
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

// While typing: only allow digits and a single decimal point (commas added on blur)
function restrictDecimalInput(el) {
    let val = el.value.replace(/,/g, '');
    val = val.replace(/[^0-9.]/g, '');
    const parts = val.split('.');
    if (parts.length > 2) {
        val = parts[0] + '.' + parts.slice(1).join('');
    }
    el.value = val;
}

// On focus: strip commas so the raw number is easy to edit
function unformatCurrencyInput(el) {
    if (el.value) el.value = el.value.replace(/,/g, '');
}

// On blur: format with thousands separators and 2 decimal places
function formatCurrencyInput(el) {
    if (el.value === '') return;
    el.value = formatNumber(parseNumber(el.value));
}


function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ── Smart Account Search (combobox) ─────────────────────────────────
let activeAccountRow = null;   // <tr> currently associated with the open dropdown
let accountDropdownEl = null;  // shared floating dropdown, appended to <body>

function getAccountDropdownEl() {
    if (!accountDropdownEl) {
        accountDropdownEl = document.createElement('div');
        accountDropdownEl.id = 'account-dropdown-global';
        accountDropdownEl.style.cssText = 'display:none; position:fixed; z-index:9999; background:#fff; border:1px solid var(--border-color); border-radius:6px; max-height:220px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.12);';
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
        listEl.innerHTML = '<div style="padding:0.6rem 0.75rem; color:var(--text-muted); font-size:0.85rem;">No matching accounts</div>';
    } else {
        listEl.innerHTML = matches.map((acc, idx) => `
            <div class="account-option" data-idx="${idx}" data-id="${acc.id}"
                 style="padding:0.5rem 0.75rem; cursor:pointer; font-size:0.85rem;"
                 onmousedown="selectAccountOption(this)"
                 onmouseover="this.style.background='var(--bg-secondary)'"
                 onmouseout="this.style.background=''">
                <span style="color:var(--primary-color); font-family:monospace; font-weight:600;">${escapeHtml(acc.code)}</span>
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
        opt.style.background = (idx === highlightIndex) ? 'var(--bg-secondary)' : '';
    });
}

function onAccountSearchInput(inputEl) {
    const tr = inputEl.closest('tr');
    // Any manual typing invalidates the previously selected account until re-picked from the list
    tr.querySelector('.account-id-input').value = '';
    const matches = getAccountMatches(inputEl.value);
    renderAccountList(tr, matches, matches.length ? 0 : -1);
    checkValidity();
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
    checkValidity();
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
            checkValidity();
            // Move focus to the next field for fast keyboard encoding
            const nextInput = tr.querySelector('.dr-input');
            if (nextInput) nextInput.focus();
        }
    } else if (e.key === 'Escape') {
        listEl.style.display = 'none';
    }
}

function onAccountSearchBlur(inputEl) {
    // Delay so a click (mousedown) on an option registers before the list is hidden
    setTimeout(() => {
        const tr = inputEl.closest('tr');
        const listEl = getAccountDropdownEl();
        listEl.style.display = 'none';
        // If the typed text wasn't picked from the list, clear it so an invalid account can't be submitted
        if (!tr.querySelector('.account-id-input').value) {
            inputEl.value = '';
        }
        checkValidity();
    }, 150);
}

window.addEventListener('scroll', function() {
    const listEl = accountDropdownEl;
    if (listEl && listEl.style.display === 'block' && activeAccountRow) {
        const inputEl = activeAccountRow.querySelector('.account-search-input');
        if (inputEl) positionAccountDropdown(inputEl);
    }
}, true);

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
            <input type="text" inputmode="decimal" name="debit[]" class="form-control text-right dr-input" style="min-width: 130px; font-size: 0.95rem; padding: 0.5rem 0.6rem;" placeholder="0.00" oninput="restrictDecimalInput(this); autoZero(this, 'cr'); calcTotals()" onfocus="unformatCurrencyInput(this)" onblur="formatCurrencyInput(this)">
        </td>
        <td style="min-width: 140px;">
            <input type="text" inputmode="decimal" name="credit[]" class="form-control text-right cr-input" style="min-width: 130px; font-size: 0.95rem; padding: 0.5rem 0.6rem;" placeholder="0.00" oninput="restrictDecimalInput(this); autoZero(this, 'dr'); calcTotals()" onfocus="unformatCurrencyInput(this)" onblur="formatCurrencyInput(this)">
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
}

function removeLine(id) {
    const lines = document.querySelectorAll('#lines-container tr');
    if (lines.length <= 2) return; // keep at least 2
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
    document.querySelectorAll('.dr-input').forEach(el => drTotal += parseNumber(el.value));
    document.querySelectorAll('.cr-input').forEach(el => crTotal += parseNumber(el.value));
    
    document.getElementById('total-dr').innerText = '₱' + drTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('total-cr').innerText = '₱' + crTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    
    const balWarn = document.getElementById('balance-warning');
    const saveBtn = document.getElementById('save-btn');
    
    if (drTotal > 0 && Math.abs(drTotal - crTotal) < 0.01) {
        balWarn.style.display = 'none';
        document.getElementById('total-dr').style.color = 'var(--text-primary)';
        document.getElementById('total-cr').style.color = 'var(--text-primary)';
    } else {
        if (drTotal > 0 || crTotal > 0) {
            balWarn.style.display = 'block';
            document.getElementById('diff-amount').innerText = Math.abs(drTotal - crTotal).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
            document.getElementById('total-dr').style.color = 'var(--danger-color)';
            document.getElementById('total-cr').style.color = 'var(--danger-color)';
        } else {
            balWarn.style.display = 'none';
        }
    }
    checkValidity();
}

function checkValidity() {
    let drTotal = 0;
    let crTotal = 0;
    let allAccountsSelected = true;

    document.querySelectorAll('.dr-input').forEach(el => drTotal += parseNumber(el.value));
    document.querySelectorAll('.cr-input').forEach(el => crTotal += parseNumber(el.value));
    document.querySelectorAll('.account-id-input').forEach(el => {
        if(!el.value) allAccountsSelected = false;
    });

    const isBalanced = drTotal > 0 && Math.abs(drTotal - crTotal) < 0.01;
    const saveBtn = document.getElementById('save-btn');
    
    if (isBalanced && allAccountsSelected) {
        saveBtn.disabled = false;
    } else {
        saveBtn.disabled = true;
    }
}

function openModal() {
    const modal = document.getElementById('entryModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    document.getElementById('modalTitle').innerText = 'New Journal Entry';
    document.getElementById('save-btn').innerText = 'Save Entry';
    document.getElementById('formAction').value = 'add_entry';
    document.getElementById('entryId').value = '';
    document.getElementById('entryDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('entryRefNo').value = '';
    document.getElementById('entryVendor').value = '';
    document.getElementById('entryDescription').value = '';
    document.getElementById('taxableYes').checked = true; // Always defaults checked; locked to Taxable if company is tax-registered
    document.getElementById('taxableNo').checked = false;
    if (!companyIsTaxRegistered) {
        document.getElementById('taxableYes').checked = false;
        document.getElementById('taxableNo').checked = true;
    }
    document.getElementById('lines-container').innerHTML = '';
    lineCount = 0;
    addLine();
    addLine();
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
    document.getElementById('entryRefNo').value = tx.reference_no || '';
    document.getElementById('entryVendor').value = tx.vendor_name || '';
    document.getElementById('entryDescription').value = tx.description || '';
    const txIsTaxable = !!Number(tx.is_taxable);
    if (companyIsTaxRegistered) {
        // Company is tax-registered: lock to Taxable regardless of the entry's saved value
        document.getElementById('taxableYes').checked = true;
        document.getElementById('taxableNo').checked = false;
    } else {
        document.getElementById('taxableYes').checked = txIsTaxable;
        document.getElementById('taxableNo').checked = !txIsTaxable;
    }

    document.getElementById('lines-container').innerHTML = '';
    lineCount = 0;
    tx.lines.forEach(line => addLine(line));
    if (tx.lines.length < 2) {
        addLine();
    }
    calcTotals();
}

function closeModal() {
    const modal = document.getElementById('entryModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    if (accountDropdownEl) accountDropdownEl.style.display = 'none';
    activeAccountRow = null;
}

// Close on overlay click
// ── Journal Type Detection & Toast ──────────────────────────────────
function _isCash(acc)       { return acc.category === 'Assets' && /cash|bank/i.test(acc.name); }
function _isReceivable(acc) { return /receivable/i.test(acc.name); }
function _isPayable(acc)    { return /payable/i.test(acc.name); }
function _isRevenue(acc)    { return acc.category === 'Revenue'; }
function _isExpense(acc)    { return acc.category === 'Expenses'; }

function _getLines() {
    const lines = [];
    document.querySelectorAll('#lines-container tr').forEach(tr => {
        const accId = tr.querySelector('.account-id-input')?.value;
        const dr = parseNumber(tr.querySelector('.dr-input')?.value || '');
        const cr = parseNumber(tr.querySelector('.cr-input')?.value || '');
        if (accId) {
            const acc = accounts.find(a => String(a.id) === String(accId));
            if (acc) lines.push({ acc, dr, cr });
        }
    });
    return lines;
}

function _detectMismatch(lines) {
    if (!lines.length) return null;
    const hasCash = lines.some(l => _isCash(l.acc));
    const hasPay = lines.some(l => _isPayable(l.acc) || l.acc.category === 'Liabilities');
    const hasExp = lines.some(l => _isExpense(l.acc));
    const hasRev = lines.some(l => _isRevenue(l.acc));

    if (hasCash)
        return { journal: 'Cash Receipts Journal', url: 'cash_receipts_journal.php', reason: 'Entries with a Cash or Bank account belong in the' };
    if (hasExp && hasPay)
        return { journal: 'Purchases Journal', url: 'purchases_journal.php', reason: 'Credit purchases must be recorded in the' };
    if (!hasRev)
        return { journal: 'Sales Journal', url: null, reason: 'A Revenue account is required for sales entries.' };
    return null;
}

function _showJournalToast(mismatch) {
    const old = document.getElementById('jt-toast'); if (old) old.remove();
    const t = document.createElement('div');
    t.id = 'jt-toast';
    t.style.cssText = 'position:fixed;top:1.5rem;right:1.5rem;z-index:999999;background:#1e293b;color:#f1f5f9;padding:1rem 1.25rem;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.45);display:flex;flex-direction:column;gap:.5rem;max-width:400px;border-left:4px solid #ef4444;font-family:inherit;font-size:.875rem;';
    const redirectBtn = mismatch.url ? `<a href="${mismatch.url}" style="background:#3b82f6;color:#fff;padding:.35rem .9rem;border-radius:6px;font-size:.78rem;font-weight:600;text-decoration:none;white-space:nowrap;">Move to ${mismatch.journal}</a>` : '';
    const bodyText = mismatch.url ? `${mismatch.reason} <strong style="color:#93c5fd;">${mismatch.journal}</strong>.` : mismatch.reason;
    t.innerHTML = `<style>@keyframes jtIn{from{transform:translateX(110%);opacity:0}to{transform:translateX(0);opacity:1}}#jt-toast{animation:jtIn .3s cubic-bezier(.22,1,.36,1)}</style>
        <div style="display:flex;align-items:center;gap:.5rem;font-weight:700;color:#fca5a5;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            Cannot Save Entry
        </div>
        <div style="color:#cbd5e1;line-height:1.5;margin-top:0.25rem;">${bodyText}</div>
        <div style="display:flex;gap:.5rem;margin-top:.5rem;flex-wrap:wrap;align-items:center;">
            ${redirectBtn}
            <button onclick="document.getElementById('jt-toast').remove();" style="background:transparent;color:#6b7280;padding:.35rem .5rem;border-radius:6px;font-size:.9rem;border:none;cursor:pointer;margin-left:auto;">✕</button>
        </div>`;
    document.body.appendChild(t);
    setTimeout(() => { if (t.parentElement) t.remove(); }, 12000);
}

// Strip comma formatting from Debit/Credit fields right before submit so PHP parses them correctly
document.getElementById('entry-form').addEventListener('submit', function(e) {
    document.querySelectorAll('.dr-input, .cr-input').forEach(el => {
        el.value = el.value.replace(/,/g, '');
    });
    const mismatch = _detectMismatch(_getLines());
    if (mismatch) { e.preventDefault(); _showJournalToast(mismatch); }
});

document.getElementById('entryModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<script>window.AUTOSAVE_KEY = 'autosave_sales_journal';</script>
<script src="<?= BASE_URL ?>includes/autosave.js"></script>

<?php require_once '../includes/footer.php'; ?>