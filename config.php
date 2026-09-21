<?php
// ============================================================
// config.php — Global constants
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '1');

$is_railway = getenv('RAILWAY_ENVIRONMENT') || getenv('RAILWAY_PUBLIC_DOMAIN');

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
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');

// Load local environment config if present (ignored by git)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// ============================================================
// Brevo REST API & SMTP Email Configuration
// ============================================================
define('BREVO_API_KEY', getenv('BREVO_API_KEY') ?: (getenv('BREVO_APT_KEY') ?: ''));
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp-relay.brevo.com');
define('SMTP_PORT', getenv('SMTP_PORT') ? (int)getenv('SMTP_PORT') : 587);
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: '');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'TALA-AIS Security');
