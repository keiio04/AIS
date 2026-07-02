<?php
// ============================================================
// auth.php — Protect pages from unauthenticated access
// Standardized authentication middleware
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure the user is logged in
if (empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

// Standard helper function for activity logging
if (!function_exists('log_activity')) {
    function log_activity($db, $user_id, $action, $module = null, $description = null) {
        $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, module, description) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('isss', $user_id, $action, $module, $description);
            $stmt->execute();
        }
    }
}
