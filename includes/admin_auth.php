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
