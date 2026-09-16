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

// Role helpers
if (!function_exists('is_instructor')) {
    function is_instructor(): bool {
        $role = $_SESSION['user_role'] ?? '';
        return in_array($role, ['Instructor', 'Admin']);
    }
}

if (!function_exists('require_instructor')) {
    function require_instructor(): void {
        if (!is_instructor()) {
            http_response_code(403);
            die('<div style="padding:2rem;font-family:sans-serif;max-width:520px;margin:3rem auto;text-align:center;background:#0f172a;color:#fff;border-radius:12px;border:1px solid #334155;">
                    <h2 style="color:#ef4444;margin-bottom:0.5rem;">Access Restricted</h2>
                    <p style="color:#94a3b8;font-size:0.95rem;">This page is restricted to Instructors and Administrators only.</p>
                    <p style="margin-top:1.5rem;"><a href="' . BASE_URL . 'pages/dashboard.php" style="display:inline-block;padding:0.5rem 1.25rem;background:#3b82f6;color:#fff;text-decoration:none;border-radius:99px;font-weight:600;font-size:0.85rem;">&larr; Return to Dashboard</a></p>
                </div>');
        }
    }
}
