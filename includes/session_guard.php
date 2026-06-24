<?php
// ============================================================
// session_guard.php — Protect pages from unauthenticated access
// Include at the TOP of every protected page.
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}
