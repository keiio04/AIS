<?php
// ============================================================
// session_guard.php — Protect pages from unauthenticated access
// STEP C: Session Auto-Timeout (5-minute idle limit)
// Include at the TOP of every protected page.
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Step C: Idle Session Timeout ──────────────────────────────
define('SESSION_IDLE_TIMEOUT', 300); // 5 minutes in seconds

if (!empty($_SESSION['user_id'])) {
    $lastActivity = $_SESSION['last_activity'] ?? time();
    $idleSeconds  = time() - $lastActivity;

    if ($idleSeconds > SESSION_IDLE_TIMEOUT) {
        // Log auto-logout to activity_logs before destroying session
        if (!empty($_SESSION['user_id'])) {
            try {
                if (!function_exists('get_db')) require_once __DIR__ . '/../db.php';
                $dbTimeout = get_db();
                $stmtT = $dbTimeout->prepare(
                    "INSERT INTO activity_logs (user_id, action, module, description)
                     VALUES (?, 'Auto-Logout', 'Session', 'Session expired due to 5-minute inactivity')"
                );
                $stmtT->bind_param('i', $_SESSION['user_id']);
                $stmtT->execute();
            } catch (Throwable $e) { /* silent — don't break logout flow */ }
        }

        // Destroy session completely
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        header('Location: ' . BASE_URL . 'auth/login.php?msg=session_expired');
        exit;
    }

    // Refresh last activity timestamp on every valid request
    $_SESSION['last_activity'] = time();

} else {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}
