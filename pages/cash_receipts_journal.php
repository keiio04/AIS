<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';

$db = get_db();
try { $db->query("ALTER TABLE journal_entry_lines ADD COLUMN description VARCHAR(255) NULL AFTER account_id"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE journal_entry_lines ADD COLUMN vendor_name VARCHAR(100) NULL AFTER description"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE activity_logs ADD COLUMN company_id INT NULL AFTER id"); } catch (Exception $e) {}


$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to view journal entries.</div>';
    require_once '../includes/footer.php';
    exit;
}

// Fetch all accounts for the dropdowns
$stmt = $db->prepare("SELECT id, code, name FROM accounts WHERE company_id = ? ORDER BY code ASC");
$stmt->bind_param('i', $company_id);
$stmt->execute();
$accountsList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_entry') {
    $date = $_POST['date'];
    $ref_no = $_POST['reference_no'];
    if (empty(trim($ref_no))) {
        $ref_no = 'CRJ-' . str_replace('-', '', $date) . '-' . rand(1000, 9999);
    }
    $description = '';
    $particulars = '';
    $type = 'Operating';
    $vendor_name = null; // No longer at header level

    $account_ids = $_POST['account_id'] ?? [];
    $line_descriptions = $_POST['line_description'] ?? [];
    $line_vendors = $_POST['line_vendor'] ?? [];
    $debits = $_POST['debit'] ?? [];
    $credits = $_POST['credit'] ?? [];

    $db->begin_transaction();
    try {
        $journal_id = 'CRJ';
        $stmt = $db->prepare("INSERT INTO journal_entries (company_id, reference_no, date, description, particulars, type, vendor_name, journal_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssssss', $company_id, $ref_no, $date, $description, $particulars, $type, $vendor_name, $journal_id);
        $stmt->execute();
        $entry_id = $stmt->insert_id;

        $stmtLine = $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, description, vendor_name, debit, credit) VALUES (?, ?, ?, ?, ?, ?)");
        
        $total_debit = 0;
        for ($i = 0; $i < count($account_ids); $i++) {
            $acc_id = (int)$account_ids[$i];
            $line_desc = $line_descriptions[$i] ?? '';
            $line_vendor = $line_vendors[$i] ?? null;
            if ($line_vendor === '') $line_vendor = null;
            $dr = (float)($debits[$i] ?: 0);
            $cr = (float)($credits[$i] ?: 0);

            if ($acc_id > 0 && ($dr > 0 || $cr > 0)) {
                $total_debit += $dr;
                $stmtLine->bind_param('iissdd', $entry_id, $acc_id, $line_desc, $line_vendor, $dr, $cr);
                $stmtLine->execute();
            }
        }

        // Add to activity log
        $user_id = $_SESSION['user_id'];
        $log_action = "Created Journal Entry | Ref: $ref_no | Amount: ₱" . number_format($total_debit, 2);
        $logStmt = $db->prepare("INSERT INTO activity_logs (user_id, action) VALUES (?, ?)");
        $logStmt->bind_param('is', $user_id, $log_action);
        $logStmt->execute();

        $db->commit();
        header("Location: cash_receipts_journal.php");
        exit;
    } catch (Exception $e) {
        $db->rollback();
        $error = "Failed to save journal entry: " . $e->getMessage();
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
    
    header("Location: cash_receipts_journal.php");
    exit;
}
}

// Fetch existing journal entries
$query = "
    SELECT e.*, 
           (SELECT SUM(debit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_debit,
           (SELECT SUM(credit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_credit
    FROM journal_entries e 
    WHERE e.company_id = ? AND e.deleted_at IS NULL AND e.journal_id = 'CRJ'
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
        <h1 class="page-title">Cash Receipts Journal</h1>
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
                        <?php 
                        $disp_vendor = !empty($line['vendor_name']) ? $line['vendor_name'] : ($index === 0 && !empty($tx['vendor_name']) ? $tx['vendor_name'] : '');
                        echo htmlspecialchars($disp_vendor);
                        ?>
                    </td>
                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        <?= htmlspecialchars($line['description'] ?? '') ?>
                    </td>
                    <td style="font-family: monospace; font-size: 0.85rem;">
                        <?php if ($index === 0): ?>
                            <span style="background: #e2e8f0; color: #475569; padding: 2px 4px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; margin-bottom: 2px; display: inline-block;" title="Journal Type"><?= htmlspecialchars($tx['journal_id']) ?></span><br>
                            <?= $tx['reference_no'] ? '<strong>'.htmlspecialchars($tx['reference_no']).'</strong><br>' : '' ?>
                        <?php endif; ?>
                        <span style="color: var(--primary-color)"><?= htmlspecialchars($line['code']) ?></span>
                    </td>
                    <td class="text-right"><?= $line['debit'] > 0 ? '₱'.number_format($line['debit'], 2) : '' ?></td>
                    <td class="text-right"><?= $line['credit'] > 0 ? '₱'.number_format($line['credit'], 2) : '' ?></td>
                    <td class="text-center" style="vertical-align: middle;">
                        <?php if ($index === 0): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Move this entry to Trash Bin?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $tx['id'] ?>">
                            <button type="submit" style="background: none; border: none; cursor: pointer; color: #ef4444;" title="Move to Trash">
                                <i data-lucide="trash-2" style="width:15px;height:15px;"></i>
                            </button>
                        </form>
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
            <h2>New Journal Entry</h2>
            <button class="icon-btn" onclick="closeModal()"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
        </div>
        <div class="modal-body">
            <form id="entry-form" method="POST">
                <input type="hidden" name="action" value="add_entry">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                    <div class="form-group">
                        <label class="form-label">Date</label>
                        <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ref No. <span style="font-size: 0.75rem; font-weight: normal;">(Auto if blank)</span></label>
                        <input type="text" name="reference_no" class="form-control" placeholder="e.g. JV-001">
                    </div>
                </div>

                <div class="card" style="margin-bottom: 1.5rem; background-color: var(--bg-secondary); padding: 1rem;">
                    <table class="table" style="margin: 0;">
                        <thead>
                            <tr>
                                <th style="width: 20%;">Account</th>
                                <th style="width: 22%;">Name</th>
                                <th style="width: 25%;">Description</th>
                                <th style="width: 14%;" class="text-right">Debit</th>
                                <th style="width: 14%;" class="text-right">Credit</th>
                                <th style="width: 5%;"></th>
                            </tr>
                        </thead>
                        <tbody id="lines-container">
                            <!-- JS injected rows -->
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2">
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

let lineCount = 0;

function createAccountOptions() {
    let html = '<option value="">Select Account...</option>';
    accounts.forEach(acc => {
        html += `<option value="${acc.id}">${acc.code} - ${acc.name}</option>`;
    });
    return html;
}

function addLine() {
    const tr = document.createElement('tr');
    tr.id = `line-${lineCount}`;
    tr.innerHTML = `
        <td>
            <select name="account_id[]" class="form-control" onchange="checkValidity()" required>
                ${createAccountOptions()}
            </select>
        </td>
        <td>
            <input type="text" name="line_vendor[]" class="form-control">
        </td>
        <td>
            <input type="text" name="line_description[]" class="form-control">
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="debit[]" class="form-control text-right dr-input" oninput="autoZero(this, 'cr'); calcTotals()">
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="credit[]" class="form-control text-right cr-input" oninput="autoZero(this, 'dr'); calcTotals()">
        </td>
        <td class="text-center">
            <button type="button" class="icon-btn text-danger remove-btn" onclick="removeLine('${tr.id}')">
                <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
            </button>
        </td>
    `;
    document.getElementById('lines-container').appendChild(tr);
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
    const val = parseFloat(el.value) || 0;
    const tr = el.closest('tr');
    const otherInput = tr.querySelector(`.${otherClassPrefix}-input`);
    if (val > 0) {
        otherInput.value = '';
    }
}

function calcTotals() {
    let drTotal = 0;
    let crTotal = 0;
    document.querySelectorAll('.dr-input').forEach(el => drTotal += parseFloat(el.value) || 0);
    document.querySelectorAll('.cr-input').forEach(el => crTotal += parseFloat(el.value) || 0);
    
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

    document.querySelectorAll('.dr-input').forEach(el => drTotal += parseFloat(el.value) || 0);
    document.querySelectorAll('.cr-input').forEach(el => crTotal += parseFloat(el.value) || 0);
    document.querySelectorAll('select[name="account_id[]"]').forEach(el => {
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
    document.getElementById('lines-container').innerHTML = '';
    lineCount = 0;
    addLine();
    addLine();
}

function closeModal() {
    const modal = document.getElementById('entryModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

// Close on overlay click
document.getElementById('entryModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php require_once '../includes/footer.php'; ?>
