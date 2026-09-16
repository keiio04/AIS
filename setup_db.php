<?php
// ============================================================
// setup_db.php — One-click database auto-migrator for Railway
// ============================================================
require_once 'config.php';
require_once 'db.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $db = get_db();
    
    $queries = [
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

        "CREATE TABLE IF NOT EXISTS `journal_entries` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `reference_no` VARCHAR(50),
            `date` DATE NOT NULL,
            `description` VARCHAR(255),
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

        "INSERT INTO `users` (`name`, `email`, `password`, `role`)
        SELECT 'Administrator', 'admin@ais.com',
            '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin'
        WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `email` = 'admin@ais.com')"
    ];

    echo "<!DOCTYPE html><html><head><title>Database Setup</title><style>body{font-family:system-ui;background:#0f172a;color:#fff;padding:2rem;display:flex;justify-content:center;} .card{background:#1e293b;padding:2rem;border-radius:12px;max-width:500px;border:1px solid #334155;} .ok{color:#4ade80;} a{display:inline-block;background:#3b82f6;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;margin-top:1rem;}</style></head><body><div class='card'>";
    echo "<h2 style='margin-top:0;'>🚀 Database Setup</h2>";

    foreach ($queries as $i => $q) {
        $db->query($q);
        echo "<div class='ok'>✔ Step " . ($i + 1) . " executed successfully.</div>";
    }

    echo "<h3 class='ok' style='margin-top:1.5rem;'>🎉 Database is 100% Ready!</h3>";
    echo "<p>Admin user created:<br><b>Email:</b> admin@ais.com<br><b>Password:</b> password</p>";
    echo "<a href='" . BASE_URL . "auth/login.php'>Go to Login Page →</a>";
    echo "</div></body></html>";

} catch (Exception $e) {
    echo "<!DOCTYPE html><html><body style='background:#0f172a;color:#f87171;font-family:sans-serif;padding:2rem;'>";
    echo "<h2>❌ Database Setup Error</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</body></html>";
}
