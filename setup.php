<?php
// ============================================================
// AIS PHP — setup.php
// Run this ONCE to create the database and all tables.
// Visit: http://localhost/AIS_PHP/setup.php
// ============================================================

$host   = 'localhost';
$user   = 'root';
$pass   = '';  // Default XAMPP password is blank

$conn = new mysqli($host, $user, $pass);

if ($conn->connect_error) {
    die("<div style='font-family:sans-serif;color:red;padding:2rem;'>
        <h2>❌ Connection Failed</h2>
        <p>Make sure XAMPP Apache and MySQL are both running.</p>
        <p>Error: " . $conn->connect_error . "</p>
    </div>");
}

$steps = [];

// 1. Create Database
$sql = "CREATE DATABASE IF NOT EXISTS `ais_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if ($conn->query($sql)) {
    $steps[] = ['ok', 'Database `ais_db` created or already exists.'];
} else {
    $steps[] = ['err', 'Failed to create database: ' . $conn->error];
}

$conn->select_db('ais_db');

// 2. Users Table
$conn->query("CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('Admin','Instructor','Student') NOT NULL DEFAULT 'Student',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$steps[] = ['ok', 'Table `users` ready.'];

// 3. Companies Table
$conn->query("CREATE TABLE IF NOT EXISTS `companies` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `address` TEXT,
    `business_type` ENUM('Service','Merchandising','Manufacturing') NOT NULL DEFAULT 'Service',
    `fiscal_year_end` VARCHAR(20) DEFAULT 'December 31',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB");
$steps[] = ['ok', 'Table `companies` ready.'];

// 4. Active Company Session per User
$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `active_company_id` INT NULL DEFAULT NULL");
$steps[] = ['ok', 'Column `active_company_id` in users ready.'];

// 5. Accounts Table
$conn->query("CREATE TABLE IF NOT EXISTS `accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `company_id` INT NOT NULL,
    `code` VARCHAR(20) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `category` ENUM('Assets','Liabilities','Equity','Revenue','Expenses') NOT NULL,
    `sub_category` VARCHAR(100),
    `description` TEXT,
    `opening_balance` DECIMAL(15,2) DEFAULT 0.00,
    FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB");
$steps[] = ['ok', 'Table `accounts` ready.'];

// 6. Journal Entries Table
$conn->query("CREATE TABLE IF NOT EXISTS `journal_entries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `company_id` INT NOT NULL,
    `reference_no` VARCHAR(50),
    `date` DATE NOT NULL,
    `description` VARCHAR(255),
    `particulars` TEXT,
    `type` ENUM('Operating','Investing','Financing','Non-Cash') DEFAULT 'Operating',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB");
$steps[] = ['ok', 'Table `journal_entries` ready.'];

// 7. Journal Entry Lines Table
$conn->query("CREATE TABLE IF NOT EXISTS `journal_entry_lines` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `journal_entry_id` INT NOT NULL,
    `account_id` INT NOT NULL,
    `debit` DECIMAL(15,2) DEFAULT 0.00,
    `credit` DECIMAL(15,2) DEFAULT 0.00,
    FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB");
$steps[] = ['ok', 'Table `journal_entry_lines` ready.'];

// 8. Notes to Financial Statements
$conn->query("CREATE TABLE IF NOT EXISTS `notes_to_fs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `company_id` INT NOT NULL,
    `note_number` VARCHAR(10),
    `title` VARCHAR(150),
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB");
$steps[] = ['ok', 'Table `notes_to_fs` ready.'];

// 9. Activity Logs Table
$conn->query("CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT,
    `action` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB");
$steps[] = ['ok', 'Table `activity_logs` ready.'];

// 10. Seed default Admin user if no users exist
$result = $conn->query("SELECT COUNT(*) as cnt FROM `users`");
$row = $result->fetch_assoc();
if ($row['cnt'] == 0) {
    $adminPass = password_hash('admin123', PASSWORD_BCRYPT);
    $conn->query("INSERT INTO `users` (name, email, password, role) VALUES ('Administrator', 'admin@ais.com', '$adminPass', 'Admin')");
    $steps[] = ['ok', 'Default Admin user created: <strong>admin@ais.com</strong> / password: <strong>admin123</strong>'];
} else {
    $steps[] = ['info', 'Users already exist — skipped default admin creation.'];
}

$conn->close();

// Count errors
$errors = array_filter($steps, fn($s) => $s[0] === 'err');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AIS Setup</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', sans-serif; background: #f0f4ff; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .card { background: white; border-radius: 16px; padding: 2.5rem; max-width: 600px; width: 90%; box-shadow: 0 10px 40px rgba(79,70,229,.12); }
  h1 { color: #4f46e5; font-size: 1.6rem; margin-bottom: 0.5rem; }
  p.sub { color: #6b7280; font-size: 0.9rem; margin-bottom: 2rem; }
  .step { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0; border-bottom: 1px solid #f3f4f6; font-size: 0.875rem; }
  .step:last-child { border-bottom: none; }
  .icon { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: bold; flex-shrink: 0; }
  .ok { background: #d1fae5; color: #065f46; }
  .err { background: #fee2e2; color: #991b1b; }
  .info { background: #dbeafe; color: #1e40af; }
  .success-banner { background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 10px; padding: 1rem 1.25rem; margin-top: 2rem; text-align: center; }
  .error-banner { background: #fee2e2; border: 1px solid #fca5a5; border-radius: 10px; padding: 1rem 1.25rem; margin-top: 2rem; text-align: center; }
  .btn { display: inline-block; margin-top: 1.25rem; padding: 0.75rem 2rem; background: #4f46e5; color: white; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.9rem; }
  .btn:hover { background: #4338ca; }
</style>
</head>
<body>
<div class="card">
  <h1>🚀 AIS Database Setup</h1>
  <p class="sub">AccounTech Accounting Information System — One-time database installer</p>

  <?php foreach ($steps as $step): ?>
  <div class="step">
    <div class="icon <?= $step[0] ?>">
      <?= $step[0] === 'ok' ? '✓' : ($step[0] === 'err' ? '✗' : 'i') ?>
    </div>
    <span><?= $step[1] ?></span>
  </div>
  <?php endforeach; ?>

  <?php if (count($errors) === 0): ?>
  <div class="success-banner">
    <strong>✅ Setup Complete!</strong><br>
    <small>Database <code>ais_db</code> and all tables are ready.</small>
    <br>
    <a class="btn" href="index.php">Go to Login Page →</a>
  </div>
  <?php else: ?>
  <div class="error-banner">
    <strong>❌ Some steps failed.</strong><br>
    <small>Check that MySQL is running in XAMPP and try again.</small>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
