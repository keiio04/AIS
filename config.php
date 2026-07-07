<?php
// ============================================================
// config.php — Global constants
// ============================================================

$is_railway = getenv('RAILWAY_ENVIRONMENT') || getenv('RAILWAY_PUBLIC_DOMAIN');
$default_base = $is_railway ? '/' : '/AIS_PHP/';

define('BASE_URL', getenv('APP_BASE_URL') ?: $default_base);
define('APP_NAME', 'AccounTech AIS');
