<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once '../config.php';
require_once '../db.php';
require_once '../includes/mailer.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// If already logged in, redirect to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit;
}

$db = get_db();

$error   = '';
$success = '';

// Determine active step (1: Email, 2: OTP, 3: New Password)
$step = (int)($_SESSION['reset_step'] ?? 1);
if ($step < 1 || $step > 3) $step = 1;

// If user is at step 2 or 3 but doesn't have an email in session, reset to step 1
if (($step === 2 || $step === 3) && empty($_SESSION['reset_email'])) {
    $step = 1;
    $_SESSION['reset_step'] = 1;
}

// Reset request completely if user clicks "start over" or "change email"
if (isset($_GET['action']) && $_GET['action'] === 'restart') {
    unset($_SESSION['reset_email'], $_SESSION['reset_step'], $_SESSION['otp_verified'], $_SESSION['otp_sent_time']);
    header('Location: forgot_password.php');
    exit;
}

// ── Handle POST Form Submissions ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── STEP 1: Request OTP ──────────────────────────────────
    if ($action === 'request_otp') {
        $email = trim($_POST['email'] ?? '');

        if (!$email) {
            $error = 'Please enter your registered email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Check if email exists in users table
            $stmt = $db->prepare("SELECT id, name, email FROM users WHERE email = ?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if (!$user) {
                $error = 'No account found with this email address. Please check and try again.';
            } else {
                // Generate 6-digit numeric OTP
                $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

                // Clear previous OTPs for this email
                $del = $db->prepare("DELETE FROM password_resets WHERE email = ?");
                $del->bind_param('s', $email);
                $del->execute();

                // Insert new OTP with 15-minute expiration
                $ins = $db->prepare("INSERT INTO password_resets (email, otp, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
                $ins->bind_param('ss', $email, $otp);
                $ins->execute();

                // Send email via SMTP
                $mailResult = send_otp_email($email, $user['name'], $otp);

                if ($mailResult['success']) {
                    $_SESSION['reset_email']     = $email;
                    $_SESSION['reset_name']      = $user['name'];
                    $_SESSION['reset_step']      = 2;
                    $_SESSION['otp_sent_time']   = time();
                    $step = 2;
                    $success = "A 6-digit OTP has been sent to <strong>" . htmlspecialchars($email) . "</strong>. Please check your inbox (and spam folder).";
                } else {
                    $error = "Failed to send OTP email: " . htmlspecialchars($mailResult['error']);
                }
            }
        }
    }

    // ── STEP 2: Resend OTP ────────────────────────────────────
    elseif ($action === 'resend_otp') {
        $email = $_SESSION['reset_email'] ?? '';

        if (!$email) {
            $step = 1;
            $_SESSION['reset_step'] = 1;
            $error = 'Session expired. Please enter your email again.';
        } else {
            $stmt = $db->prepare("SELECT name FROM users WHERE email = ?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $name = $user['name'] ?? 'User';

            // Generate new 6-digit OTP
            $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

            // Invalidate old OTPs and insert new one
            $del = $db->prepare("DELETE FROM password_resets WHERE email = ?");
            $del->bind_param('s', $email);
            $del->execute();

            $ins = $db->prepare("INSERT INTO password_resets (email, otp, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
            $ins->bind_param('ss', $email, $otp);
            $ins->execute();

            $mailResult = send_otp_email($email, $name, $otp);

            if ($mailResult['success']) {
                $_SESSION['otp_sent_time'] = time();
                $success = "A new 6-digit OTP has been sent to your email!";
            } else {
                $error = "Failed to resend OTP: " . htmlspecialchars($mailResult['error']);
            }
            $step = 2;
        }
    }

    // ── STEP 2: Verify OTP ────────────────────────────────────
    elseif ($action === 'verify_otp') {
        $email = $_SESSION['reset_email'] ?? '';
        $otp   = trim($_POST['otp'] ?? '');

        if (!$email) {
            $step = 1;
            $_SESSION['reset_step'] = 1;
            $error = 'Session expired. Please start over.';
        } elseif (empty($otp)) {
            $error = 'Please enter the 6-digit OTP code.';
            $step = 2;
        } else {
            // Check matching and unexpired OTP
            $stmt = $db->prepare("SELECT id FROM password_resets WHERE email = ? AND otp = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
            $stmt->bind_param('ss', $email, $otp);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            if ($row) {
                $_SESSION['otp_verified'] = true;
                $_SESSION['reset_step']   = 3;
                $step = 3;
                $success = 'OTP verified successfully! Please enter your new password.';
            } else {
                $error = 'Invalid or expired OTP code. Please try again or click "Resend Code".';
                $step = 2;
            }
        }
    }

    // ── STEP 3: Reset Password ────────────────────────────────
    elseif ($action === 'reset_password') {
        $email      = $_SESSION['reset_email'] ?? '';
        $isVerified = !empty($_SESSION['otp_verified']);

        if (!$email || !$isVerified) {
            $step = 1;
            $_SESSION['reset_step'] = 1;
            $error = 'Unauthorized password reset attempt. Please start over.';
        } else {
            $new_pass     = $_POST['password'] ?? '';
            $confirm_pass = $_POST['confirm_password'] ?? '';

            if (!$new_pass || !$confirm_pass) {
                $error = 'Please fill in both password fields.';
                $step = 3;
            } elseif ($new_pass !== $confirm_pass) {
                $error = 'Passwords do not match. Please verify.';
                $step = 3;
            } elseif (strlen($new_pass) < 6) {
                $error = 'Password must be at least 6 characters in length.';
                $step = 3;
            } else {
                // Fetch user id for activity log
                $stmtU = $db->prepare("SELECT id FROM users WHERE email = ?");
                $stmtU->bind_param('s', $email);
                $stmtU->execute();
                $user = $stmtU->get_result()->fetch_assoc();

                if ($user) {
                    $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
                    $upd = $db->prepare("UPDATE users SET password = ? WHERE email = ?");
                    $upd->bind_param('ss', $hashed, $email);
                    $upd->execute();

                    // Delete OTP from password_resets
                    $del = $db->prepare("DELETE FROM password_resets WHERE email = ?");
                    $del->bind_param('s', $email);
                    $del->execute();

                    // Log activity
                    $stmtLog = $db->prepare("INSERT INTO activity_logs (user_id, action, module, description) VALUES (?, 'Password Reset', 'Authentication', 'User successfully reset password via OTP')");
                    $stmtLog->execute([$user['id']]);

                    // Clean up session reset variables
                    unset($_SESSION['reset_email'], $_SESSION['reset_name'], $_SESSION['reset_step'], $_SESSION['otp_verified'], $_SESSION['otp_sent_time']);

                    // Redirect to login with success flag
                    header('Location: ' . BASE_URL . 'auth/login.php?msg=pw_reset_success');
                    exit;
                } else {
                    $error = 'User account not found.';
                    $step = 1;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password · TALA-AIS</title>
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/images/logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
/* ─────────────────────────────────────────
   RESET & VARIABLES
───────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --font-heading: 'Plus Jakarta Sans', sans-serif;
  --font-body: 'Inter', sans-serif;
  --bg-deep: #060a12;
  --bg-card: rgba(13, 21, 41, 0.85);
  --border-card: rgba(59, 130, 246, 0.28);
  --border-input: rgba(59, 130, 246, 0.32);
  --primary-blue: #0070f3;
  --accent-cyan: #00d2ff;
  --accent-purple: #7928ca;
  --text-white: #ffffff;
  --text-light-blue: #93c5fd;
  --text-muted: #94a3b8;
  --danger: #ef4444;
  --success: #10b981;
}

body {
  background-color: #060a12;
  color: #f8fafc;
  font-family: var(--font-body);
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow-x: hidden;
  padding: 1.5rem;
}

/* Hero Background Layer matching login page */
.hero-bg-layer {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-image: 
    linear-gradient(180deg, rgba(6, 10, 18, 0.55) 0%, rgba(7, 11, 20, 0.45) 45%, rgba(11, 19, 41, 0.75) 85%, #060a12 100%),
    radial-gradient(circle at 50% 40%, rgba(0, 112, 243, 0.3) 0%, rgba(6, 10, 18, 0.55) 75%),
    url('<?= BASE_URL ?>assets/images/hero-accounting-bg.jpg');
  background-size: cover;
  background-position: center;
  background-repeat: no-repeat;
  pointer-events: none;
  z-index: 1;
  opacity: 0.72;
}

/* Subtle Grid Matrix Pattern */
.bg-grid-overlay {
  position: fixed;
  inset: 0;
  background-image: 
    linear-gradient(to right, rgba(59, 130, 246, 0.08) 1px, transparent 1px),
    linear-gradient(to bottom, rgba(59, 130, 246, 0.08) 1px, transparent 1px);
  background-size: 38px 38px;
  mask-image: radial-gradient(circle at center, black 40%, transparent 85%);
  -webkit-mask-image: radial-gradient(circle at center, black 40%, transparent 85%);
  pointer-events: none;
  z-index: 1;
}

/* Background Glowing Elements */
.ambient-glow-top {
  position: fixed;
  top: -140px;
  left: 50%;
  transform: translateX(-50%);
  width: 950px;
  height: 520px;
  background: radial-gradient(ellipse at center, rgba(37, 99, 235, 0.5) 0%, rgba(0, 210, 255, 0.18) 40%, rgba(6, 10, 18, 0) 70%);
  pointer-events: none;
  z-index: 1;
  animation: pulseGlow 7s ease-in-out infinite alternate;
}

.ambient-glow-bottom {
  position: fixed;
  bottom: -60px;
  left: 0;
  right: 0;
  height: 450px;
  background: radial-gradient(ellipse at 50% 100%, rgba(37, 99, 235, 0.4) 0%, rgba(121, 40, 202, 0.18) 45%, transparent 70%);
  pointer-events: none;
  z-index: 1;
}

@keyframes pulseGlow {
  0% { opacity: 0.8; transform: translateX(-50%) scale(1); }
  100% { opacity: 1; transform: translateX(-50%) scale(1.05); }
}

/* Container */
.auth-wrap {
  position: relative;
  z-index: 2;
  width: 100%;
  max-width: 480px;
}

/* Brand Header */
.auth-brand {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.85rem;
  margin-bottom: 2rem;
  text-decoration: none;
  transition: transform 0.2s ease;
}

.auth-brand:hover {
  transform: translateY(-2px);
}

.brand-badge-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 50%, #1d4ed8 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #ffffff;
  box-shadow: 0 0 25px rgba(0, 112, 243, 0.55);
  animation: starFloat 4s ease-in-out infinite;
}

@keyframes starFloat {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
}

.auth-brand-text-wrap {
  display: flex;
  flex-direction: column;
}

.auth-brand-name {
  font-family: var(--font-heading);
  font-size: 1.55rem;
  font-weight: 800;
  letter-spacing: -0.03em;
  line-height: 1.1;
  background: linear-gradient(135deg, #ffffff 40%, #93c5fd 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

.auth-brand-name .brand-sub {
  color: #38bdf8;
  -webkit-text-fill-color: #38bdf8;
}

.brand-tagline {
  font-size: 0.72rem;
  color: #60a5fa;
  font-weight: 600;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

/* Auth Card */
.auth-card {
  background: rgba(13, 22, 45, 0.82);
  padding: 2.25rem 2.25rem 2rem;
  border-radius: 24px;
  box-shadow: 0 30px 70px -15px rgba(0, 0, 0, 0.75), 0 0 40px rgba(37, 99, 235, 0.18);
  border: 1px solid rgba(59, 130, 246, 0.28);
  border-top: 1px solid rgba(147, 197, 253, 0.4);
  backdrop-filter: blur(24px) saturate(160%);
  -webkit-backdrop-filter: blur(24px) saturate(160%);
  position: relative;
  overflow: hidden;
}

.auth-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 15%;
  right: 15%;
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(96, 165, 250, 0.8), transparent);
}

/* Steps Indicator */
.step-indicator {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 2.25rem;
  position: relative;
}

.step-indicator-track {
  position: absolute;
  top: 16px;
  left: 32px;
  right: 32px;
  height: 2px;
  background: rgba(255, 255, 255, 0.1);
  z-index: 0;
}

.step-indicator-fill {
  position: absolute;
  top: 16px;
  left: 32px;
  height: 2px;
  background: linear-gradient(90deg, #10b981, #0070f3);
  z-index: 0;
  transition: width 0.4s ease;
}

.step-item {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
}

.step-circle {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: #090e1a;
  border: 2px solid #23334d;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.85rem;
  font-weight: 700;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.step-label {
  font-size: 0.72rem;
  color: #64748b;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  transition: color 0.3s ease;
}

.step-item.active .step-circle {
  background: #0070f3;
  border-color: #60a5fa;
  color: #ffffff;
  box-shadow: 0 0 20px rgba(0, 112, 243, 0.75);
  transform: scale(1.08);
}

.step-item.active .step-label {
  color: #93c5fd;
  font-weight: 700;
}

.step-item.completed .step-circle {
  background: #10b981;
  border-color: #34d399;
  color: #ffffff;
  box-shadow: 0 0 15px rgba(16, 185, 129, 0.5);
}

.step-item.completed .step-label {
  color: #34d399;
}

/* Card Header */
.card-header-text {
  text-align: center;
  margin-bottom: 1.5rem;
}

.card-header-text h2 {
  font-family: var(--font-heading);
  font-size: 1.6rem;
  font-weight: 800;
  color: #ffffff;
  letter-spacing: -0.025em;
  margin-bottom: 0.4rem;
}

.card-header-text p {
  font-size: 0.88rem;
  color: var(--text-muted);
  line-height: 1.5;
}

/* Alerts */
.auth-alert {
  padding: 0.85rem 1rem;
  border-radius: 12px;
  font-size: 0.88rem;
  margin-bottom: 1.25rem;
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  line-height: 1.45;
}

.auth-alert.error {
  background: rgba(239, 68, 68, 0.15);
  border: 1px solid rgba(239, 68, 68, 0.4);
  color: #fca5a5;
}

.auth-alert.success {
  background: rgba(16, 185, 129, 0.15);
  border: 1px solid rgba(16, 185, 129, 0.4);
  color: #6ee7b7;
}

.auth-alert svg { flex-shrink: 0; margin-top: 2px; }

/* Form Fields */
.auth-field {
  margin-bottom: 1.25rem;
}

.auth-field label {
  display: block;
  font-size: 0.83rem;
  font-weight: 600;
  color: #cbd5e1;
  margin-bottom: 0.45rem;
}

.input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}

.input-icon {
  position: absolute;
  left: 1rem;
  color: #64748b;
  display: flex;
  align-items: center;
  pointer-events: none;
  z-index: 1;
}

.auth-input {
  width: 100%;
  padding: 0.85rem 1rem 0.85rem 2.85rem;
  background: rgba(15, 23, 42, 0.7);
  border: 1.5px solid var(--border-input);
  border-radius: 14px;
  color: #ffffff;
  font-size: 0.95rem;
  font-family: inherit;
  transition: all 0.25s ease;
  outline: none;
}

.auth-input:focus {
  border-color: #3b82f6;
  background: rgba(15, 23, 42, 0.95);
  box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.2);
}

.auth-input::placeholder {
  color: #475569;
}

/* OTP Monospace Input */
.otp-input-wrap {
  display: flex;
  justify-content: center;
  margin: 1.5rem 0 1rem;
}

.otp-field {
  font-family: 'Courier New', Courier, monospace;
  font-size: 2.25rem;
  font-weight: 800;
  letter-spacing: 0.65rem;
  text-align: center;
  padding: 0.85rem 1rem;
  width: 100%;
  border-radius: 16px;
  background: rgba(6, 10, 18, 0.85);
  border: 2px solid #3b82f6;
  color: #38bdf8;
  outline: none;
  box-shadow: 0 0 20px rgba(59, 130, 246, 0.25);
  transition: all 0.25s ease;
}

.otp-field:focus {
  border-color: #60a5fa;
  box-shadow: 0 0 25px rgba(59, 130, 246, 0.45);
  background: rgba(6, 10, 18, 0.98);
}

/* Password Toggle */
.pw-toggle {
  position: absolute;
  right: 0.9rem;
  background: none;
  border: none;
  color: #64748b;
  cursor: pointer;
  display: flex;
  align-items: center;
  padding: 0.25rem;
  transition: color 0.2s ease;
}

.pw-toggle:hover { color: #93c5fd; }

/* Password Strength Meter */
.strength-bar {
  height: 4px;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 9999px;
  overflow: hidden;
  margin-top: 0.5rem;
}

.strength-fill {
  height: 100%;
  width: 0%;
  transition: all 0.3s ease;
  border-radius: 9999px;
}

.strength-info {
  display: flex;
  justify-content: space-between;
  font-size: 0.75rem;
  color: var(--text-muted);
  margin-top: 0.35rem;
}

/* Submit Button */
.auth-submit {
  width: 100%;
  padding: 0.95rem;
  border: none;
  border-radius: 14px;
  background: linear-gradient(135deg, #0070f3 0%, #0051cc 100%);
  color: #ffffff;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 8px 25px rgba(0, 112, 243, 0.4);
  transition: all 0.25s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  margin-top: 1.5rem;
}

.auth-submit:hover {
  background: linear-gradient(135deg, #1a82f7 0%, #0060e6 100%);
  transform: translateY(-1px);
  box-shadow: 0 12px 30px rgba(0, 112, 243, 0.55);
}

.auth-submit:active {
  transform: translateY(0);
}

/* Secondary Action Links */
.auth-links {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 1.5rem;
  font-size: 0.85rem;
}

.auth-link {
  color: #60a5fa;
  text-decoration: none;
  font-weight: 600;
  transition: color 0.2s ease;
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.auth-link:hover {
  color: #93c5fd;
  text-decoration: underline;
}

.resend-btn {
  background: none;
  border: none;
  color: #60a5fa;
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  padding: 0;
  font-family: inherit;
  transition: color 0.2s ease;
}

.resend-btn:hover {
  color: #93c5fd;
  text-decoration: underline;
}
  </style>
</head>
<body>

<div class="hero-bg-layer"></div>
<div class="bg-grid-overlay"></div>
<div class="ambient-glow-top"></div>
<div class="ambient-glow-bottom"></div>

<div class="auth-wrap">
  <!-- Brand Logo -->
  <a href="<?= BASE_URL ?>auth/login.php" class="auth-brand">
    <div class="brand-badge-icon">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
        <path d="M12 2L14.4 8.6L21.5 9.2L16 13.8L17.7 20.8L12 17.2L6.3 20.8L8 13.8L2.5 9.2L9.6 8.6L12 2Z"/>
      </svg>
    </div>
    <div class="auth-brand-text-wrap">
      <div class="auth-brand-name">TALA<span class="brand-dash">-</span><span class="brand-sub">AIS</span></div>
      <div class="brand-tagline">Secure Authentication</div>
    </div>
  </a>

  <!-- Card -->
  <div class="auth-card">
    
    <!-- Step Progress Indicator -->
    <div class="step-indicator">
      <div class="step-indicator-track"></div>
      <div class="step-indicator-fill" style="width: <?= $step === 1 ? '0%' : ($step === 2 ? '50%' : '100%') ?>;"></div>

      <div class="step-item <?= $step === 1 ? 'active' : ($step > 1 ? 'completed' : '') ?>">
        <div class="step-circle"><?= $step > 1 ? '✓' : '1' ?></div>
        <span class="step-label">Email</span>
      </div>
      <div class="step-item <?= $step === 2 ? 'active' : ($step > 2 ? 'completed' : '') ?>">
        <div class="step-circle"><?= $step > 2 ? '✓' : '2' ?></div>
        <span class="step-label">Verify OTP</span>
      </div>
      <div class="step-item <?= $step === 3 ? 'active' : '' ?>">
        <div class="step-circle">3</div>
        <span class="step-label">Password</span>
      </div>
    </div>

    <!-- Alerts -->
    <?php if ($error): ?>
    <div class="auth-alert error">
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <div><?= $error ?></div>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="auth-alert success">
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <div><?= $success ?></div>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════
         STEP 1: Enter Email
         ═══════════════════════════════════════════════ -->
    <?php if ($step === 1): ?>
    <div class="card-header-text">
      <h2>Forgot Password?</h2>
      <p>Enter your registered account email and we'll send a 6-digit OTP code to verify your identity.</p>
    </div>

    <form method="POST">
      <input type="hidden" name="action" value="request_otp">

      <div class="auth-field">
        <label for="reset-email">Registered Email Address</label>
        <div class="input-wrap">
          <span class="input-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          </span>
          <input type="email" id="reset-email" name="email" class="auth-input"
            placeholder="example@ais.com"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
        </div>
      </div>

      <button type="submit" class="auth-submit">
        Send 6-Digit OTP →
      </button>

      <div class="auth-links" style="justify-content: center; margin-top: 1.75rem;">
        <a href="<?= BASE_URL ?>auth/login.php" class="auth-link">
          ← Back to Sign In
        </a>
      </div>
    </form>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════
         STEP 2: Verify OTP Code
         ═══════════════════════════════════════════════ -->
    <?php if ($step === 2): ?>
    <div class="card-header-text">
      <h2>Verify OTP Code</h2>
      <p>Enter the 6-digit code sent to <strong style="color: #93c5fd;"><?= htmlspecialchars($_SESSION['reset_email'] ?? '') ?></strong>.</p>
    </div>

    <form method="POST">
      <input type="hidden" name="action" value="verify_otp">

      <div class="otp-input-wrap">
        <input type="text" name="otp" id="otp-input" class="otp-field"
          maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
          placeholder="••••••" autocomplete="one-time-code" required autofocus>
      </div>

      <div style="font-size: 0.8rem; color: #94a3b8; text-align: center; margin-bottom: 1.25rem;">
        Code is valid for 15 minutes.
      </div>

      <button type="submit" class="auth-submit">
        Verify Code & Continue →
      </button>
    </form>

    <div class="auth-links">
      <form method="POST" style="display: inline;">
        <input type="hidden" name="action" value="resend_otp">
        <span style="color: #94a3b8; font-size: 0.85rem;">Didn't receive code?</span>
        <button type="submit" class="resend-btn">Resend OTP</button>
      </form>

      <a href="forgot_password.php?action=restart" class="auth-link" style="font-size: 0.82rem;">
        Change Email
      </a>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════
         STEP 3: Set New Password
         ═══════════════════════════════════════════════ -->
    <?php if ($step === 3): ?>
    <div class="card-header-text">
      <h2>Create New Password</h2>
      <p>Your identity has been verified. Enter a secure new password for your account.</p>
    </div>

    <form method="POST">
      <input type="hidden" name="action" value="reset_password">

      <!-- New Password -->
      <div class="auth-field">
        <label for="new-pw">New Password</label>
        <div class="input-wrap">
          <span class="input-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          </span>
          <input type="password" id="new-pw" name="password" class="auth-input"
            placeholder="At least 6 characters" required autofocus oninput="checkStrength(this.value)">
          <button type="button" class="pw-toggle" onclick="togglePasswordVisibility('new-pw', this)" aria-label="Toggle password">
            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
        <div class="strength-bar">
          <div class="strength-fill" id="strength-fill"></div>
        </div>
        <div class="strength-info">
          <span>Password strength</span>
          <span id="strength-text" style="font-weight: 600;">—</span>
        </div>
      </div>

      <!-- Confirm Password -->
      <div class="auth-field">
        <label for="confirm-pw">Confirm New Password</label>
        <div class="input-wrap">
          <span class="input-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          </span>
          <input type="password" id="confirm-pw" name="confirm_password" class="auth-input"
            placeholder="Re-enter new password" required>
          <button type="button" class="pw-toggle" onclick="togglePasswordVisibility('confirm-pw', this)" aria-label="Toggle password">
            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>

      <button type="submit" class="auth-submit">
        Reset Password & Sign In →
      </button>

      <div class="auth-links" style="justify-content: center; margin-top: 1.75rem;">
        <a href="forgot_password.php?action=restart" class="auth-link">
          Cancel & Return
        </a>
      </div>
    </form>
    <?php endif; ?>

  </div>
</div>

<script>
function togglePasswordVisibility(fieldId, btn) {
  const el = document.getElementById(fieldId);
  if (!el) return;
  const isText = el.type === 'password';
  el.type = isText ? 'text' : 'password';
  btn.style.color = isText ? '#3b82f6' : '#64748b';
}

function checkStrength(pw) {
  let score = 0;
  if (pw.length >= 6)           score++;
  if (pw.length >= 10)          score++;
  if (/[A-Z]/.test(pw))        score++;
  if (/[0-9]/.test(pw))        score++;
  if (/[^a-zA-Z0-9]/.test(pw)) score++;

  const fill = document.getElementById('strength-fill');
  const text = document.getElementById('strength-text');
  if (!fill || !text) return;

  fill.style.width = Math.round((score / 5) * 100) + '%';

  if (score <= 1) {
    fill.style.background = '#ef4444';
    text.textContent = 'Weak';
    text.style.color = '#ef4444';
  } else if (score <= 3) {
    fill.style.background = '#f59e0b';
    text.textContent = 'Fair';
    text.style.color = '#f59e0b';
  } else {
    fill.style.background = '#10b981';
    text.textContent = 'Strong';
    text.style.color = '#10b981';
  }
}

// Auto-clean non-digits on OTP input
const otpInput = document.getElementById('otp-input');
if (otpInput) {
  otpInput.addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
  });
}
</script>
</body>
</html>
