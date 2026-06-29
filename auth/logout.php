<?php
require_once '../config.php';
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    $db = get_db();
    $stmtLog = $db->prepare("INSERT INTO activity_logs (user_id, action, module, description) VALUES (?, 'Logout', 'Authentication', 'User logged out')");
    $stmtLog->execute([$_SESSION['user_id']]);
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header('Location: ' . BASE_URL . 'auth/login.php');
exit;
