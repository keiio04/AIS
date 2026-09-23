<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';
require_once '../includes/transaction_poster.php';

$db = get_db();
try { $db->query("CREATE TABLE IF NOT EXISTS customers (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, code VARCHAR(20) NULL, name VARCHAR(150) NOT NULL, opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0, status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); } catch (Exception $e) {}
try { $db->query("CREATE TABLE IF NOT EXISTS suppliers (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, code VARCHAR(20) NULL, name VARCHAR(150) NOT NULL, opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0, status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE journal_entry_lines ADD COLUMN description VARCHAR(255) NULL AFTER account_id"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE journal_entry_lines ADD COLUMN vendor_name VARCHAR(100) NULL AFTER description"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE activity_logs ADD COLUMN company_id INT NULL AFTER id"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE journal_entries ADD COLUMN entity_id INT NULL AFTER type"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE journal_entries ADD COLUMN entity_type VARCHAR(20) NULL AFTER entity_id"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE customers ADD COLUMN code VARCHAR(20) NULL AFTER company_id"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE suppliers ADD COLUMN code VARCHAR(20) NULL AFTER company_id"); } catch (Exception $e) {}


$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first to view entries.</div>';
    require_once '../includes/footer.php';
    exit;
}

// Fetch all accounts for the dropdowns
$stmt = $db->prepare("SELECT id, code, name, category FROM accounts WHERE company_id = ? ORDER BY code ASC");
$stmt->bind_param('i', $company_id);
$stmt->execute();
$accountsList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch all customers for Name dropdown
$stmtCust = $db->prepare("SELECT id, name, 'customer' as type FROM customers WHERE company_id = ? ORDER BY name ASC");
$stmtCust->bind_param('i', $company_id);
$stmtCust->execute();
$customersList = $stmtCust->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch all suppliers for Name dropdown
$stmtSupp = $db->prepare("SELECT id, name, 'supplier' as type FROM suppliers WHERE company_id = ? ORDER BY name ASC");
$stmtSupp->bind_param('i', $company_id);
$stmtSupp->execute();
$suppliersList = $stmtSupp->get_result()->fetch_all(MYSQLI_ASSOC);

$entitiesList = array_merge($customersList, $suppliersList);
usort($entitiesList, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});


// Helper to find VAT Account IDs on the backend
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
$std_accounts = get_company_standard_accounts($db, $company_id);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── AUTO-POST RECEIPT (Simplified student entry) ─────────────────────
    if ($_POST['action'] === 'auto_receipt') {
        $date         = trim($_POST['date'] ?? date('Y-m-d'));
        $receipt_type = trim($_POST['receipt_type'] ?? 'collection'); // 'collection' or 'other'
        $amount       = (float)str_replace(',', '', $_POST['amount'] ?? 0);
        $description  = trim($_POST['description'] ?? '');
        $entity_id    = !empty($_POST['entity_id']) ? (int)$_POST['entity_id'] : null;
        $entity_type  = !empty($_POST['entity_type']) ? $_POST['entity_type'] : 'customer';
        $credit_account_id = !empty($_POST['credit_account_id']) ? (int)$_POST['credit_account_id'] : null;

        if ($amount <= 0) {
            $error = "Amount must be greater than zero.";
        }

        $std_accounts = get_company_standard_accounts($db, $company_id);
        $cash_acct    = $std_accounts['cash'];
        if (!$cash_acct) {
            $error = "No Cash account found for this company. Please create a Cash on Hand account first.";
        }

        if ($receipt_type === 'collection') {
            $ar_acct = $std_accounts['ar'];
            if (!$ar_acct) {
                $error = "No Accounts Receivable account found. Please ensure an Accounts Receivable account exists.";
            }
        } else {
            if (!$credit_account_id) {
                $error = "Please select a Credit / Revenue account.";
            }
        }

        if (!isset($error)) {
            $db->begin_transaction();
            try {
                $ref_no      = 'CRJ-' . str_replace('-', '', $date) . '-' . rand(1000, 9999);
                $journal_id  = 'CRJ';
                $is_taxable  = $companyIsTaxRegistered ? 1 : 0;
                $particulars = '';
                $type        = 'Operating';
                $vendor_name = null;

                if (empty($description)) {
                    if ($receipt_type === 'collection') {
                        $cust_name = '';
                        if ($entity_id) {
                            $stmtC = $db->prepare("SELECT name FROM customers WHERE id = ?");
                            $stmtC->bind_param('i', $entity_id);
                            $stmtC->execute();
                            $cust_name = $stmtC->get_result()->fetch_assoc()['name'] ?? '';
                        }
                        $description = $cust_name ? "Collection from $cust_name" : "Customer collection";
                    } else {
                        $description = "Cash receipt";
                    }
                }

                $stmt = $db->prepare("INSERT INTO journal_entries (company_id, reference_no, date, description, is_taxable, particulars, type, vendor_name, journal_id, entity_id, entity_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('isssissssis', $company_id, $ref_no, $date, $description, $is_taxable, $particulars, $type, $vendor_name, $journal_id, $entity_id, $entity_type);
                $stmt->execute();
                $entry_id = $stmt->insert_id;

                $stmtL = $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
                $zero  = 0.00;

                if ($receipt_type === 'collection') {
                    // Dr. Cash on Hand
                    $stmtL->bind_param('iidd', $entry_id, $cash_acct['id'], $amount, $zero);
                    $stmtL->execute();
                    // Cr. Accounts Receivable
                    $stmtL->bind_param('iidd', $entry_id, $ar_acct['id'], $zero, $amount);
                    $stmtL->execute();
                } else {
                    // Check if credit account is Revenue & company is tax registered
                    $is_revenue = false;
                    foreach ($accountsList as $acc) {
                        if ($acc['id'] == $credit_account_id && $acc['category'] === 'Revenue') {
                            $is_revenue = true;
                            break;
                        }
                    }

                    if ($is_revenue && $companyIsTaxRegistered) {
                        $net        = round($amount / 1.12, 2);
                        $output_vat = round($amount - $net, 2);

                        // Dr. Cash on Hand (gross)
                        $stmtL->bind_param('iidd', $entry_id, $cash_acct['id'], $amount, $zero);
                        $stmtL->execute();
                        // Cr. Revenue (net)
                        $stmtL->bind_param('iidd', $entry_id, $credit_account_id, $zero, $net);
                        $stmtL->execute();
                        // Cr. Output VAT (12%)
                        $vat_id = $std_accounts['output_vat']['id'] ?? $outputVatId;
                        if ($vat_id && $output_vat > 0) {
                            $stmtL->bind_param('iidd', $entry_id, $vat_id, $zero, $output_vat);
                            $stmtL->execute();
                        }
                    } else {
                        // Dr. Cash on Hand
                        $stmtL->bind_param('iidd', $entry_id, $cash_acct['id'], $amount, $zero);
                        $stmtL->execute();
                        // Cr. Selected Account
                        $stmtL->bind_param('iidd', $entry_id, $credit_account_id, $zero, $amount);
                        $stmtL->execute();
                    }
                }

                $user_id    = $_SESSION['user_id'];
                $log_type   = ($receipt_type === 'collection') ? 'Customer Collection' : 'Cash Receipt';
                $log_action = "Auto-posted Receipt Entry | Ref: {$ref_no} | {$log_type} | Amount: ₱" . number_format($amount, 2);
                $logStmt    = $db->prepare("INSERT INTO activity_logs (company_id, user_id, action) VALUES (?, ?, ?)");
                $logStmt->bind_param('iis', $company_id, $user_id, $log_action);
                $logStmt->execute();

                $db->commit();
                header("Location: cash_receipts_journal.php?posted=1&ref=" . urlencode($ref_no));
                exit;
            } catch (Exception $e) {
                $db->rollback();
                $error = "Failed to save entry: " . $e->getMessage();
            }
        }

    // ── EDIT ENTRY (manual multi-line edit of existing CRJ entry) ────
    } elseif ($_POST['action'] === 'edit_entry') {
        $entry_id    = (int)($_POST['entry_id'] ?? 0);
        $date        = $_POST['date'];
        $ref_no      = trim($_POST['reference_no'] ?? '');
        if (empty($ref_no)) $ref_no = 'CRJ-' . str_replace('-', '', $date) . '-' . rand(1000, 9999);
        $description = trim($_POST['description'] ?? '');
        $is_taxable  = $companyIsTaxRegistered ? 1 : 0;
        $particulars = '';
        $type        = 'Operating';
        $entity_id   = !empty($_POST['entity_id']) ? (int)$_POST['entity_id'] : null;
        $entity_type = !empty($_POST['entity_type']) ? $_POST['entity_type'] : null;
        $account_ids = $_POST['account_id'] ?? [];
        $debits      = $_POST['debit'] ?? [];
        $credits     = $_POST['credit'] ?? [];

        $check = $db->prepare("SELECT id FROM journal_entries WHERE id = ? AND company_id = ? AND deleted_at IS NULL AND journal_id = 'CRJ'");
        $check->bind_param('ii', $entry_id, $company_id);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            $error = "Entry not found.";
        }

        if (!isset($error)) {
            $db->begin_transaction();
            try {
                $stmt = $db->prepare("UPDATE journal_entries SET reference_no=?, date=?, description=?, is_taxable=?, entity_id=?, entity_type=? WHERE id=? AND company_id=?");
                $stmt->bind_param('sssiisii', $ref_no, $date, $description, $is_taxable, $entity_id, $entity_type, $entry_id, $company_id);
                $stmt->execute();

                $stmtDel = $db->prepare("DELETE FROM journal_entry_lines WHERE journal_entry_id = ?");
                $stmtDel->bind_param('i', $entry_id);
                $stmtDel->execute();

                $final_lines = [];
                for ($i = 0; $i < count($account_ids); $i++) {
                    $acc_id = (int)$account_ids[$i];
                    $dr = (float)str_replace(',', '', $debits[$i] ?: 0);
                    $cr = (float)str_replace(',', '', $credits[$i] ?: 0);
                    if ($acc_id > 0 && ($dr > 0 || $cr > 0)) {
                        $final_lines[] = ['account_id' => $acc_id, 'debit' => $dr, 'credit' => $cr];
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

                $user_id    = $_SESSION['user_id'];
                $log_action = "Edited Cash Receipts Entry #$entry_id | Ref: $ref_no | Amount: ₱" . number_format($total_debit, 2);
                $logStmt    = $db->prepare("INSERT INTO activity_logs (company_id, user_id, action) VALUES (?, ?, ?)");
                $logStmt->bind_param('iis', $company_id, $user_id, $log_action);
                $logStmt->execute();

                $db->commit();
                header("Location: cash_receipts_journal.php");
                exit;
            } catch (Exception $e) {
                $db->rollback();
                $error = "Failed to save entry: " . $e->getMessage();
            }
        }

    } elseif ($_POST['action'] === 'delete') {
        $delete_id = (int)$_POST['id'];
        $stmtDel   = $db->prepare("UPDATE journal_entries SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND company_id = ?");
        $stmtDel->bind_param('ii', $delete_id, $company_id);
        $stmtDel->execute();

        $user_id    = $_SESSION['user_id'];
        $log_action = "Moved Cash Receipts Entry #$delete_id to Trash";
        $stmtLog    = $db->prepare("INSERT INTO activity_logs (company_id, user_id, action) VALUES (?, ?, ?)");
        $stmtLog->bind_param('iis', $company_id, $user_id, $log_action);
        $stmtLog->execute();

        header("Location: cash_receipts_journal.php");
        exit;
    }
}

// ── Search (from the top search bar) ─────────────────────────────
// Matches: reference no., description, date, name (customer/vendor) + code, account title/code, and amounts.
// Several words = every word must match something in the entry.
$search = trim($_GET['search'] ?? '');
$searchSql = '';
$searchTypes = '';
$searchParams = [];
if ($search !== '') {
    foreach (array_slice(preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY), 0, 6) as $term) {
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $group = "(e.reference_no LIKE ? OR e.description LIKE ? OR e.date LIKE ? OR DATE_FORMAT(e.date, '%b %d, %Y') LIKE ? OR COALESCE(c.name, s.name) LIKE ? OR COALESCE(c.code, s.code) LIKE ?"
               . " OR EXISTS (SELECT 1 FROM journal_entry_lines sl JOIN accounts sa ON sl.account_id = sa.id WHERE sl.journal_entry_id = e.id AND (sa.name LIKE ? OR sa.code LIKE ?))";
        array_push($searchParams, $like, $like, $like, $like, $like, $like, $like, $like);
        $searchTypes .= 'ssssssss';
        $amount = str_replace([',', '₱'], '', $term);
        if (is_numeric($amount)) {
            $group .= " OR EXISTS (SELECT 1 FROM journal_entry_lines sl2 WHERE sl2.journal_entry_id = e.id AND (ROUND(sl2.debit, 2) = ROUND(?, 2) OR ROUND(sl2.credit, 2) = ROUND(?, 2)))";
            array_push($searchParams, $amount, $amount);
            $searchTypes .= 'ss';
        }
        $searchSql .= ' AND ' . $group . ')';
    }
}

// Fetch existing journal entries
$query = "
    SELECT e.*,
           COALESCE(c.name, s.name) AS entity_name,
           COALESCE(c.code, s.code) AS entity_code,
           (SELECT SUM(debit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_debit,
           (SELECT SUM(credit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_credit
    FROM journal_entries e
    LEFT JOIN customers c ON e.entity_id = c.id AND e.entity_type = 'customer'
    LEFT JOIN suppliers s ON e.entity_id = s.id AND e.entity_type = 'supplier'
    WHERE e.company_id = ? AND e.deleted_at IS NULL AND e.journal_id = 'CRJ' $searchSql
    ORDER BY e.date DESC, e.id DESC
";
$stmt = $db->prepare($query);
$stmt->bind_param('i' . $searchTypes, $company_id, ...$searchParams);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

require_once '../includes/header.php';
?>

<?php if (isset($_GET['posted'])): ?>
    <div style="background:#dcfce7; color:#15803d; padding:1rem 1.25rem; border-radius:10px; margin-bottom:1.25rem; border:1px solid #bbf7d0; display:flex; align-items:center; gap:0.75rem;">
        <i data-lucide="check-circle" style="width:20px;height:20px;color:#16a34a;flex-shrink:0;"></i>
        <div>
            <strong>Transaction Posted!</strong> Journal Entry <code><?= htmlspecialchars($_GET['ref'] ?? '') ?></code> was successfully generated and recorded in Cash Receipts.
        </div>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($search !== ''): ?>
<div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.75rem; font-size:0.875rem; color: var(--text-secondary);">
    <span>Showing <strong><?= count($transactions) ?></strong> <?= count($transactions) === 1 ? 'result' : 'results' ?> for "<strong><?= htmlspecialchars($search) ?></strong>"</span>
    <a href="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>" style="color: var(--primary-color); font-weight: 600; text-decoration: none;">Clear search</a>
</div>
<?php endif; ?>

<div class="card" style="padding: 0; overflow: hidden; border: none; box-shadow: none;">
    <div class="table-container">
        <table class="table journal-table">
            <thead>
                <tr>
                    <th style="min-width: 105px; width: 11%;" class="nowrap">Date</th>
                    <th style="min-width: 180px; width: 22%;">Account Title</th>
                    <th style="min-width: 130px; width: 14%;">Name</th>
                    <th style="min-width: 150px; width: 17%;">Description</th>
                    <th style="min-width: 130px; width: 14%;" class="nowrap">Ref No. / Code</th>
                    <th class="text-right nowrap" style="min-width: 105px; width: 11%;">Debit</th>
                    <th class="text-right nowrap" style="min-width: 105px; width: 11%;">Credit</th>
                    <th style="min-width: 44px; width: 44px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($transactions as $tx): 
                    $stmtLine = $db->prepare("SELECT l.*, a.code, a.name FROM journal_entry_lines l JOIN accounts a ON l.account_id = a.id WHERE l.journal_entry_id = ?");
                    $stmtLine->bind_param('i', $tx['id']);
                    $stmtLine->execute();
                    $lines = $stmtLine->get_result()->fetch_all(MYSQLI_ASSOC);
                    $lineCount = count($lines);
                ?>
                <?php foreach($lines as $index => $line): 
                    $isFirst = ($index === 0);
                    $isLast = ($index === $lineCount - 1);
                    $rowClasses = [];
                    if ($isFirst) $rowClasses[] = 'entry-row-first';
                    if ($isLast)  $rowClasses[] = 'entry-row-last';
                    $rowClassStr = implode(' ', $rowClasses);
                ?>
                <tr class="<?= $rowClassStr ?>">
                    <td style="white-space: nowrap;">
                        <?= $isFirst ? '<strong>' . date('M d, Y', strtotime($tx['date'])) . '</strong>' : '' ?>
                    </td>
                    <td style="padding-left: <?= $line['credit'] > 0 ? '1.75rem' : '0.75rem' ?>; font-weight: <?= $line['credit'] > 0 ? '400' : '600' ?>;">
                        <?= htmlspecialchars($line['name']) ?>
                    </td>
                    <td style="color: #475569 !important; font-size: 0.8125rem;">
                        <?php if ($isFirst && !empty($tx['entity_name'])): ?>
                            <?= htmlspecialchars($tx['entity_name']) ?>
                            <?php if (!empty($tx['entity_code'])): ?>
                                <br><span style="font-family: monospace; font-size: 0.7rem; font-weight: 600; color: #64748b;">[<?= htmlspecialchars($tx['entity_code']) ?>]</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="color: #334155 !important; font-size: 0.8125rem; white-space: normal; word-break: break-word;">
                        <?= $isFirst ? nl2br(htmlspecialchars(($tx['description'] ?? '') !== '' ? $tx['description'] : ($line['description'] ?? ''))) : nl2br(htmlspecialchars($line['description'] ?? '')) ?>
                    </td>
                    <td style="font-family: monospace; font-size: 0.8125rem; white-space: nowrap;">
                        <?php if ($isFirst): ?>
                            <span style="background: #dcfce7; color: #15803d; padding: 1px 5px; border-radius: 4px; font-size: 0.68rem; font-weight: 700; margin-bottom: 2px; display: inline-block;" title="Journal Type"><?= htmlspecialchars($tx['journal_id']) ?></span>
                            <?= $tx['reference_no'] ? ' <strong style="color: #0f172a; font-size: 0.78rem;">'.htmlspecialchars($tx['reference_no']).'</strong><br>' : '' ?>
                        <?php endif; ?>
                        <span style="color: var(--primary-color)"><?= htmlspecialchars($line['code']) ?></span>
                    </td>
                    <td class="text-right" style="white-space: nowrap; font-variant-numeric: tabular-nums;"><?= $line['debit'] > 0 ? '₱'.number_format($line['debit'], 2) : '' ?></td>
                    <td class="text-right" style="white-space: nowrap; font-variant-numeric: tabular-nums;"><?= $line['credit'] > 0 ? '₱'.number_format($line['credit'], 2) : '' ?></td>
                    <td class="text-center" style="vertical-align: top;">
                        <?php if ($isFirst): ?>
                        <div class="flex gap-1" style="justify-content: center; padding-top: 2px;">
                            <button type="button" style="background: none; border: none; cursor: pointer; color: var(--primary-color);" title="Edit Entry" onclick='openEditModal(<?= json_encode([
                                "id" => $tx['id'],
                                "date" => $tx['date'],
                                "reference_no" => $tx['reference_no'],
                                "description" => $tx['description'],
                                "entity_id" => $tx['entity_id'],
                                "entity_type" => $tx['entity_type'],
                                "entity_name" => $tx['entity_name'],
                                "lines" => array_map(function($l) {
                                    return [
                                        "account_id" => $l['account_id'],
                                        "debit" => $l['debit'],
                                        "credit" => $l['credit'],
                                    ];
                                }, $lines)
                            ]) ?>)'>
                                <i data-lucide="edit-2" style="width:14px;height:14px;"></i>
                            </button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Move this entry to Trash Bin?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $tx['id'] ?>">
                                <button type="submit" style="background: none; border: none; cursor: pointer; color: #ef4444;" title="Move to Trash">
                                    <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if(count($transactions) === 0): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted" style="padding: 2rem;"><?= $search !== '' ? 'No entries match your search.' : 'No journal entries found.' ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: dual-mode (simplified auto_receipt for new entries, manual edit for existing) -->
<div id="entryModal" class="modal-overlay hidden">
    <div class="modal" id="entryModalInner" style="width: 740px; max-width: 96vw;">
        <div class="modal-header">
            <div>
                <h2 id="modalTitle">New Cash Receipt Entry</h2>
                <p id="modalSubtitle" style="font-size:0.78rem; color:var(--text-muted); margin:2px 0 0; line-height:1.4;">System automatically generates the balanced accounting entry and posts to Cash Receipts Journal.</p>
            </div>
            <button class="icon-btn" onclick="closeModal()"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
        </div>
        <div class="modal-body">

            <!-- ===== AUTO FORM (new entries) ===== -->
            <div id="crj-auto-section">
                <form id="auto-entry-form" method="POST">
                    <input type="hidden" name="action" value="auto_receipt">

                    <!-- Receipt Type toggle -->
                    <div style="margin-bottom:1.25rem;">
                        <label class="form-label" style="margin-bottom:0.5rem;">Receipt Type</label>
                        <div style="display:flex; gap:0.5rem;">
                            <button type="button" id="typeCollectionBtn" class="btn btn-primary" onclick="setReceiptType('collection')" style="flex:1; font-size:0.875rem;">
                                <i data-lucide="user-check" style="width:14px;height:14px;"></i>&nbsp; Customer Collection (from A/R)
                            </button>
                            <button type="button" id="typeOtherBtn" class="btn btn-secondary" onclick="setReceiptType('other')" style="flex:1; font-size:0.875rem;">
                                <i data-lucide="banknote" style="width:14px;height:14px;"></i>&nbsp; Direct Cash / Other Income
                            </button>
                        </div>
                        <input type="hidden" name="receipt_type" id="receiptTypeInput" value="collection">
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
                        <div class="form-group">
                            <label class="form-label">Date</label>
                            <input type="date" name="date" id="autoEntryDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group" style="position:relative;">
                            <label class="form-label">Customer <span id="autoEntityRequired" style="color:#ef4444;">*</span></label>
                            <input type="text" id="autoEntitySearch" class="form-control" placeholder="Search customer..." autocomplete="off"
                                   oninput="onAutoEntitySearch()" onfocus="onAutoEntitySearch()"
                                   onkeydown="onAutoEntityKeydown(event)" onblur="onAutoEntityBlur()">
                            <input type="hidden" name="entity_id" id="autoEntityId" value="">
                            <input type="hidden" name="entity_type" id="autoEntityType" value="customer">
                            <div id="auto-entity-dd" style="display:none;position:absolute;top:calc(100% + 2px);left:0;right:0;z-index:9999;background:var(--bg-primary,#fff);border:1px solid var(--border-color);border-radius:6px;max-height:180px;overflow-y:auto;box-shadow:0 4px 14px rgba(0,0,0,.14);"></div>
                        </div>
                    </div>

                    <!-- Credit Account group: shown when 'other' is selected -->
                    <div id="creditAccountGroup" style="display:none; margin-bottom:1rem;" class="form-group">
                        <label class="form-label">Credit / Revenue Account</label>
                        <select name="credit_account_id" id="creditAccountId" class="form-control" onchange="computePreview()">
                            <option value="">-- Select Account to Credit --</option>
                            <?php foreach ($accountsList as $acc): if ($acc['category'] === 'Assets') continue; ?>
                            <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['code'] . ' - ' . $acc['name'] . ' (' . $acc['category'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="form-label">Amount Received (&#8369;)</label>
                        <input type="number" name="amount" id="autoAmount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required oninput="computePreview()">
                    </div>

                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="autoDescription" class="form-control" rows="2" style="resize:vertical;" placeholder="Optional memo/note..."></textarea>
                    </div>

                    <!-- Auto-Generated Entry Preview -->
                    <div style="border:1px solid var(--border-color);border-radius:10px;padding:1rem;background:var(--bg-secondary);">
                        <div style="font-size:0.71rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#0284c7;margin-bottom:0.7rem;display:flex;align-items:center;gap:0.4rem;">
                            <i data-lucide="zap" style="width:13px;height:13px;"></i>
                            Auto-Generated Journal Entry &mdash; Posts to Cash Receipts Journal (CRJ)
                        </div>
                        <table style="width:100%;font-size:0.875rem;border-collapse:collapse;">
                            <thead><tr style="border-bottom:1px solid var(--border-color);">
                                <th style="text-align:left;padding:0.3rem 0.5rem;font-size:0.72rem;font-weight:700;color:var(--text-muted);">Account</th>
                                <th style="text-align:right;padding:0.3rem 0.5rem;font-size:0.72rem;font-weight:700;color:var(--text-muted);min-width:110px;">Debit</th>
                                <th style="text-align:right;padding:0.3rem 0.5rem;font-size:0.72rem;font-weight:700;color:var(--text-muted);min-width:110px;">Credit</th>
                            </tr></thead>
                            <tbody id="prev-tbody">
                                <tr>
                                    <td style="padding:0.4rem 0.5rem;font-weight:500;" id="prev-dr-name">Cash on Hand</td>
                                    <td style="text-align:right;padding:0.4rem 0.5rem;font-weight:700;color:#22c55e;font-variant-numeric:tabular-nums;" id="prev-dr-amt">&#8369;0.00</td>
                                    <td style="text-align:right;padding:0.4rem 0.5rem;color:var(--text-muted);">&#8212;</td>
                                </tr>
                                <tr>
                                    <td style="padding:0.4rem 0.5rem;padding-left:2rem;color:var(--text-secondary);" id="prev-cr-name">Accounts Receivable</td>
                                    <td style="text-align:right;padding:0.4rem 0.5rem;color:var(--text-muted);">&#8212;</td>
                                    <td style="text-align:right;padding:0.4rem 0.5rem;font-weight:600;color:#60a5fa;font-variant-numeric:tabular-nums;" id="prev-cr-amt">&#8369;0.00</td>
                                </tr>
                                <tr id="prev-vat-row" style="display:none;">
                                    <td style="padding:0.4rem 0.5rem;padding-left:2rem;color:var(--text-secondary);">Output VAT (12%)</td>
                                    <td style="text-align:right;padding:0.4rem 0.5rem;color:var(--text-muted);">&#8212;</td>
                                    <td style="text-align:right;padding:0.4rem 0.5rem;font-weight:600;color:#60a5fa;font-variant-numeric:tabular-nums;" id="prev-vat-amt">&#8369;0.00</td>
                                </tr>
                            </tbody>
                            <tfoot><tr style="border-top:2px solid var(--border-color);">
                                <td style="padding:0.4rem 0.5rem;font-weight:700;font-size:0.8rem;">Total</td>
                                <td style="text-align:right;padding:0.4rem 0.5rem;font-weight:700;" id="prev-total-dr">&#8369;0.00</td>
                                <td style="text-align:right;padding:0.4rem 0.5rem;font-weight:700;" id="prev-total-cr">&#8369;0.00</td>
                            </tr></tfoot>
                        </table>
                        <div id="prev-vat-info" style="display:none;margin-top:0.55rem;font-size:0.8rem;color:var(--text-muted);padding:0.4rem 0.5rem;background:rgba(59,130,246,0.08);border-radius:6px;border:1px solid rgba(59,130,246,0.2);">
                            Net Revenue &#8369;<span id="prev-vat-base">0.00</span> + Output VAT 12% &#8369;<span id="prev-vat-computed">0.00</span> = Total Cash &#8369;<span id="prev-vat-full">0.00</span>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ===== MANUAL / EDIT FORM (editing existing entries) ===== -->
            <div id="crj-edit-section" style="display:none;">
                <form id="entry-form" method="POST">
                    <input type="hidden" name="action" id="formAction" value="edit_entry">
                    <input type="hidden" name="entry_id" id="entryId" value="">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                        <div class="form-group">
                            <label class="form-label">Date</label>
                            <input type="date" name="date" id="entryDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <input type="hidden" name="reference_no" id="entryRefNo" value="">
                        <div class="form-group" style="position:relative;">
                            <label class="form-label">Name <span style="font-weight:400;color:var(--text-muted);font-size:0.78rem;">(Customer / Supplier)</span></label>
                            <input type="text" id="entitySearchInput" class="form-control" placeholder="Search customer or vendor..." autocomplete="off"
                                   oninput="onEntitySearchInput()" onfocus="onEntitySearchInput()"
                                   onkeydown="onEntitySearchKeydown(event)" onblur="onEntitySearchBlur()">
                            <input type="hidden" name="entity_id" id="entityIdInput" value="">
                            <input type="hidden" name="entity_type" id="entityTypeInput" value="">
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:1.5rem;">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="entryDescription" class="form-control" rows="2" style="resize:vertical;"></textarea>
                    </div>
                    <div class="card" style="margin-bottom:1.5rem;background-color:var(--bg-secondary);padding:1rem;border:none;box-shadow:none;">
                        <table class="table" style="margin:0;">
                            <thead><tr>
                                <th style="width:55%;">Account</th>
                                <th style="width:20%;" class="text-right">Debit</th>
                                <th style="width:20%;" class="text-right">Credit</th>
                                <th style="width:5%;"></th>
                            </tr></thead>
                            <tbody id="lines-container"></tbody>
                            <tfoot><tr>
                                <td><button type="button" class="btn btn-secondary" style="padding:0.25rem 0.5rem;font-size:0.8rem;" onclick="addLine()"><i data-lucide="plus" style="width:14px;height:14px;"></i> Add Line</button></td>
                                <td class="text-right" style="font-weight:600;" id="total-dr">&#8369;0.00</td>
                                <td class="text-right" style="font-weight:600;" id="total-cr">&#8369;0.00</td>
                                <td></td>
                            </tr></tfoot>
                        </table>
                    </div>
                    <div id="balance-warning" style="color:var(--danger-color);font-size:0.875rem;margin-bottom:1rem;text-align:right;display:none;">
                        Debits and Credits must balance. Difference: &#8369;<span id="diff-amount">0.00</span>
                    </div>
                </form>
            </div>

        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="auto-entry-form" id="auto-save-btn" class="btn btn-primary">
                <i data-lucide="send" style="width:14px;height:14px;"></i>&nbsp; Post to Cash Receipts
            </button>
            <button type="submit" form="entry-form" id="save-btn" class="btn btn-primary" style="display:none;" disabled>Save Entry</button>
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

<script>
const accounts = <?= json_encode($accountsList) ?>;
const stdAccounts = <?= json_encode($std_accounts) ?>;
let entitiesList = <?= json_encode($entitiesList ?? []) ?>;
const customersList = <?= json_encode($customersList ?? []) ?>;
const suppliersList = <?= json_encode($suppliersList ?? []) ?>;


const globalInputVatId = <?= $inputVatId ?: 'null' ?>;
const globalOutputVatId = <?= $outputVatId ?: 'null' ?>;
/* ===== LIVE VAT PREVIEW ===== */
const companyIsTaxRegistered = <?= $companyIsTaxRegistered ? 'true' : 'false' ?>;
const vatMode = 'output';   // 'output' = VAT on Revenue credits | 'input' = VAT on Expense/Asset debits
const VAT_RATE = 0.12;

function vatPeso(n) {
    return '\u20b1' + (n || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function isVatAccount(id) {
    return (globalInputVatId !== null && String(id) === String(globalInputVatId))
        || (globalOutputVatId !== null && String(id) === String(globalOutputVatId));
}

// Mirrors the backend VAT rules exactly so what you see here is what gets saved.
function computeVatPreview() {
    const out = { applies: false, gross: 0, base: 0, vat: 0 };

    if (!companyIsTaxRegistered) return out;

    const vatAccId = (vatMode === 'output') ? globalOutputVatId : globalInputVatId;
    if (!vatAccId) return out;

    // Backend only auto-adds VAT on NEW entries; on edit the lines already contain it.
    const actionEl = document.getElementById('formAction');
    if (actionEl && actionEl.value !== 'add_entry') return out;

    let hasUserVat = false;
    document.querySelectorAll('#lines-container tr').forEach(tr => {
        const idEl = tr.querySelector('.account-id-input');
        if (idEl && idEl.value && isVatAccount(idEl.value)) {
            hasUserVat = true;
        }
    });
    if (hasUserVat) return out;

    document.querySelectorAll('#lines-container tr').forEach(tr => {
        const idEl = tr.querySelector('.account-id-input');
        if (!idEl || !idEl.value) return;
        if (isVatAccount(idEl.value)) return;

        const acc = accounts.find(a => String(a.id) === String(idEl.value));
        if (!acc) return;

        const cr = parseNumber(tr.querySelector('.cr-input').value);

        if (acc.category === 'Revenue' && cr > 0) {
            out.gross += cr;
        }
    });

    if (out.gross > 0) {
        out.base = Math.round((out.gross / 1.12) * 100) / 100;
        out.vat = Math.round((out.gross - out.base) * 100) / 100;
        out.applies = out.vat > 0;
    }
    return out;
}

function renderVatPreview() {
    const box = document.getElementById('vat-preview');
    if (!box) return;

    const r = computeVatPreview();
    if (!r.applies) { box.style.display = 'none'; return; }

    const vatAccId = (vatMode === 'output') ? globalOutputVatId : globalInputVatId;
    const vatAcc = accounts.find(a => String(a.id) === String(vatAccId));

    document.getElementById('vat-base').innerText = vatPeso(r.base);
    document.getElementById('vat-amount').innerText = vatPeso(r.vat);
    document.getElementById('vat-account-name').innerText = vatAcc ? (vatAcc.code + ' - ' + vatAcc.name) : '';
    document.getElementById('vat-grand-total').innerText = vatPeso(r.gross);

    box.style.display = 'block';
}
/* ===== END LIVE VAT PREVIEW ===== */



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



// ── Smart Account Search (combobox) ─────────────────────────────────
let activeAccountRow = null;
let accountDropdownEl = null;

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

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
        if (e.key === 'ArrowDown' || e.key === 'Enter') onAccountSearchInput(inputEl);
        return;
    }
    const matches = listEl._matches || [];
    let idx = listEl._highlightIndex ?? -1;
    if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min(idx + 1, matches.length - 1); updateAccountHighlight(idx); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(idx - 1, 0); updateAccountHighlight(idx); }
    else if (e.key === 'Enter') {
        e.preventDefault();
        if (idx >= 0 && matches[idx]) {
            const acc = matches[idx];
            inputEl.value = `${acc.code} - ${acc.name}`;
            tr.querySelector('.account-id-input').value = acc.id;
            listEl.style.display = 'none';
            checkValidity();
            const nextInput = tr.querySelector('.dr-input');
            if (nextInput) nextInput.focus();
        }
    } else if (e.key === 'Escape') { listEl.style.display = 'none'; }
}

function onAccountSearchBlur(inputEl) {
    setTimeout(() => {
        const tr = inputEl.closest('tr');
        const listEl = getAccountDropdownEl();
        listEl.style.display = 'none';
        if (!tr.querySelector('.account-id-input').value) inputEl.value = '';
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

    document.getElementById('total-dr').innerText = '\u20b1' + drTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('total-cr').innerText = '\u20b1' + crTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});

    renderVatPreview();

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



function checkValidity() {
    renderVatPreview();
    let drTotal = 0;
    let crTotal = 0;
    let allAccountsSelected = true;

    document.querySelectorAll('.dr-input').forEach(el => drTotal += parseFloat(el.value) || 0);
    document.querySelectorAll('.cr-input').forEach(el => crTotal += parseFloat(el.value) || 0);
    document.querySelectorAll('.account-id-input').forEach(el => {
        if (!el.value) allAccountsSelected = false;
    });

    const isBalanced = drTotal > 0 && Math.abs(drTotal - crTotal) < 0.01;
    const saveBtn = document.getElementById('save-btn');
    
    if (isBalanced && allAccountsSelected) {
        saveBtn.disabled = false;
    } else {
        saveBtn.disabled = true;
    }
}

// ── Entity (Customer/Vendor) search at header level ─────────────────
let entityDropdownEl = null;
let _entityMatches = [];
let _entityHighlight = -1;

function getEntityDropdownEl() {
    if (!entityDropdownEl) {
        entityDropdownEl = document.createElement('div');
        entityDropdownEl.style.cssText = 'display:none;position:fixed;z-index:9998;background:#fff;border:1px solid var(--border-color);border-radius:6px;max-height:220px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.12);';
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
    const addBtns = `<div style="padding:0.4rem 0.75rem; display:flex; gap:0.4rem; border-top:1px solid var(--border-color); margin-top:2px;">
        <button type="button" onmousedown="openQuickAdd('customer')" style="flex:1; padding:0.35rem; font-size:0.8rem; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; border-radius:5px; cursor:pointer;">+ Add Customer</button>
        <button type="button" onmousedown="openQuickAdd('supplier')" style="flex:1; padding:0.35rem; font-size:0.8rem; background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; border-radius:5px; cursor:pointer;">+ Add Vendor</button>
    </div>`;
    if (matches.length === 0) {
        el.innerHTML = `<div style="padding:0.6rem 0.75rem; color:var(--text-muted); font-size:0.85rem;">No records found</div>` + addBtns;
    } else {
        el.innerHTML = matches.map((e, idx) => `
            <div class="entity-opt" data-idx="${idx}"
                 style="padding:0.5rem 0.75rem; cursor:pointer; font-size:0.85rem; background:${idx === highlightIndex ? 'var(--bg-secondary)' : '#fff'};display:flex;align-items:center;gap:0.5rem;"
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
        if (!document.getElementById('entityIdInput').value) {
            document.getElementById('entitySearchInput').value = '';
            document.getElementById('entityTypeInput').value = '';
        }
    }, 180);
}

let _quickAddType = null;
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
            document.getElementById('entityTypeInput').value = _quickAddType === 'customer' ? 'customer' : 'supplier';
            closeQuickAdd();
        } else {
            document.getElementById('quickAddError').innerText = data.error || 'Failed to add.';
            document.getElementById('quickAddError').style.display = 'block';
        }
    } catch (e) {
        document.getElementById('quickAddError').innerText = 'Network error.';
        document.getElementById('quickAddError').style.display = 'block';
    }
    btn.disabled = false; btn.innerText = 'Save';
}

// ── Auto Receipt Mode ─────────────────────────────────────────────
let autoReceiptType = 'collection'; // 'collection' or 'other'

function setReceiptType(type) {
    autoReceiptType = type;
    document.getElementById('receiptTypeInput').value = type;
    const collBtn = document.getElementById('typeCollectionBtn');
    const otherBtn = document.getElementById('typeOtherBtn');
    const creditGroup = document.getElementById('creditAccountGroup');
    const entityReq = document.getElementById('autoEntityRequired');

    if (type === 'collection') {
        collBtn.className = 'btn btn-primary';
        otherBtn.className = 'btn btn-secondary';
        creditGroup.style.display = 'none';
        document.getElementById('creditAccountId').removeAttribute('required');
        entityReq.style.display = 'inline';
    } else {
        collBtn.className = 'btn btn-secondary';
        otherBtn.className = 'btn btn-primary';
        creditGroup.style.display = 'block';
        document.getElementById('creditAccountId').setAttribute('required', 'true');
        entityReq.style.display = 'none';
    }
    computePreview();
}

function computePreview() {
    const amt = parseFloat(document.getElementById('autoAmount').value) || 0;
    const prevTbody = document.getElementById('prev-tbody');
    const vatInfo = document.getElementById('prev-vat-info');
    const cashName = (stdAccounts.cash ? (stdAccounts.cash.code + ' - ' + stdAccounts.cash.name) : 'Cash on Hand');
    const arName = (stdAccounts.ar ? (stdAccounts.ar.code + ' - ' + stdAccounts.ar.name) : 'Accounts Receivable');

    if (autoReceiptType === 'collection') {
        vatInfo.style.display = 'none';
        prevTbody.innerHTML = `
            <tr>
                <td style="padding:0.4rem 0.5rem;font-weight:500;">${escapeHtml(cashName)}</td>
                <td style="text-align:right;padding:0.4rem 0.5rem;font-weight:700;color:#22c55e;font-variant-numeric:tabular-nums;">${vatPeso(amt)}</td>
                <td style="text-align:right;padding:0.4rem 0.5rem;color:var(--text-muted);">&#8212;</td>
            </tr>
            <tr>
                <td style="padding:0.4rem 0.5rem;padding-left:2rem;color:var(--text-secondary);">${escapeHtml(arName)}</td>
                <td style="text-align:right;padding:0.4rem 0.5rem;color:var(--text-muted);">&#8212;</td>
                <td style="text-align:right;padding:0.4rem 0.5rem;font-weight:600;color:#60a5fa;font-variant-numeric:tabular-nums;">${vatPeso(amt)}</td>
            </tr>
        `;
        document.getElementById('prev-total-dr').innerText = vatPeso(amt);
        document.getElementById('prev-total-cr').innerText = vatPeso(amt);
    } else {
        const creditAccId = document.getElementById('creditAccountId').value;
        const creditAcc = accounts.find(a => String(a.id) === String(creditAccId));
        const crName = creditAcc ? (creditAcc.code + ' - ' + creditAcc.name) : 'Credit / Income Account';
        const isRevenue = creditAcc && creditAcc.category === 'Revenue';

        if (isRevenue && companyIsTaxRegistered && amt > 0) {
            const net = Math.round((amt / 1.12) * 100) / 100;
            const vat = Math.round((amt - net) * 100) / 100;
            const vatName = (stdAccounts.output_vat ? (stdAccounts.output_vat.code + ' - ' + stdAccounts.output_vat.name) : 'Output VAT (12%)');

            prevTbody.innerHTML = `
                <tr>
                    <td style="padding:0.4rem 0.5rem;font-weight:500;">${escapeHtml(cashName)}</td>
                    <td style="text-align:right;padding:0.4rem 0.5rem;font-weight:700;color:#22c55e;font-variant-numeric:tabular-nums;">${vatPeso(amt)}</td>
                    <td style="text-align:right;padding:0.4rem 0.5rem;color:var(--text-muted);">&#8212;</td>
                </tr>
                <tr>
                    <td style="padding:0.4rem 0.5rem;padding-left:2rem;color:var(--text-secondary);">${escapeHtml(crName)}</td>
                    <td style="text-align:right;padding:0.4rem 0.5rem;color:var(--text-muted);">&#8212;</td>
                    <td style="text-align:right;padding:0.4rem 0.5rem;font-weight:600;color:#60a5fa;font-variant-numeric:tabular-nums;">${vatPeso(net)}</td>
                </tr>
                <tr>
                    <td style="padding:0.4rem 0.5rem;padding-left:2rem;color:var(--text-secondary);">${escapeHtml(vatName)}</td>
                    <td style="text-align:right;padding:0.4rem 0.5rem;color:var(--text-muted);">&#8212;</td>
                    <td style="text-align:right;padding:0.4rem 0.5rem;font-weight:600;color:#60a5fa;font-variant-numeric:tabular-nums;">${vatPeso(vat)}</td>
                </tr>
            `;
            document.getElementById('prev-total-dr').innerText = vatPeso(amt);
            document.getElementById('prev-total-cr').innerText = vatPeso(amt);

            document.getElementById('prev-vat-base').innerText = net.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
            document.getElementById('prev-vat-computed').innerText = vat.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
            document.getElementById('prev-vat-full').innerText = amt.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
            vatInfo.style.display = 'block';
        } else {
            vatInfo.style.display = 'none';
            prevTbody.innerHTML = `
                <tr>
                    <td style="padding:0.4rem 0.5rem;font-weight:500;">${escapeHtml(cashName)}</td>
                    <td style="text-align:right;padding:0.4rem 0.5rem;font-weight:700;color:#22c55e;font-variant-numeric:tabular-nums;">${vatPeso(amt)}</td>
                    <td style="text-align:right;padding:0.4rem 0.5rem;color:var(--text-muted);">&#8212;</td>
                </tr>
                <tr>
                    <td style="padding:0.4rem 0.5rem;padding-left:2rem;color:var(--text-secondary);">${escapeHtml(crName)}</td>
                    <td style="text-align:right;padding:0.4rem 0.5rem;color:var(--text-muted);">&#8212;</td>
                    <td style="text-align:right;padding:0.4rem 0.5rem;font-weight:600;color:#60a5fa;font-variant-numeric:tabular-nums;">${vatPeso(amt)}</td>
                </tr>
            `;
            document.getElementById('prev-total-dr').innerText = vatPeso(amt);
            document.getElementById('prev-total-cr').innerText = vatPeso(amt);
        }
    }
}

// Auto Customer Search
let _autoMatches = [];
let _autoHighlight = -1;

function onAutoEntitySearch() {
    const q = (document.getElementById('autoEntitySearch').value || '').trim().toLowerCase();
    const dd = document.getElementById('auto-entity-dd');
    const matches = q ? customersList.filter(c => c.name.toLowerCase().includes(q)) : customersList.slice(0, 30);
    _autoMatches = matches;
    _autoHighlight = matches.length ? 0 : -1;

    let html = '';
    if (matches.length === 0) {
        html = `<div style="padding:0.5rem 0.75rem;color:var(--text-muted);font-size:0.82rem;">No customers found</div>`;
    } else {
        html = matches.map((c, i) => `
            <div class="auto-cust-opt" data-idx="${i}" style="padding:0.45rem 0.75rem;cursor:pointer;font-size:0.83rem;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:0.4rem;${i===0?'background:var(--bg-secondary);':''}"
                 onmousedown="selectAutoEntity(${i})"
                 onmouseover="this.style.background='var(--bg-secondary)'"
                 onmouseout="if(${i}!==_autoHighlight)this.style.background=''">
                <span style="font-size:0.68rem;padding:1px 5px;border-radius:10px;font-weight:700;background:#dbeafe;color:#1d4ed8;">C</span>
                ${escapeHtml(c.name)}
            </div>
        `).join('');
    }
    html += `<div style="padding:0.4rem 0.75rem;background:var(--bg-secondary);border-top:1px solid var(--border-color);">
        <button type="button" onmousedown="openQuickAdd('customer')" style="width:100%;padding:0.3rem 0.5rem;font-size:0.78rem;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;border-radius:4px;cursor:pointer;font-weight:600;">+ Quick Add Customer</button>
    </div>`;
    dd.innerHTML = html;
    dd.style.display = 'block';
}

function selectAutoEntity(idx) {
    const c = _autoMatches[idx];
    if (!c) return;
    document.getElementById('autoEntitySearch').value = c.name;
    document.getElementById('autoEntityId').value = c.id;
    document.getElementById('autoEntityType').value = 'customer';
    document.getElementById('auto-entity-dd').style.display = 'none';
}

function onAutoEntityKeydown(e) {
    const dd = document.getElementById('auto-entity-dd');
    if (dd.style.display === 'none') {
        if (e.key === 'ArrowDown') onAutoEntitySearch();
        return;
    }
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        _autoHighlight = Math.min(_autoHighlight + 1, _autoMatches.length - 1);
        updateAutoHighlight();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        _autoHighlight = Math.max(_autoHighlight - 1, 0);
        updateAutoHighlight();
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (_autoHighlight >= 0) selectAutoEntity(_autoHighlight);
    } else if (e.key === 'Escape') {
        dd.style.display = 'none';
    }
}

function updateAutoHighlight() {
    document.querySelectorAll('.auto-cust-opt').forEach((el, i) => {
        el.style.background = (i === _autoHighlight) ? 'var(--bg-secondary)' : '';
    });
}

function onAutoEntityBlur() {
    setTimeout(() => {
        const dd = document.getElementById('auto-entity-dd');
        if (dd) dd.style.display = 'none';
        if (!document.getElementById('autoEntityId').value) {
            document.getElementById('autoEntitySearch').value = '';
        }
    }, 180);
}

function openModal() {
    const modal = document.getElementById('entryModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    document.getElementById('modalTitle').innerText = 'New Cash Receipt Entry';
    document.getElementById('modalSubtitle').style.display = 'block';
    document.getElementById('crj-auto-section').style.display = 'block';
    document.getElementById('crj-edit-section').style.display = 'none';
    document.getElementById('auto-save-btn').style.display = 'inline-flex';
    document.getElementById('save-btn').style.display = 'none';
    document.getElementById('auto-entry-form').reset();
    document.getElementById('autoEntryDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('autoEntityId').value = '';
    document.getElementById('autoEntitySearch').value = '';
    setReceiptType('collection');
    computePreview();
}

function openEditModal(tx) {
    const modal = document.getElementById('entryModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    document.getElementById('modalTitle').innerText = 'Edit Cash Receipt Entry #' + tx.id;
    document.getElementById('modalSubtitle').style.display = 'none';
    document.getElementById('crj-auto-section').style.display = 'none';
    document.getElementById('crj-edit-section').style.display = 'block';
    document.getElementById('auto-save-btn').style.display = 'none';
    document.getElementById('save-btn').style.display = 'inline-flex';
    document.getElementById('formAction').value = 'edit_entry';
    document.getElementById('entryId').value = tx.id;
    document.getElementById('entryDate').value = tx.date;
    document.getElementById('entryRefNo').value = tx.reference_no;
    document.getElementById('entryDescription').value = tx.description || '';
    // Pre-fill entity
    document.getElementById('entitySearchInput').value = tx.entity_name || '';
    document.getElementById('entityIdInput').value = tx.entity_id || '';
    document.getElementById('entityTypeInput').value = tx.entity_type || '';
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

// Close on overlay click
document.getElementById('entryModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// ── Journal Type Detection & Toast ─────────────────────────────────
function _isCash(acc)    { return acc.category === 'Assets' && /cash|bank/i.test(acc.name); }

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

    // No Cash/Bank — check if it belongs elsewhere
    if (!hasCash) {
        if (hasRev && hasRec)
            return { journal: 'Sales', url: 'sales_journal.php', reason: 'It looks like you\'re recording a credit sale, which belongs in', hint: 'If this is a purchase, please check your Debit/Credit entries.' };
        if (hasExp && hasPay)
            return { journal: 'Purchases', url: 'purchases_journal.php', reason: 'It looks like you\'re recording a credit purchase, which belongs in', hint: 'If this is a sale, please check your Debit/Credit entries.' };
        return { journal: 'Cash Receipts', url: null, reason: 'A Cash or Bank account is required for cash receipts.' };
    }
    return null;
}

function _showJournalToast(mismatch) {
    const old = document.getElementById('jt-toast'); if (old) old.remove();
    const t = document.createElement('div');
    t.id = 'jt-toast';
    t.style.cssText = 'position:fixed;top:1.5rem;right:1.5rem;z-index:999999;background:#1e293b;color:#f1f5f9;padding:1rem 1.25rem;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.45);display:flex;flex-direction:column;gap:.5rem;max-width:400px;border-left:4px solid #ef4444;font-family:inherit;font-size:.875rem;';
    const redirectBtn = mismatch.url ? `<a href=\"${mismatch.url}\" style=\"background:transparent;color:#94a3b8;border:1px solid #475569;padding:.35rem .9rem;border-radius:6px;font-size:.78rem;font-weight:600;text-decoration:none;white-space:nowrap;transition:all 0.2s;\" onmouseover=\"this.style.color='#f8fafc';this.style.borderColor='#94a3b8'\" onmouseout=\"this.style.color='#94a3b8';this.style.borderColor='#475569'\">Move to ${mismatch.journal}</a>` : '';
    const bodyText = mismatch.url ? `${mismatch.reason} <strong style=\"color:#93c5fd;\">${mismatch.journal}</strong>.` + (mismatch.hint ? ` <span style=\"color:#94a3b8;display:block;margin-top:4px;\">${mismatch.hint}</span>` : '') : mismatch.reason;
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

document.getElementById('entry-form').addEventListener('submit', function(e) {
    document.querySelectorAll('.dr-input, .cr-input').forEach(el => {
        el.value = el.value.replace(/,/g, '');
    });
    const mismatch = _detectMismatch(_getLines());
    if (mismatch) { e.preventDefault(); _showJournalToast(mismatch); }
});
</script>

<script>window.AUTOSAVE_KEY = 'autosave_cash_receipts';</script>
<script src="<?= BASE_URL ?>includes/autosave.js"></script>

<?php require_once '../includes/footer.php'; ?>