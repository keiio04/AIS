<?php
// ============================================================
// config.php — Global constants
// ============================================================

$is_railway    = (bool)(getenv('RAILWAY_ENVIRONMENT') || getenv('RAILWAY_PUBLIC_DOMAIN'));
$is_production = $is_railway || (getenv('APP_ENV') === 'production');

// Security Hardening: suppress error display in production, log securely
ini_set('log_errors', '1');
if ($is_production) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// Session Cookie Security (Area C - Cybersecurity Plan)
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.use_only_cookies', '1');
}

// Global HTTP Security Headers (Area E - Cybersecurity Plan)
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("X-XSS-Protection: 1; mode=block");

    // Enforce HSTS if running over HTTPS
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    if ($is_https) {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
    }

    // Content Security Policy: whitelist necessary assets (Google Auth, fonts, icons, charts)
    $csp = "default-src 'self' 'unsafe-inline' 'unsafe-eval' https: data: blob:; "
         . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://accounts.google.com https://apis.google.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://accounts.google.com https://cdnjs.cloudflare.com; "
         . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
         . "frame-src 'self' https://accounts.google.com https://content.googleapis.com; "
         . "connect-src 'self' https://accounts.google.com https://www.googleapis.com https://apis.google.com; "
         . "img-src 'self' data: https: blob:;";
    header("Content-Security-Policy: " . $csp);
}


// Dynamically determine the base URL
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
$script_dir = str_replace('\\', '/', $script_dir); // Handle Windows paths
if ($script_dir === '/' || $script_dir === '.') $script_dir = '';

// Because config.php is usually included from different subdirectories,
// we just hardcode it to '/' if running via php -S, or '/AIS_PHP/' if via XAMPP.
// Since php built-in server usually lacks the directory name in SCRIPT_NAME if run from inside it:
$is_php_server = php_sapi_name() === 'cli-server';
$default_base = $is_php_server || $is_railway ? '/' : '/AIS_PHP/';

define('BASE_URL', getenv('APP_BASE_URL') ?: $default_base);
define('APP_NAME', 'TALA-AIS');
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '120548778464-ubkm83rg43upp89pksnspr1ccf3lfr1k.apps.googleusercontent.com');

// Load Centralized Security & Data Protection Helpers (Step D)
require_once __DIR__ . '/includes/security.php';

// Load local environment config if present (ignored by git)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// ============================================================
// Brevo REST API & SMTP Email Configuration
// ============================================================
if (!defined('BREVO_API_KEY')) define('BREVO_API_KEY', getenv('BREVO_API_KEY') ?: (getenv('BREVO_APT_KEY') ?: ''));
if (!defined('SMTP_HOST')) define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp-relay.brevo.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', getenv('SMTP_PORT') ? (int)getenv('SMTP_PORT') : 587);
if (!defined('SMTP_USER')) define('SMTP_USER', getenv('SMTP_USER') ?: '');
if (!defined('SMTP_PASS')) define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: '');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'TALA-AIS Security');
