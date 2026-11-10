<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

try {
    $db = get_db();
    $db->query("TRUNCATE TABLE login_attempts");
    $db->query("DELETE FROM login_attempts");
    echo "<h2 style='color:#10b981;font-family:sans-serif;'>✅ IP Lockout Successfully Cleared!</h2>";
    echo "<p><a href='/auth/login.php'>Proceed to Login &rarr;</a></p>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
