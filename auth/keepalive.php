<?php
// ============================================================
// keepalive.php — Session Keepalive Endpoint (Step C)
// Called via fetch() by the frontend session timeout warning toast.
// Refreshes $_SESSION['last_activity'] to prevent auto-logout.
// ============================================================
require_once '../config.php';
require_once '../includes/csrf.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only allow POST requests from authenticated sessions
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

// Refresh last activity timestamp
$_SESSION['last_activity'] = time();

echo json_encode(['ok' => true, 'ts' => time()]);
