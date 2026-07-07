<?php
// ============================================================
// setup.php — One-time database setup for Railway deployment
// DELETE THIS FILE after running!
// ============================================================

// Security: require a setup key to prevent unauthorized access
$setup_key = $_GET['key'] ?? '';
if ($setup_key !== 'ais-setup-2024') {
    die('<h2 style="color:red">Access denied. Provide ?key=ais-setup-2024</h2>');
}

// Load DB config
require_once __DIR__ . '/config.php';

$host = getenv('MYSQLHOST') ?: 'localhost';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '';
$name = getenv('MYSQLDATABASE') ?: 'accounting_db';
$port = getenv('MYSQLPORT') ?: 3306;

$conn = new mysqli($host, $user, $pass, $name, $port);
if ($conn->connect_error) {
    die('<h2 style="color:red">Connection failed: ' . $conn->connect_error . '</h2>');
}
$conn->set_charset('utf8mb4');

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

    "INSERT INTO `users` (`name`, `email`, `password`, `role`)
     SELECT 'Administrator', 'admin@ais.com',
         '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin'
     WHERE NOT EXISTS (SELECT 1 FROM `users`)",
];

echo '<!DOCTYPE html><html><head><title>AIS Setup</title>
<style>body{font-family:sans-serif;max-width:700px;margin:2rem auto;padding:1rem;}
.ok{color:#16a34a;} .err{color:#dc2626;} .card{border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;margin-top:1rem;}
h1{color:#1d4ed8;}</style></head><body>';
echo '<h1>🚀 AIS Database Setup</h1>';
echo '<div class="card">';

$all_ok = true;
foreach ($queries as $i => $sql) {
    $label = "Query #" . ($i + 1);
    if ($conn->query($sql)) {
        echo "<p class='ok'>✅ $label — OK</p>";
    } else {
        echo "<p class='err'>❌ $label — Error: " . $conn->error . "</p>";
        $all_ok = false;
    }
}

echo '</div>';

if ($all_ok) {
    echo '<div class="card" style="border-color:#16a34a;background:#f0fdf4;">
        <h2 class="ok">✅ Database setup complete!</h2>
        <p>All tables created successfully.</p>
        <p><strong>Default login:</strong><br>
        Email: <code>admin@ais.com</code><br>
        Password: <code>password</code></p>
        <p style="color:#dc2626;font-weight:bold;">⚠️ Please DELETE setup.php from your project after this!</p>
        <p><a href="' . BASE_URL . 'auth/login.php">→ Go to Login Page</a></p>
    </div>';
} else {
    echo '<div class="card" style="border-color:#dc2626;background:#fef2f2;">
        <h2 class="err">❌ Some errors occurred. Check above.</h2>
    </div>';
}

$conn->close();
echo '</body></html>';
