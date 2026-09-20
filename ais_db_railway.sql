-- Railway-compatible schema (no CREATE DATABASE / USE statements)
-- Users table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('Admin','Instructor','Student') NOT NULL DEFAULT 'Student',
    `active_company_id` INT NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Companies table
CREATE TABLE IF NOT EXISTS `companies` (
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
) ENGINE=InnoDB;

-- Accounts table
CREATE TABLE IF NOT EXISTS `accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `company_id` INT NOT NULL,
    `code` VARCHAR(20) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `category` ENUM('Assets','Liabilities','Equity','Revenue','Expenses') NOT NULL,
    `sub_category` VARCHAR(100),
    `description` TEXT,
    `opening_balance` DECIMAL(15,2) DEFAULT 0.00,
    FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Journal entries table
CREATE TABLE IF NOT EXISTS `journal_entries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `company_id` INT NOT NULL,
    `reference_no` VARCHAR(50),
    `date` DATE NOT NULL,
    `description` VARCHAR(255),
    `particulars` TEXT,
    `type` ENUM('Operating','Investing','Financing','Non-Cash') DEFAULT 'Operating',
    `journal_id` VARCHAR(5) NOT NULL DEFAULT 'GJ',
    `vendor_name` VARCHAR(150) DEFAULT NULL,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Journal entry lines table
CREATE TABLE IF NOT EXISTS `journal_entry_lines` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `journal_entry_id` INT NOT NULL,
    `account_id` INT NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `vendor_name` VARCHAR(150) DEFAULT NULL,
    `debit` DECIMAL(15,2) DEFAULT 0.00,
    `credit` DECIMAL(15,2) DEFAULT 0.00,
    FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Customers table (AR subsidiary ledger master list)
CREATE TABLE IF NOT EXISTS `customers` (
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
    INDEX idx_customers_company (`company_id`),
    FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Suppliers table (AP subsidiary ledger master list)
CREATE TABLE IF NOT EXISTS `suppliers` (
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
    INDEX idx_suppliers_company (`company_id`),
    FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Employees table
CREATE TABLE IF NOT EXISTS `employees` (
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
    INDEX idx_employees_company (`company_id`),
    FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Notes to financial statements table
CREATE TABLE IF NOT EXISTS `notes_to_fs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `company_id` INT NOT NULL,
    `note_number` VARCHAR(10),
    `title` VARCHAR(150),
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Activity logs table
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `company_id` INT DEFAULT NULL,
    `user_id` INT,
    `action` VARCHAR(255),
    `module` VARCHAR(100) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Instructor Students Assignment table
CREATE TABLE IF NOT EXISTS `instructor_students` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `instructor_id` INT NOT NULL,
    `student_id` INT NOT NULL,
    `section` VARCHAR(100) DEFAULT 'Section 1',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_inst_stud` (`instructor_id`, `student_id`),
    FOREIGN KEY (`instructor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Password Resets OTP table
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(150) NOT NULL,
    `otp` VARCHAR(10) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_otp (`email`, `otp`)
) ENGINE=InnoDB;

-- System Notices (admin broadcasts)
CREATE TABLE IF NOT EXISTS `system_notices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sender_id` INT NULL,
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `severity` VARCHAR(20) NOT NULL DEFAULT 'info',
    `audience` VARCHAR(20) NOT NULL DEFAULT 'all',
    `recipient_count` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notifications (user inbox)
CREATE TABLE IF NOT EXISTS `notifications` (
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
    CONSTRAINT fk_notif_notice FOREIGN KEY (`notice_id`)
        REFERENCES `system_notices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin user (password: password)
INSERT INTO `users` (`name`, `email`, `password`, `role`)
SELECT 'Administrator', 'admin@ais.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin'
WHERE NOT EXISTS (SELECT 1 FROM `users`);
