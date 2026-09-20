<?php
/**
 * Notification helpers (TALA-AIS)
 *
 * Every function fails soft: if the notifications table has not been created
 * yet (sql/notifications.sql not imported), the rest of the app keeps working
 * and the bell simply shows nothing.
 */

/** Number of unread notifications for a user. */
function notif_unread_count($db, int $userId): int {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        return (int)$stmt->get_result()->fetch_row()[0];
    } catch (Throwable $e) {
        return 0;
    }
}

/** Latest notifications for a user (newest first). age_sec is computed by MySQL so PHP/DB timezones can't disagree. */
function notif_recent($db, int $userId, int $limit = 10): array {
    try {
        $limit = max(1, min(50, $limit));
        $stmt = $db->prepare("
            SELECT id, type, severity, title, message, link, is_read,
                   TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT $limit
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** Mark one notification as read (only if it belongs to this user). */
function notif_mark_read($db, int $userId, int $id): bool {
    try {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $id, $userId);
        return $stmt->execute();
    } catch (Throwable $e) {
        return false;
    }
}

/** Mark all of a user's notifications as read. */
function notif_mark_all_read($db, int $userId): bool {
    try {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param('i', $userId);
        return $stmt->execute();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Create one notification for one user. Reusable by later features
 * (new task, deadline reminder, feedback...).
 * $opts: type, severity, link, notice_id
 */
function notif_create($db, int $userId, string $title, string $message = '', array $opts = []): bool {
    try {
        $type     = $opts['type']      ?? 'system';
        $severity = $opts['severity']  ?? 'info';
        $link     = $opts['link']      ?? null;
        $noticeId = $opts['notice_id'] ?? null;
        $stmt = $db->prepare("INSERT INTO notifications (user_id, notice_id, type, severity, title, message, link) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iisssss', $userId, $noticeId, $type, $severity, $title, $message, $link);
        return $stmt->execute();
    } catch (Throwable $e) {
        return false;
    }
}

/** "5 min ago" style label from an age in seconds. */
function notif_time_ago(int $s): string {
    if ($s < 60)         return 'Just now';
    if ($s < 3600)       { $m = (int)floor($s / 60);      return $m . ' min ago'; }
    if ($s < 86400)      { $h = (int)floor($s / 3600);    return $h . ' hr ago'; }
    if ($s < 7 * 86400)  { $d = (int)floor($s / 86400);   return $d . ($d === 1 ? ' day ago' : ' days ago'); }
    if ($s < 30 * 86400) { $w = (int)floor($s / 604800);  return $w . ($w === 1 ? ' week ago' : ' weeks ago'); }
    $mo = (int)floor($s / 2592000);
    return $mo . ($mo === 1 ? ' month ago' : ' months ago');
}

/** Icon + colors for a notification: returns [lucide-icon, color, background]. */
function notif_visual(string $type, string $severity): array {
    if ($type !== 'system') {
        $byType = [
            'announcement' => ['megaphone',      '#2563eb', 'rgba(59,130,246,0.14)'],
            'task'         => ['clipboard-list', '#4f46e5', 'rgba(99,102,241,0.14)'],
            'deadline'     => ['clock',          '#d97706', 'rgba(245,158,11,0.14)'],
            'feedback'     => ['message-square', '#7c3aed', 'rgba(139,92,246,0.14)'],
        ];
        if (isset($byType[$type])) return $byType[$type];
    }
    $bySeverity = [
        'maintenance' => ['wrench',         '#d97706', 'rgba(245,158,11,0.14)'],
        'warning'     => ['alert-triangle', '#dc2626', 'rgba(239,68,68,0.14)'],
        'success'     => ['check-circle',   '#059669', 'rgba(16,185,129,0.14)'],
        'info'        => ['info',           '#2563eb', 'rgba(59,130,246,0.14)'],
    ];
    return $bySeverity[$severity] ?? $bySeverity['info'];
}

/** Turn a stored link into a safe URL ('' if none / not allowed). Relative links are prefixed with BASE_URL. */
function notif_url(?string $link): string {
    $link = trim((string)$link);
    if ($link === '') return '';
    if (preg_match('#^https?://#i', $link)) return $link;
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $link)) return '';   // javascript:, data:, etc.
    return BASE_URL . ltrim($link, '/');
}