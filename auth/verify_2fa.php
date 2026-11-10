<?php
// ============================================================
// auth/verify_2fa.php — Two-Factor Authentication (2FA / MFA)
// Verifies 6-digit One-Time PIN (OTP) sent to user email.
// ============================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = get_db();

// If already fully authenticated, redirect to appropriate dashboard
if (!empty($_SESSION['user_id'])) {
    if (($_SESSION['user_role'] ?? '') === 'Admin') {
        header('Location: ' . BASE_URL . 'admin/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . 'pages/dashboard.php');
    }
    exit;
}

// Ensure 2FA verification session is active
if (empty($_SESSION['2fa_pending']) || empty($_SESSION['2fa_user_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$userId   = (int)$_SESSION['2fa_user_id'];
$userEmail = $_SESSION['2fa_email'] ?? '';
$userName  = $_SESSION['2fa_user_name'] ?? 'User';
$userRole  = $_SESSION['2fa_role'] ?? 'Student';
$userStatus = $_SESSION['2fa_status'] ?? 'approved';

$error   = '';
$success = '';

// Handle cancellation / start over
if (isset($_GET['action']) && $_GET['action'] === 'cancel') {
    unset(
        $_SESSION['2fa_pending'],
        $_SESSION['2fa_user_id'],
        $_SESSION['2fa_user_name'],
        $_SESSION['2fa_email'],
        $_SESSION['2fa_role'],
        $_SESSION['2fa_status'],
        $_SESSION['2fa_otp'],
        $_SESSION['2fa_expires'],
        $_SESSION['2fa_attempts']
    );
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = "Security validation failed (CSRF token expired or invalid). Please refresh.";
    } else {
        $action = $_POST['action'] ?? '';

        // ── VERIFY OTP ───────────────────────────────────────
        if ($action === 'verify_otp') {
            $submittedOtp = preg_replace('/[^0-9]/', '', trim($_POST['otp'] ?? ''));

            if (strlen($submittedOtp) !== 6) {
                $error = "Please enter the complete 6-digit verification code.";
            } else {
                $_SESSION['2fa_attempts'] = ($_SESSION['2fa_attempts'] ?? 0) + 1;

                if ($_SESSION['2fa_attempts'] > 5) {
                    $error = "Too many failed 2FA verification attempts. Please sign in again.";
                    unset(
                        $_SESSION['2fa_pending'],
                        $_SESSION['2fa_user_id'],
                        $_SESSION['2fa_user_name'],
                        $_SESSION['2fa_email'],
                        $_SESSION['2fa_role'],
                        $_SESSION['2fa_status'],
                        $_SESSION['2fa_otp'],
                        $_SESSION['2fa_expires'],
                        $_SESSION['2fa_attempts']
                    );
                    header('Location: ' . BASE_URL . 'auth/login.php?error=' . urlencode($error));
                    exit;
                }

                $isValid = false;

                // 1. Check in two_factor_otps table
                try {
                    $stmtChk = $db->prepare("SELECT otp, expires_at FROM two_factor_otps WHERE user_id = ? AND expires_at >= NOW() ORDER BY id DESC LIMIT 1");
                    $stmtChk->bind_param('i', $userId);
                    $stmtChk->execute();
                    $dbRow = $stmtChk->get_result()->fetch_assoc();
                    if ($dbRow && hash_equals($dbRow['otp'], $submittedOtp)) {
                        $isValid = true;
                    }
                } catch (Exception $e) {}

                // 2. Session fallback check
                if (!$isValid && !empty($_SESSION['2fa_otp'])) {
                    if (time() <= ($_SESSION['2fa_expires'] ?? 0) && hash_equals($_SESSION['2fa_otp'], $submittedOtp)) {
                        $isValid = true;
                    }
                }

                if ($isValid) {
                    // Clear 2FA OTP records from database
                    try {
                        $db->query("DELETE FROM two_factor_otps WHERE user_id = " . (int)$userId);
                    } catch (Exception $e) {}

                    // Clear 2FA session variables
                    unset(
                        $_SESSION['2fa_pending'],
                        $_SESSION['2fa_user_id'],
                        $_SESSION['2fa_user_name'],
                        $_SESSION['2fa_email'],
                        $_SESSION['2fa_role'],
                        $_SESSION['2fa_status'],
                        $_SESSION['2fa_otp'],
                        $_SESSION['2fa_expires'],
                        $_SESSION['2fa_attempts']
                    );

                    // Successfully Authenticate User
                    session_regenerate_id(true);
                    $_SESSION['user_id']       = $userId;
                    $_SESSION['user_name']     = $userName;
                    $_SESSION['user_email']    = $userEmail;
                    $_SESSION['user_role']     = $userRole;
                    $_SESSION['user_status']   = $userStatus;
                    $_SESSION['last_activity'] = time();

                    // Load active company
                    try {
                        $stmtCo = $db->prepare("SELECT active_company_id FROM users WHERE id = ?");
                        $stmtCo->bind_param('i', $userId);
                        $stmtCo->execute();
                        $coRow = $stmtCo->get_result()->fetch_assoc();
                        if ($coRow && $coRow['active_company_id']) {
                            $_SESSION['active_company_id'] = $coRow['active_company_id'];
                        }
                    } catch (Exception $e) {}

                    // Log activity
                    try {
                        $stmtLog = $db->prepare("INSERT INTO activity_logs (user_id, action, module, description) VALUES (?, 'Login', '2FA Authentication', 'User verified via Two-Factor Authentication (OTP)')");
                        $stmtLog->execute([$userId]);
                    } catch (Exception $e) {}

                    // Redirect to dashboard
                    if ($userRole === 'Admin') {
                        header('Location: ' . BASE_URL . 'admin/dashboard.php');
                    } else {
                        header('Location: ' . BASE_URL . 'pages/dashboard.php');
                    }
                    exit;
                } else {
                    $error = "Invalid or expired verification code. Please check and try again.";
                }
            }
        }

        // ── RESEND OTP ───────────────────────────────────────
        elseif ($action === 'resend_otp') {
            $lastSent = $_SESSION['2fa_last_sent'] ?? 0;
            if (time() - $lastSent < 30) {
                $wait = 30 - (time() - $lastSent);
                $error = "Please wait {$wait} seconds before requesting a new code.";
            } else {
                $newOtp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $expiresAt = date('Y-m-d H:i:s', time() + 600);

                try {
                    $db->query("DELETE FROM two_factor_otps WHERE user_id = " . (int)$userId);
                    $stmtIns = $db->prepare("INSERT INTO two_factor_otps (user_id, otp, expires_at) VALUES (?, ?, ?)");
                    $stmtIns->bind_param('iss', $userId, $newOtp, $expiresAt);
                    $stmtIns->execute();
                } catch (Exception $e) {}

                $_SESSION['2fa_otp']       = $newOtp;
                $_SESSION['2fa_expires']   = time() + 600;
                $_SESSION['2fa_last_sent'] = time();

                send_2fa_otp_email($userEmail, $userName, $newOtp);

                $success = "A fresh 6-digit verification code has been dispatched to your email.";
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
  <title>Two-Factor Authentication – <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600&family=Fira+Code:wght@500;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --font-heading: 'Plus Jakarta Sans', sans-serif;
      --font-body: 'Inter', sans-serif;
      --font-mono: 'Fira Code', monospace;
      --bg-deep: #060a12;
      --bg-card: rgba(13, 21, 41, 0.88);
      --border-card: rgba(59, 130, 246, 0.28);
      --primary-blue: #2563eb;
      --accent-cyan: #38bdf8;
      --text-white: #ffffff;
      --text-muted: #94a3b8;
      --danger: #ef4444;
      --success: #10b981;
    }

    body {
      background-color: var(--bg-deep);
      color: #f8fafc;
      font-family: var(--font-body);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
      position: relative;
      overflow-x: hidden;
    }

    .hero-bg-layer {
      position: fixed;
      inset: 0;
      background: radial-gradient(circle at 50% 10%, rgba(37, 99, 235, 0.22) 0%, transparent 60%),
                  radial-gradient(circle at 80% 80%, rgba(56, 189, 248, 0.12) 0%, transparent 50%),
                  url('../assets/images/hero-accounting-bg.jpg') center/cover no-repeat;
      opacity: 0.22;
      filter: blur(2px);
      z-index: 0;
      pointer-events: none;
    }

    .card-wrap {
      width: 100%;
      max-width: 480px;
      position: relative;
      z-index: 10;
    }

    .auth-card {
      background: var(--bg-card);
      border: 1px solid var(--border-card);
      border-radius: 20px;
      padding: 2.25rem 2rem;
      box-shadow: 0 25px 60px rgba(0, 0, 0, 0.65), 0 0 40px rgba(37, 99, 235, 0.15);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      text-align: center;
    }

    .shield-badge {
      width: 60px;
      height: 60px;
      margin: 0 auto 1.25rem;
      border-radius: 50%;
      background: rgba(37, 99, 235, 0.16);
      border: 1px solid rgba(59, 130, 246, 0.45);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--accent-cyan);
      box-shadow: 0 0 25px rgba(37, 99, 235, 0.35);
    }

    h1 {
      font-family: var(--font-heading);
      font-size: 1.5rem;
      font-weight: 800;
      color: var(--text-white);
      margin-bottom: 0.5rem;
      letter-spacing: -0.02em;
    }

    .subtext {
      color: var(--text-muted);
      font-size: 0.88rem;
      line-height: 1.5;
      margin-bottom: 1.5rem;
    }

    .subtext strong {
      color: #93c5fd;
      font-weight: 600;
    }

    .auth-alert {
      padding: 0.75rem 1rem;
      border-radius: 10px;
      font-size: 0.85rem;
      margin-bottom: 1.25rem;
      text-align: left;
      display: flex;
      align-items: center;
      gap: 0.6rem;
      line-height: 1.4;
    }

    .auth-alert.error {
      background: rgba(239, 68, 68, 0.15);
      border: 1px solid rgba(239, 68, 68, 0.35);
      color: #fca5a5;
    }

    .auth-alert.success {
      background: rgba(16, 185, 129, 0.15);
      border: 1px solid rgba(16, 185, 129, 0.35);
      color: #6ee7b7;
    }

    .otp-input-wrap {
      margin: 1.25rem 0 1rem;
    }

    .otp-field {
      font-family: var(--font-mono);
      font-size: 2.25rem;
      font-weight: 700;
      letter-spacing: 0.65rem;
      text-align: center;
      padding: 0.85rem 1rem;
      width: 100%;
      border-radius: 14px;
      background: rgba(6, 10, 18, 0.85);
      border: 2px solid #3b82f6;
      color: var(--accent-cyan);
      outline: none;
      box-shadow: 0 0 25px rgba(59, 130, 246, 0.25);
      transition: all 0.2s ease;
    }

    .otp-field:focus {
      border-color: var(--accent-cyan);
      box-shadow: 0 0 30px rgba(56, 189, 248, 0.4);
    }

    .otp-field::placeholder {
      color: rgba(148, 163, 184, 0.25);
      letter-spacing: 0.65rem;
    }

    .submit-btn {
      width: 100%;
      padding: 0.85rem 1.25rem;
      border: none;
      border-radius: 12px;
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      color: #ffffff;
      font-family: var(--font-heading);
      font-weight: 700;
      font-size: 0.95rem;
      cursor: pointer;
      box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      margin-top: 0.75rem;
    }

    .submit-btn:hover {
      background: linear-gradient(135deg, #1d4ed8, #1e40af);
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(37, 99, 235, 0.5);
    }

    .action-links {
      margin-top: 1.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      font-size: 0.82rem;
      color: var(--text-muted);
      flex-wrap: wrap;
    }

    .resend-btn {
      background: transparent;
      border: none;
      color: #60a5fa;
      font-size: 0.82rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: underline;
      padding: 0;
      font-family: inherit;
    }

    .resend-btn:hover {
      color: var(--accent-cyan);
    }

    .cancel-link {
      color: #94a3b8;
      text-decoration: none;
      transition: color 0.15s ease;
    }

    .cancel-link:hover {
      color: #f8fafc;
    }

    .footer-note {
      text-align: center;
      margin-top: 1.5rem;
      font-size: 0.75rem;
      color: #64748b;
    }
  </style>
</head>
<body>

  <div class="hero-bg-layer"></div>

  <div class="card-wrap">
    <div class="auth-card">
      <div class="shield-badge">
        <i data-lucide="shield-check" style="width: 30px; height: 30px;"></i>
      </div>

      <h1>Two-Factor Verification</h1>
      <p class="subtext">
        Enter the 6-digit security code sent to<br>
        <strong><?= htmlspecialchars($userEmail) ?></strong>
      </p>

      <?php if ($error): ?>
        <div class="auth-alert error">
          <i data-lucide="alert-circle" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
          <div><?= htmlspecialchars($error) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="auth-alert success">
          <i data-lucide="check-circle-2" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
          <div><?= htmlspecialchars($success) ?></div>
        </div>
      <?php endif; ?>

      <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="verify_otp">

        <div class="otp-input-wrap">
          <input type="text" name="otp" id="otp-input" class="otp-field"
                 maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                 placeholder="······" autocomplete="one-time-code" required autofocus>
        </div>

        <div style="font-size: 0.78rem; color: #94a3b8; margin-bottom: 1rem;">
          Code expires in 10 minutes.
        </div>

        <button type="submit" class="submit-btn">
          <i data-lucide="log-in" style="width: 16px; height: 16px;"></i>
          Verify &amp; Continue
        </button>
      </form>

      <div class="action-links">
        <form method="POST" style="display: inline;">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="resend_otp">
          <span>Didn't receive code?</span>
          <button type="submit" class="resend-btn">Resend OTP</button>
        </form>

        <a href="verify_2fa.php?action=cancel" class="cancel-link">
          &larr; Return to Sign In
        </a>
      </div>
    </div>

    <div class="footer-note">
      TALA-AIS &bull; Two-Factor Authentication &bull; Laguna State Polytechnic University
    </div>
  </div>

  <script>
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

    const otpInput = document.getElementById('otp-input');
    if (otpInput) {
      otpInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
      });
    }
  </script>
</body>
</html>

