<?php
// ============================================================
// csrf.php — Cross-Site Request Forgery (CSRF) Protection
// & Secure Session Helpers
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session cookie parameters if headers not sent
    if (!headers_sent()) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
    }
    session_start();
}

/**
 * Generate or retrieve the current session's CSRF token.
 */
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/**
 * Return a hidden HTML input containing the CSRF token.
 */
if (!function_exists('csrf_input')) {
    function csrf_input(): string {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

/**
 * Verify whether the incoming POST request has a valid CSRF token.
 */
if (!function_exists('csrf_verify')) {
    function csrf_verify(): bool {
        $submittedToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken   = $_SESSION['csrf_token'] ?? '';
        if (empty($submittedToken) || empty($sessionToken)) {
            return false;
        }
        return hash_equals($sessionToken, $submittedToken);
    }
}

/**
 * Abort with HTTP 403 Forbidden if CSRF token is invalid on a POST request.
 */
if (!function_exists('require_csrf')) {
    function require_csrf(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                http_response_code(403);
                die('<div style="padding:2rem;font-family:sans-serif;max-width:520px;margin:3rem auto;text-align:center;background:#0f172a;color:#fff;border-radius:12px;border:1px solid #334155;">
                        <h2 style="color:#ef4444;margin-bottom:0.5rem;">Security Verification Failed</h2>
                        <p style="color:#94a3b8;font-size:0.95rem;">Invalid or expired security token (CSRF). Please refresh the page and try again.</p>
                        <p style="margin-top:1.5rem;"><a href="javascript:history.back()" style="display:inline-block;padding:0.5rem 1.25rem;background:#3b82f6;color:#fff;text-decoration:none;border-radius:99px;font-weight:600;font-size:0.85rem;">&larr; Go Back</a></p>
                    </div>');
            }
        }
    }
}
