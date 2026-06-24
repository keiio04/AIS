<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/header.php';

$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    echo '<div class="alert alert-warning" style="margin: 2rem;">Please <a href="'.BASE_URL.'pages/company_setup.php">select or create a company</a> first.</div>';
    require_once '../includes/footer.php';
    exit;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'restore') {
        $restore_id = (int)$_POST['id'];
        $stmt = $db->prepare("UPDATE journal_entries SET deleted_at = NULL WHERE id = ? AND company_id = ?");
        $stmt->bind_param('ii', $restore_id, $company_id);
        $stmt->execute();
        $user_id = $_SESSION['user_id'];
        $log = "Restored Journal Entry #$restore_id from Trash";
        $sl = $db->prepare("INSERT INTO activity_logs (company_id, user_id, action) VALUES (?, ?, ?)");
        $sl->bind_param('iis', $company_id, $user_id, $log);
        $sl->execute();
        header("Location: trash_bin.php");
        exit;

    } elseif ($_POST['action'] === 'delete_permanent') {
        $del_id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM journal_entries WHERE id = ? AND company_id = ? AND deleted_at IS NOT NULL");
        $stmt->bind_param('ii', $del_id, $company_id);
        $stmt->execute();
        $user_id = $_SESSION['user_id'];
        $log = "Permanently Deleted Journal Entry #$del_id";
        $sl = $db->prepare("INSERT INTO activity_logs (company_id, user_id, action) VALUES (?, ?, ?)");
        $sl->bind_param('iis', $company_id, $user_id, $log);
        $sl->execute();
        header("Location: trash_bin.php");
        exit;

    } elseif ($_POST['action'] === 'empty_trash') {
        $stmt = $db->prepare("DELETE FROM journal_entries WHERE company_id = ? AND deleted_at IS NOT NULL");
        $stmt->bind_param('i', $company_id);
        $stmt->execute();
        $user_id = $_SESSION['user_id'];
        $log = "Emptied Trash Bin";
        $sl = $db->prepare("INSERT INTO activity_logs (company_id, user_id, action) VALUES (?, ?, ?)");
        $sl->bind_param('iis', $company_id, $user_id, $log);
        $sl->execute();
        header("Location: trash_bin.php");
        exit;
    }
}

// Fetch deleted journal entries
$query = "
    SELECT e.*,
           (SELECT SUM(debit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_debit,
           (SELECT SUM(credit) FROM journal_entry_lines WHERE journal_entry_id = e.id) as total_credit
    FROM journal_entries e
    WHERE e.company_id = ? AND e.deleted_at IS NOT NULL
    ORDER BY e.deleted_at DESC
";
$stmt = $db->prepare($query);
$stmt->bind_param('i', $company_id);
$stmt->execute();
$trashed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title" style="color: #ef4444;">
            <i data-lucide="trash-2" style="width:22px;height:22px; display:inline; vertical-align:middle; margin-right: 6px;"></i>
            Trash Bin
        </h1>
        <p class="page-subtitle"><?= count($trashed) ?> deleted entries. Restore or permanently delete them.</p>
    </div>
    <div class="flex gap-2">
        <a href="journal_entries.php" class="btn btn-secondary">
            <i data-lucide="arrow-left" style="width:15px;height:15px;"></i> Back to Journal
        </a>
        <?php if (count($trashed) > 0): ?>
        <form method="POST" onsubmit="return confirm('Empty all trash? This cannot be undone!');">
            <input type="hidden" name="action" value="empty_trash">
            <button type="submit" class="btn" style="background: #ef4444; color: #fff;">
                <i data-lucide="trash" style="width:15px;height:15px;"></i> Empty Trash
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if (count($trashed) === 0): ?>
<div style="text-align: center; padding: 3rem 1rem;">
    <h3 style="color: var(--text-muted); margin-bottom: 0.5rem; font-weight: 500;">Trash Bin is Empty</h3>
    <p style="color: var(--text-muted); font-size: 0.9rem;">No deleted journal entries found. Items moved to trash will appear here.</p>
</div>

<?php else: ?>
<div class="card" style="padding: 0; overflow: hidden; border: 1px solid #fca5a5;">
    <div style="background: #fef2f2; padding: 0.75rem 1rem; border-bottom: 1px solid #fca5a5; display: flex; align-items: center; gap: 0.5rem;">
        <i data-lucide="alert-triangle" style="width:15px;height:15px; color: #ef4444;"></i>
        <span style="font-size: 0.85rem; color: #991b1b;">Items in the trash are excluded from all reports. Restore them to include in balances again.</span>
    </div>
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 12%">Date</th>
                    <th style="width: 20%">Particulars / Account Title</th>
                    <th style="width: 20%">Explanation</th>
                    <th style="width: 13%">Ref No.</th>
                    <th class="text-right" style="width: 10%">Debit</th>
                    <th class="text-right" style="width: 10%">Credit</th>
                    <th style="width: 15%">Deleted On</th>
                    <th style="width: 10%; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($trashed as $tx):
                    $stmtLine = $db->prepare("SELECT l.*, a.code, a.name FROM journal_entry_lines l JOIN accounts a ON l.account_id = a.id WHERE l.journal_entry_id = ?");
                    $stmtLine->bind_param('i', $tx['id']);
                    $stmtLine->execute();
                    $lines = $stmtLine->get_result()->fetch_all(MYSQLI_ASSOC);
                ?>
                <?php foreach($lines as $index => $line): ?>
                <tr style="opacity: 0.75;">
                    <td><?= $index === 0 ? date('M d, Y', strtotime($tx['date'])) : '' ?></td>
                    <td style="padding-left: <?= $line['credit'] > 0 ? '2.5rem' : '1rem' ?>; font-weight: 500; color: #6b7280;">
                        <?= htmlspecialchars($line['name']) ?>
                    </td>
                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        <?= $index === 0 ? htmlspecialchars($tx['description']) : '' ?>
                    </td>
                    <td style="font-family: monospace; font-size: 0.85rem; color: #6b7280;">
                        <?= $index === 0 ? htmlspecialchars($tx['reference_no'] ?: '—') : '' ?>
                    </td>
                    <td class="text-right" style="color: #6b7280;"><?= $line['debit'] > 0 ? '₱'.number_format($line['debit'], 2) : '' ?></td>
                    <td class="text-right" style="color: #6b7280;"><?= $line['credit'] > 0 ? '₱'.number_format($line['credit'], 2) : '' ?></td>
                    <td style="font-size: 0.8rem; color: #ef4444;">
                        <?= $index === 0 ? date('M d, Y H:i', strtotime($tx['deleted_at'])) : '' ?>
                    </td>
                    <td style="text-align: center; vertical-align: middle;">
                        <?php if ($index === 0): ?>
                        <div class="flex gap-1" style="justify-content: center;">
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Restore this entry to Journal?');">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="id" value="<?= $tx['id'] ?>">
                                <button type="submit" class="btn" style="padding: 4px 10px; font-size: 0.75rem; background: #dcfce7; color: #166534; border: 1px solid #86efac;" title="Restore">
                                    <i data-lucide="rotate-ccw" style="width:12px;height:12px;"></i> Restore
                                </button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete? This CANNOT be undone!');">
                                <input type="hidden" name="action" value="delete_permanent">
                                <input type="hidden" name="id" value="<?= $tx['id'] ?>">
                                <button type="submit" class="btn" style="padding: 4px 10px; font-size: 0.75rem; background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;" title="Delete Permanently">
                                    <i data-lucide="x" style="width:12px;height:12px;"></i>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr><td colspan="8" style="border-bottom: 2px solid #fca5a5; padding: 0;"></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
