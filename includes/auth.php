<?php
// ============================================================
// auth.php — Protect pages from unauthenticated access
// Standardized authentication middleware
// ============================================================

require_once __DIR__ . '/csrf.php';

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

// Account status helpers
if (!function_exists('is_approved')) {
    function is_approved(): bool {
        return ($_SESSION['user_status'] ?? 'approved') === 'approved';
    }
}

// Role helpers
if (!function_exists('is_instructor')) {
    function is_instructor(): bool {
        $role = $_SESSION['user_role'] ?? '';
        // Admins always have access; Instructors must have approved status
        if ($role === 'Admin') {
            return true;
        }
        if ($role === 'Instructor' && is_approved()) {
            return true;
        }
        return false;
    }
}

if (!function_exists('require_instructor')) {
    function require_instructor(): void {
        $role = $_SESSION['user_role'] ?? '';
        $status = $_SESSION['user_status'] ?? 'approved';

        if ($role === 'Instructor' && $status !== 'approved') {
            http_response_code(403);
            die('<div style="padding:2.5rem;font-family:sans-serif;max-width:560px;margin:3rem auto;text-align:center;background:#0f172a;color:#fff;border-radius:16px;border:1px solid #334155;box-shadow:0 15px 35px rgba(0,0,0,0.4);">
                    <div style="font-size:2.5rem;margin-bottom:0.75rem;">⏳</div>
                    <h2 style="color:#f59e0b;margin-bottom:0.5rem;font-size:1.35rem;">Faculty Approval Required</h2>
                    <p style="color:#94a3b8;font-size:0.95rem;line-height:1.6;">Your instructor account is currently pending faculty verification and approval by an authorized administrator before access to the Instructor Dashboard is granted.</p>
                    <p style="margin-top:1.5rem;"><a href="' . BASE_URL . 'pages/dashboard.php" style="display:inline-block;padding:0.6rem 1.4rem;background:#3b82f6;color:#fff;text-decoration:none;border-radius:99px;font-weight:600;font-size:0.875rem;">&larr; View Account Status</a></p>
                </div>');
        }

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
