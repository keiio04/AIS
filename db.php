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
        // Suppress warnings from mysqli constructor to handle them manually
        mysqli_report(MYSQLI_REPORT_OFF);
        
        $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($conn->connect_error) {
            http_response_code(500);
            $padding = str_repeat('<!-- Chrome padding to disable friendly error pages -->', 20);
            $errorDetails = "Database connection failed!<br>";
            $errorDetails .= "Error: " . $conn->connect_error . "<br>";
            $errorDetails .= "Host: " . DB_HOST . ":" . DB_PORT . "<br>";
            $errorDetails .= "User: " . DB_USER . "<br>";
            $errorDetails .= "Database: " . DB_NAME . "<br>";
            die("<html><body><h1>500 Internal Server Error</h1><p>{$errorDetails}</p>{$padding}</body></html>");
        }
        
        // Enable exceptions for queries so we can see what's actually failing in Railway
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        
        $conn->set_charset('utf8mb4');
        
        // Disable ONLY_FULL_GROUP_BY for Railway compatibility
        $conn->query("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
        
        // Auto-migrations for dynamic customer/vendor at header level
        try { $conn->query("ALTER TABLE journal_entries ADD COLUMN entity_id INT NULL AFTER journal_id"); } catch (Exception $e) {}
        try { $conn->query("ALTER TABLE journal_entries ADD COLUMN entity_type ENUM('customer', 'supplier') NULL AFTER entity_id"); } catch (Exception $e) {}
    }
    return $conn;
}
?>
