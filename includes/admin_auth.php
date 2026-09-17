<?php
// ============================================================
// admin_auth.php — Protect pages from non-admin access
// ============================================================

require_once __DIR__ . '/auth.php';

// Verify Admin Role
if ($_SESSION['user_role'] !== 'Admin') {
    // Redirect non-admins to their dashboard with an error
    header('Location: ' . BASE_URL . 'pages/dashboard.php?error=access_denied');
    exit;
}

// Lets includes/header.php know it's being loaded from an Admin Panel page
// (admin/dashboard.php, users.php, companies.php, assignments.php, logs.php)
// so it can render the admin sidebar instead of the accounting sidebar.
if (!defined('IS_ADMIN_PANEL')) {
    define('IS_ADMIN_PANEL', true);
}