<?php
// Small JSON endpoint used by the bell in includes/header.php.
// POST action=read (id) | action=read_all  -> only ever touches the logged-in user's own rows.
require_once '../config.php';
require_once '../db.php';
require_once '../includes/auth.php';
require_once '../includes/notifications.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$db     = get_db();
$userId = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action === 'read') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) notif_mark_read($db, $userId, $id);
} elseif ($action === 'read_all') {
    notif_mark_all_read($db, $userId);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

echo json_encode(['ok' => true, 'unread' => notif_unread_count($db, $userId)]);