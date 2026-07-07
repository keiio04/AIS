<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
// index.php — Entry point, redirect to login or dashboard
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit;
}

header('Location: ' . BASE_URL . 'auth/login.php');
exit;
