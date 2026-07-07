<?php
// ============================================================
// db.php — Database connection singleton
// Include this file in every PHP page that needs database.
// ============================================================

define('DB_HOST', getenv('MYSQLHOST') ?: (getenv('DB_HOST') ?: 'localhost'));
define('DB_USER', getenv('MYSQLUSER') ?: (getenv('DB_USER') ?: 'root'));
define('DB_PASS', getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : (getenv('DB_PASS') ?: ''));
define('DB_NAME', getenv('MYSQLDATABASE') ?: (getenv('DB_NAME') ?: 'accounting_db'));
define('DB_PORT', getenv('MYSQLPORT') ?: (getenv('DB_PORT') ?: 3306));

function get_db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($conn->connect_error) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}
?>
