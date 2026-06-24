<?php
require_once '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
session_unset();
session_destroy();
header('Location: ' . BASE_URL . 'auth/login.php');
exit;
