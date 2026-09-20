<?php
// ============================================================
// db.php — Database connection singleton
// Include this file in every PHP page that needs database.
// ============================================================

$db_host = getenv('MYSQLHOST') ?: (getenv('DB_HOST') ?: 'localhost');
$db_user = getenv('MYSQLUSER') ?: (getenv('DB_USER') ?: 'root');
$db_pass = getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : (getenv('DB_PASS') ?: '');
$db_name = getenv('MYSQLDATABASE') ?: (getenv('DB_NAME') ?: 'accounting_db');
$db_port = getenv('MYSQLPORT') ?: (getenv('DB_PORT') ?: 3306);

// Support DATABASE_URL or MYSQL_URL if set by Railway/Heroku
$database_url = getenv('DATABASE_URL') ?: (getenv('MYSQL_URL') ?: getenv('MYSQL_PRIVATE_URL'));
if ($database_url) {
    $parsed = parse_url($database_url);
    if ($parsed) {
        $db_host = $parsed['host'] ?? $db_host;
        $db_user = $parsed['user'] ?? $db_user;
        $db_pass = $parsed['pass'] ?? $db_pass;
        $db_port = $parsed['port'] ?? $db_port;
        if (!empty($parsed['path'])) {
            $db_name = ltrim($parsed['path'], '/');
        }
    }
}

define('DB_HOST', $db_host);
define('DB_USER', $db_user);
define('DB_PASS', $db_pass);
define('DB_NAME', $db_name);
define('DB_PORT', (int)$db_port);


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
        
        // Automatic Table Initialization (Self-Healing on first launch)
        $initQueries = [
            "CREATE TABLE IF NOT EXISTS `users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `email` VARCHAR(150) NOT NULL UNIQUE,
                `password` VARCHAR(255) NOT NULL,
                `role` ENUM('Admin','Instructor','Student') NOT NULL DEFAULT 'Student',
                `active_company_id` INT NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS `companies` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `name` VARCHAR(150) NOT NULL,
                `address` TEXT,
                `business_type` ENUM('Service','Merchandising','Manufacturing') NOT NULL DEFAULT 'Service',
                `tax_registered` TINYINT(1) NOT NULL DEFAULT 0,
                `tax_type` ENUM('VAT','Percentage Tax') DEFAULT NULL,
                `period_type` ENUM('Calendar','Fiscal') NOT NULL DEFAULT 'Calendar',
                `fiscal_start_month` VARCHAR(20) DEFAULT NULL,
                `fiscal_start_date` INT DEFAULT NULL,
                `fiscal_year_end` VARCHAR(20) DEFAULT 'December 31',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS `accounts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `company_id` INT NOT NULL,
                `code` VARCHAR(20) NOT NULL,
                `name` VARCHAR(200) NOT NULL,
                `category` ENUM('Assets','Liabilities','Equity','Revenue','Expenses') NOT NULL,
                `sub_category` VARCHAR(100),
                `description` TEXT,
                `opening_balance` DECIMAL(15,2) DEFAULT 0.00,
                FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS `customers` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `company_id` INT NOT NULL,
                `code` VARCHAR(20) NULL,
                `name` VARCHAR(150) NOT NULL,
                `contact_person` VARCHAR(150) NULL,
                `email` VARCHAR(150) NULL,
                `phone` VARCHAR(50) NULL,
                `address` VARCHAR(255) NULL,
                `tin` VARCHAR(50) NULL,
                `terms` VARCHAR(50) NULL,
                `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
                `notes` TEXT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_customers_company (`company_id`)
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS `suppliers` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `company_id` INT NOT NULL,
                `code` VARCHAR(20) NULL,
                `name` VARCHAR(150) NOT NULL,
                `contact_person` VARCHAR(150) NULL,
                `email` VARCHAR(150) NULL,
                `phone` VARCHAR(50) NULL,
                `address` VARCHAR(255) NULL,
                `tin` VARCHAR(50) NULL,
                `terms` VARCHAR(50) NULL,
                `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
                `notes` TEXT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_suppliers_company (`company_id`)
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS `employees` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `company_id` INT NOT NULL,
                `code` VARCHAR(20) NULL,
                `name` VARCHAR(150) NOT NULL,
                `position` VARCHAR(150) NULL,
                `department` VARCHAR(150) NULL,
                `email` VARCHAR(150) NULL,
                `phone` VARCHAR(50) NULL,
                `address` VARCHAR(255) NULL,
                `date_hired` DATE NULL,
                `rate` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `pay_frequency` VARCHAR(50) NULL,
                `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
                `notes` TEXT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_employees_company (`company_id`)
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS `journal_entries` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `company_id` INT NOT NULL,
                `reference_no` VARCHAR(50),
                `date` DATE NOT NULL,
                `description` VARCHAR(255),
                `is_taxable` TINYINT(1) NOT NULL DEFAULT 0,
                `particulars` TEXT,
                `type` ENUM('Operating','Investing','Financing','Non-Cash') DEFAULT 'Operating',
                `journal_id` VARCHAR(5) NOT NULL DEFAULT 'GJ',
                `entity_id` INT NULL,
                `entity_type` ENUM('customer', 'supplier') NULL,
                `vendor_name` VARCHAR(150) DEFAULT NULL,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS `journal_entry_lines` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `journal_entry_id` INT NOT NULL,
                `account_id` INT NOT NULL,
                `description` VARCHAR(255) DEFAULT NULL,
                `vendor_name` VARCHAR(150) DEFAULT NULL,
                `debit` DECIMAL(15,2) DEFAULT 0.00,
                `credit` DECIMAL(15,2) DEFAULT 0.00,
                FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS `notes_to_fs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `company_id` INT NOT NULL,
                `note_number` VARCHAR(10),
                `title` VARCHAR(150),
                `description` TEXT,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS `activity_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `company_id` INT DEFAULT NULL,
                `user_id` INT,
                `action` VARCHAR(255),
                `module` VARCHAR(100) DEFAULT NULL,
                `description` TEXT DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
                FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS `instructor_students` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `instructor_id` INT NOT NULL,
                `student_id` INT NOT NULL,
                `section` VARCHAR(100) DEFAULT 'Section 1',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_inst_stud` (`instructor_id`, `student_id`),
                FOREIGN KEY (`instructor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS `password_resets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(150) NOT NULL,
                `otp` VARCHAR(10) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_otp (`email`, `otp`)
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS `system_notices` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `sender_id` INT NULL,
                `title` VARCHAR(150) NOT NULL,
                `message` TEXT NOT NULL,
                `severity` VARCHAR(20) NOT NULL DEFAULT 'info',
                `audience` VARCHAR(20) NOT NULL DEFAULT 'all',
                `recipient_count` INT NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `notifications` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `notice_id` INT NULL,
                `type` VARCHAR(30) NOT NULL DEFAULT 'system',
                `severity` VARCHAR(20) NOT NULL DEFAULT 'info',
                `title` VARCHAR(150) NOT NULL,
                `message` TEXT NULL,
                `link` VARCHAR(255) NULL,
                `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_unread (`user_id`, `is_read`, `created_at`),
                INDEX idx_notice (`notice_id`),
                FOREIGN KEY (`notice_id`) REFERENCES `system_notices`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "INSERT INTO `users` (`name`, `email`, `password`, `role`)
            SELECT 'Administrator', 'admin@ais.com',
                '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin'
            WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `email` = 'admin@ais.com')"
        ];

        foreach ($initQueries as $query) {
            try {
                $conn->query($query);
            } catch (Exception $e) {
                // Ignore errors for already existing tables/constraints
            }
        }

        // Auto-migrations for dynamic customer/vendor and is_taxable
        try { $conn->query("ALTER TABLE journal_entries ADD COLUMN is_taxable TINYINT(1) NOT NULL DEFAULT 0 AFTER description"); } catch (Exception $e) {}
        try { $conn->query("ALTER TABLE journal_entries ADD COLUMN entity_id INT NULL AFTER journal_id"); } catch (Exception $e) {}
        try { $conn->query("ALTER TABLE journal_entries ADD COLUMN entity_type ENUM('customer', 'supplier') NULL AFTER entity_id"); } catch (Exception $e) {}
        try { $conn->query("ALTER TABLE customers ADD COLUMN code VARCHAR(20) NULL AFTER company_id"); } catch (Exception $e) {}
        try { $conn->query("ALTER TABLE suppliers ADD COLUMN code VARCHAR(20) NULL AFTER company_id"); } catch (Exception $e) {}
        try { $conn->query("ALTER TABLE employees ADD COLUMN code VARCHAR(20) NULL AFTER company_id"); } catch (Exception $e) {}
    }
    return $conn;
}

?>
