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
