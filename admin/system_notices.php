<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/admin_auth.php';
require_once '../includes/header.php';
require_once '../includes/notifications.php';

$db = get_db();

// ── Options ────────────────────────────────────────────────────────────
$severities = [
    'maintenance' => ['label' => 'Maintenance',  'icon' => 'wrench',        'color' => '#d97706', 'bg' => 'rgba(245,158,11,0.14)'],
    'warning'     => ['label' => 'Urgent',       'icon' => 'alert-triangle', 'color' => '#dc2626', 'bg' => 'rgba(239,68,68,0.14)'],
    'info'        => ['label' => 'Information',  'icon' => 'info',          'color' => '#2563eb', 'bg' => 'rgba(59,130,246,0.14)'],
    'success'     => ['label' => 'Back online',  'icon' => 'check-circle',  'color' => '#059669', 'bg' => 'rgba(16,185,129,0.14)'],
];
$audiences = [
    'all'        => 'Everyone (Instructors & Students)',
    'Instructor' => 'Instructors only',
    'Student'    => 'Students only',
];

// Recipient counts per role (Admins are not notified)
$roleCounts = ['Instructor' => 0, 'Student' => 0];
$resCounts = $db->query("SELECT role, COUNT(*) AS c FROM users WHERE role IN ('Instructor','Student') GROUP BY role");
foreach ($resCounts->fetch_all(MYSQLI_ASSOC) as $rc) {
    $roleCounts[$rc['role']] = (int)$rc['c'];
}
$audienceCounts = [
    'all'        => $roleCounts['Instructor'] + $roleCounts['Student'],
    'Instructor' => $roleCounts['Instructor'],
    'Student'    => $roleCounts['Student'],
];

// Character length that works even if the mbstring extension is off
function notice_len($s) { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }

// One-time form token: stops double-sends on refresh and blocks cross-site posts
if (empty($_SESSION['notice_token'])) {
    $_SESSION['notice_token'] = bin2hex(random_bytes(16));
}

$form = ['severity' => 'maintenance', 'audience' => 'all', 'title' => '', 'message' => ''];

// ── Handle POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $tokenOk = isset($_POST['token']) && hash_equals($_SESSION['notice_token'], (string)$_POST['token']);

    if ($_POST['action'] === 'send_notice') {
        $form['severity'] = $_POST['severity'] ?? 'info';
        $form['audience'] = $_POST['audience'] ?? 'all';
        $form['title']    = trim($_POST['title'] ?? '');
        $form['message']  = trim($_POST['message'] ?? '');

        if (!$tokenOk) {
            $error = "This form was already submitted or has expired. Please check the history below, then try again.";
        } elseif (!isset($severities[$form['severity']]) || !isset($audiences[$form['audience']])) {
            $error = "Invalid notice type or audience.";
        } elseif ($form['title'] === '' || $form['message'] === '') {
            $error = "Title and message are required.";
        } elseif (notice_len($form['title']) > 150) {
            $error = "Title must be 150 characters or fewer.";
        } elseif (notice_len($form['message']) > 1000) {
            $error = "Message must be 1000 characters or fewer.";
        } elseif ($audienceCounts[$form['audience']] === 0) {
            $error = "There are no users in that audience yet.";
        } else {
            try {
                $db->begin_transaction();

                $senderId = (int)$_SESSION['user_id'];
                $stmt = $db->prepare("INSERT INTO system_notices (sender_id, title, message, severity, audience) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('issss', $senderId, $form['title'], $form['message'], $form['severity'], $form['audience']);
                $stmt->execute();
                $noticeId = (int)$db->insert_id;

                if ($form['audience'] === 'all') {
                    $stmt = $db->prepare("INSERT INTO notifications (user_id, notice_id, type, severity, title, message)
                                          SELECT id, ?, 'system', ?, ?, ? FROM users WHERE role IN ('Instructor','Student')");
                    $stmt->bind_param('isss', $noticeId, $form['severity'], $form['title'], $form['message']);
                } else {
                    $stmt = $db->prepare("INSERT INTO notifications (user_id, notice_id, type, severity, title, message)
                                          SELECT id, ?, 'system', ?, ?, ? FROM users WHERE role = ?");
                    $stmt->bind_param('issss', $noticeId, $form['severity'], $form['title'], $form['message'], $form['audience']);
                }
                $stmt->execute();
                $recipients = (int)$stmt->affected_rows;

                $stmt = $db->prepare("UPDATE system_notices SET recipient_count = ? WHERE id = ?");
                $stmt->bind_param('ii', $recipients, $noticeId);
                $stmt->execute();

                $db->commit();

                log_activity($db, $_SESSION['user_id'], 'Create', 'System Notices', "Sent {$form['severity']} notice \"{$form['title']}\" to {$recipients} user(s) ({$audiences[$form['audience']]})");
                $success = "Notice sent to {$recipients} user" . ($recipients === 1 ? '' : 's') . ".";
                $_SESSION['notice_token'] = bin2hex(random_bytes(16));   // rotate so a refresh can't re-send
                $form = ['severity' => 'maintenance', 'audience' => 'all', 'title' => '', 'message' => ''];
            } catch (Throwable $e) {
                try { $db->rollback(); } catch (Throwable $e2) {}
                $error = "Could not send the notice. Make sure sql/notifications.sql has been imported. (" . $e->getMessage() . ")";
            }
        }

    } elseif ($_POST['action'] === 'delete_notice') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$tokenOk) {
            $error = "This form has expired. Please reload the page and try again.";
        } else {
            $lookup = $db->prepare("SELECT title FROM system_notices WHERE id = ?");
            $lookup->bind_param('i', $id);
            $lookup->execute();
            $row = $lookup->get_result()->fetch_assoc();
            if ($row) {
                $stmt = $db->prepare("DELETE FROM notifications WHERE notice_id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt = $db->prepare("DELETE FROM system_notices WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                log_activity($db, $_SESSION['user_id'], 'Delete', 'System Notices', "Removed notice \"{$row['title']}\" (ID: $id) from all users");
                $success = "Notice removed. It no longer appears in users' notifications.";
            } else {
                $error = "That notice was already removed.";
            }
        }
    }
}

// ── History ────────────────────────────────────────────────────────────
try {
    $notices = $db->query("
        SELECT n.id, n.title, n.message, n.severity, n.audience, n.recipient_count, n.created_at,
               u.name AS sender_name,
               (SELECT COUNT(*) FROM notifications x WHERE x.notice_id = n.id AND x.is_read = 1) AS read_count
        FROM system_notices n
        LEFT JOIN users u ON u.id = n.sender_id
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT 50
    ")->fetch_all(MYSQLI_ASSOC);
    $tablesReady = true;
} catch (Throwable $e) {
    $notices = [];
    $tablesReady = false;
}
?>

<style>
.sn-grid { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 1.25rem; margin-bottom: 1.5rem; align-items: start; }
@media (max-width: 960px) { .sn-grid { grid-template-columns: 1fr; } }
.sn-types { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; }
@media (max-width: 640px) { .sn-types { grid-template-columns: repeat(2, 1fr); } }
.sn-type { position: relative; cursor: pointer; display: block; }
.sn-type input { position: absolute; opacity: 0; pointer-events: none; }
.sn-type-body {
    display: flex; align-items: center; gap: 0.5rem; padding: 0.6rem 0.75rem;
    border: 1px solid var(--border-color); border-radius: var(--radius-md);
    font-size: 0.8125rem; font-weight: 500; color: var(--text-primary);
    transition: border-color 0.15s, background 0.15s;
}
.sn-type:hover .sn-type-body { border-color: var(--c); }
.sn-type input:checked + .sn-type-body { border-color: var(--c); background: var(--tint); font-weight: 600; }
.sn-type input:focus-visible + .sn-type-body { outline: 2px solid var(--c); outline-offset: 2px; }
.sn-type-body svg { color: var(--c); flex-shrink: 0; }
.sn-templates { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.sn-chip {
    background: var(--bg-tertiary); border: 1px solid var(--border-color); color: var(--text-primary);
    border-radius: 99px; padding: 0.3rem 0.8rem; font-size: 0.78rem; cursor: pointer;
}
.sn-chip:hover { border-color: var(--primary-color); color: var(--primary-color); }
.sn-preview {
    display: flex; gap: 0.7rem; padding: 0.85rem 1rem; border: 1px solid var(--border-color);
    border-radius: var(--radius-md); background: var(--bg-primary, var(--bg-secondary));
    border-left: 3px solid #3b82f6;
}
.sn-pill { display: inline-flex; align-items: center; gap: 0.3rem; padding: 2px 9px; border-radius: 99px; font-size: 0.72rem; font-weight: 600; }
.sn-clamp { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
</style>

<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">System Notices</h1>
        <p class="page-subtitle">Send maintenance alerts and system-wide messages. They appear in each user's notification bell.</p>
    </div>
</div>

<?php if (isset($success)): ?>
    <div style="background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= htmlspecialchars($success) ?>
    </div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>
<?php if (!$tablesReady): ?>
    <div style="background: #fef3c7; color: #92400e; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        The notification tables don't exist yet. Import <strong>sql/notifications.sql</strong> in phpMyAdmin, then reload this page.
    </div>
<?php endif; ?>

<div class="sn-grid">

    <!-- Compose -->
    <div class="card" style="padding: 1.5rem;">
        <form method="POST" id="noticeForm" onsubmit="return confirmSend();">
            <input type="hidden" name="action" value="send_notice">
            <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['notice_token']) ?>">

            <div class="form-group">
                <label class="form-label">Type</label>
                <div class="sn-types">
                    <?php foreach ($severities as $key => $s): ?>
                    <label class="sn-type" style="--c: <?= $s['color'] ?>; --tint: <?= $s['bg'] ?>;">
                        <input type="radio" name="severity" value="<?= $key ?>" <?= $form['severity'] === $key ? 'checked' : '' ?> onchange="updatePreview()">
                        <span class="sn-type-body">
                            <i data-lucide="<?= $s['icon'] ?>" style="width:16px;height:16px;"></i>
                            <?= $s['label'] ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Start from a template</label>
                <div class="sn-templates">
                    <button type="button" class="sn-chip" onclick="applyTemplate('maintenance')">Scheduled maintenance</button>
                    <button type="button" class="sn-chip" onclick="applyTemplate('online')">System back online</button>
                    <button type="button" class="sn-chip" onclick="applyTemplate('emergency')">Unplanned downtime</button>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Title</label>
                <input type="text" name="title" id="noticeTitle" class="form-control" maxlength="150" required
                       placeholder="e.g. Scheduled system maintenance" value="<?= htmlspecialchars($form['title']) ?>" oninput="updatePreview()">
            </div>

            <div class="form-group">
                <label class="form-label">Message</label>
                <textarea name="message" id="noticeMessage" class="form-control" rows="5" maxlength="1000" required
                          placeholder="What is happening, when, and what should users do?" oninput="updatePreview()"><?= htmlspecialchars($form['message']) ?></textarea>
                <div class="text-muted" style="font-size: 0.75rem; text-align: right; margin-top: 0.25rem;"><span id="msgCount">0</span> / 1000</div>
            </div>

            <div class="form-group">
                <label class="form-label">Send to</label>
                <select name="audience" id="noticeAudience" class="form-control">
                    <?php foreach ($audiences as $key => $label): ?>
                    <option value="<?= $key ?>" data-count="<?= $audienceCounts[$key] ?>" <?= $form['audience'] === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?> — <?= $audienceCounts[$key] ?> user<?= $audienceCounts[$key] === 1 ? '' : 's' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="send" style="width:15px;height:15px;"></i> Send Notice
                </button>
            </div>
        </form>
    </div>

    <!-- Preview -->
    <div class="card" style="padding: 1.25rem;">
        <div style="font-size: 0.85rem; font-weight: 600; margin-bottom: 0.75rem;">How users will see it</div>
        <div class="sn-preview" id="previewBox">
            <div id="previewIcon" style="width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;"></div>
            <div style="min-width: 0; flex: 1;">
                <div id="previewTitle" style="font-size: 0.8125rem; font-weight: 600; color: var(--text-primary); word-break: break-word;"></div>
                <div id="previewMsg" style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px; white-space: pre-line; word-break: break-word;"></div>
                <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 4px; opacity: 0.8;">Just now</div>
            </div>
        </div>
        <div class="text-muted" style="font-size: 0.78rem; margin-top: 1rem; line-height: 1.5;">
            Users see the notice the next time a page loads. Admin accounts are not notified. If you send something by mistake, remove it from the history below and it disappears for everyone.
        </div>
    </div>
</div>

<!-- History -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-color); font-weight: 600; font-size: 0.95rem;">Sent notices</div>
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 160px;">Sent</th>
                    <th style="width: 130px;">Type</th>
                    <th>Notice</th>
                    <th style="width: 190px;">Audience</th>
                    <th class="text-center" style="width: 110px;">Read</th>
                    <th class="text-center" style="width: 80px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($notices as $n):
                    $sv = $severities[$n['severity']] ?? $severities['info'];
                    $aud = $audiences[$n['audience']] ?? $n['audience'];
                ?>
                <tr>
                    <td style="font-size: 0.8125rem; color: var(--text-muted);">
                        <?= date('M j, Y h:i A', strtotime($n['created_at'])) ?>
                        <div style="font-size: 0.72rem;">by <?= htmlspecialchars($n['sender_name'] ?? 'Unknown') ?></div>
                    </td>
                    <td>
                        <span class="sn-pill" style="color: <?= $sv['color'] ?>; background: <?= $sv['bg'] ?>;"><?= $sv['label'] ?></span>
                    </td>
                    <td>
                        <div style="font-weight: 500;"><?= htmlspecialchars($n['title']) ?></div>
                        <div class="text-muted sn-clamp" style="font-size: 0.78rem;" title="<?= htmlspecialchars($n['message']) ?>"><?= htmlspecialchars($n['message']) ?></div>
                    </td>
                    <td style="font-size: 0.8125rem;">
                        <?= htmlspecialchars($aud) ?>
                        <div class="text-muted" style="font-size: 0.72rem;"><?= (int)$n['recipient_count'] ?> recipient<?= (int)$n['recipient_count'] === 1 ? '' : 's' ?></div>
                    </td>
                    <td class="text-center" style="font-size: 0.8125rem;">
                        <?= (int)$n['read_count'] ?> / <?= (int)$n['recipient_count'] ?>
                    </td>
                    <td>
                        <div class="flex justify-center">
                            <form method="POST" onsubmit="return confirm('Remove this notice? It will disappear from every user\'s notifications.');" style="display:inline;">
                                <input type="hidden" name="action" value="delete_notice">
                                <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['notice_token']) ?>">
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                <button type="submit" class="icon-btn text-danger" title="Remove notice">
                                    <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($notices) === 0): ?>
                <tr><td colspan="6" class="text-center text-muted" style="padding: 2rem;">No notices sent yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
var SEVERITIES = <?= json_encode(array_map(function ($s) { return ['icon' => $s['icon'], 'color' => $s['color'], 'bg' => $s['bg']]; }, $severities)) ?>;

var TEMPLATES = {
    maintenance: {
        severity: 'maintenance',
        title: 'Scheduled system maintenance',
        message: 'TALA-AIS will be unavailable on [date] from [start time] to [end time] for scheduled maintenance.\nPlease save your work and log out before then.'
    },
    online: {
        severity: 'success',
        title: 'System is back online',
        message: 'The scheduled maintenance is complete and TALA-AIS is available again. Thank you for your patience. You can continue your work now.'
    },
    emergency: {
        severity: 'warning',
        title: 'Temporary system downtime',
        message: 'We are experiencing a technical issue and the system may be unavailable for a while. We are working on it and will post an update here shortly. Please avoid submitting work until then.'
    }
};

function selectedSeverity() {
    var el = document.querySelector('input[name="severity"]:checked');
    return el ? el.value : 'info';
}

function updatePreview() {
    var sv = SEVERITIES[selectedSeverity()] || SEVERITIES.info;
    var title = document.getElementById('noticeTitle').value.trim();
    var msg = document.getElementById('noticeMessage').value.trim();

    document.getElementById('previewTitle').textContent = title || 'Your title appears here';
    document.getElementById('previewMsg').textContent = msg || 'Your message appears here.';
    document.getElementById('msgCount').textContent = document.getElementById('noticeMessage').value.length;

    var icon = document.getElementById('previewIcon');
    icon.style.background = sv.bg;
    icon.style.color = sv.color;
    icon.innerHTML = '<i data-lucide="' + sv.icon + '" style="width:16px;height:16px;"></i>';
    document.getElementById('previewBox').style.borderLeftColor = sv.color;
    if (window.lucide) lucide.createIcons();
}

function applyTemplate(key) {
    var t = TEMPLATES[key];
    if (!t) return;
    var hasText = document.getElementById('noticeTitle').value.trim() || document.getElementById('noticeMessage').value.trim();
    if (hasText && !confirm('Replace what you have typed with this template?')) return;
    document.querySelector('input[name="severity"][value="' + t.severity + '"]').checked = true;
    document.getElementById('noticeTitle').value = t.title;
    document.getElementById('noticeMessage').value = t.message;
    updatePreview();
}

function confirmSend() {
    var text = document.getElementById('noticeTitle').value + ' ' + document.getElementById('noticeMessage').value;
    if (/\[[^\]]+\]/.test(text) && !confirm('Your notice still has [bracketed placeholders]. Send it anyway?')) return false;
    var sel = document.getElementById('noticeAudience');
    var opt = sel.options[sel.selectedIndex];
    var n = opt.getAttribute('data-count');
    return confirm('Send this notice to ' + n + ' user' + (n === '1' ? '' : 's') + ' (' + opt.text.split(' — ')[0] + ')?');
}

updatePreview();
</script>

<?php require_once '../includes/footer.php'; ?>