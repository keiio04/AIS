<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once '../config.php';
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit;
}

$error   = '';
$success = '';
$mode    = $_GET['mode'] ?? 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'login') {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        if (!$email || !$pass) {
            $error = 'Please fill in all fields.';
        } else {
            $db   = get_db();
            $stmt = $db->prepare("SELECT id, name, email, role, password FROM users WHERE email = ?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user   = $result->fetch_assoc();
            if ($user && password_verify($pass, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email']= $user['email'];
                $_SESSION['user_role'] = $user['role'];

                $stmtCo = $db->prepare("SELECT active_company_id FROM users WHERE id = ?");
                $stmtCo->bind_param('i', $user['id']);
                $stmtCo->execute();
                $coRow = $stmtCo->get_result()->fetch_assoc();
                if ($coRow && $coRow['active_company_id']) {
                    $_SESSION['active_company_id'] = $coRow['active_company_id'];
                }

                $stmtLog = $db->prepare("INSERT INTO activity_logs (user_id, action, module, description) VALUES (?, 'Login', 'Authentication', 'User successfully logged in')");
                $stmtLog->execute([$user['id']]);

                if ($user['role'] === 'Admin') {
                    header('Location: ' . BASE_URL . 'admin/dashboard.php');
                } else {
                    header('Location: ' . BASE_URL . 'pages/dashboard.php');
                }
                exit;
            } else {
                $error = 'Invalid email or password.';
            }
        }
    } elseif ($_POST['action'] === 'register') {
        $name    = trim($_POST['name']    ?? '');
        $email   = trim($_POST['email']   ?? '');
        $pass    = $_POST['password']     ?? '';
        $confirm = $_POST['confirm_pass'] ?? '';
        $role    = $_POST['role']         ?? 'Student';
        if (!$name || !$email || !$pass || !$confirm) {
            $error = 'Please fill in all fields.'; $mode = 'register';
        } elseif ($pass !== $confirm) {
            $error = 'Passwords do not match.'; $mode = 'register';
        } elseif (strlen($pass) < 6) {
            $error = 'Password must be at least 6 characters.'; $mode = 'register';
        } else {
            $db   = get_db();
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $error = 'Email is already registered.'; $mode = 'register';
            } else {
                $hashed   = password_hash($pass, PASSWORD_BCRYPT);
                $safeRole = in_array($role, ['Student','Instructor']) ? $role : 'Student';
                $ins = $db->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
                $ins->bind_param('ssss', $name, $email, $hashed, $safeRole);
                $ins->execute();
                $success = 'Account created successfully! You can now log in.';
                $mode    = 'login';
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
<title>AccounTech AIS – Sign In</title>
<meta name="description" content="Sign in to AccounTech AIS Platform – Laguna State Polytechnic University Accounting Information System.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
  height: 100%;
  font-family: 'Inter', sans-serif;
}

/* ─────────────────────────────────────────
   FULL PAGE: single seamless gradient
   Left = soft blue, Right = near white
───────────────────────────────────────── */
body {
  min-height: 100vh;
  /* Matches the screenshot: periwinkle-blue left → white right */
  background: linear-gradient(to right,
    #c2d8f5 0%,
    #cde0f7 20%,
    #daeaf9 38%,
    #eaf4fc 55%,
    #f4f9fe 70%,
    #fafcff 84%,
    #ffffff 100%
  );
  display: flex;
  position: relative;
  overflow: hidden;
}

/* ─────────────────────────────────────────
   WAVE LINES — fixed to bottom-left
   (matches screenshot exactly)
───────────────────────────────────────── */
.bg-waves {
  position: fixed;
  bottom: -20px;
  left: -20px;
  width: 52vw;
  min-width: 360px;
  pointer-events: none;
  z-index: 0;
}

/* ─────────────────────────────────────────
   PAGE LAYOUT
───────────────────────────────────────── */
.page-wrap {
  position: relative;
  z-index: 1;
  display: flex;
  width: 100%;
  min-height: 100vh;
}

/* ─────────────────────────────────────────
   LEFT — brand area, fully transparent
───────────────────────────────────────── */
.auth-left {
  flex: 0 0 45%;
  /* grid: logo top | content centered | badge bottom */
  display: grid;
  grid-template-rows: auto 1fr auto;
  padding: 3.5rem 2rem 3.5rem 7.5rem;
  min-height: 100vh;
  background: transparent;
}

/* Brand icon + name (top) */
.brand-logo {
  display: flex;
  align-items: center;
  gap: 0.85rem;
}
.brand-icon {
  width: 60px; height: 60px;
  background: #ffffff;
  border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 6px 24px rgba(37,99,235,0.15);
  color: #2563eb;
  flex-shrink: 0;
}

/* Main heading block — vertically centered in the 1fr row */
.auth-left-body {
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 1rem 0;
}
.auth-left-body h1 {
  font-family: 'Outfit', sans-serif;
  font-size: 3.6rem;
  font-weight: 800;
  color: #1a2f6e;
  line-height: 1.08;
  letter-spacing: -0.03em;
  margin-bottom: 0.85rem;
}
/* Short blue underline accent */
.h1-underline {
  width: 56px;
  height: 5px;
  background: #2563eb;
  border-radius: 3px;
  margin-bottom: 1.5rem;
}
.auth-left-body p {
  font-size: 1.05rem;
  color: #3558a0;
  line-height: 1.7;
  max-width: 320px;
  font-weight: 400;
}

/* Campus badge (bottom) */
.campus-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.55rem 1.25rem;
  background: rgba(255,255,255,0.55);
  border: 1px solid rgba(255,255,255,0.85);
  border-radius: 50px;
  font-size: 0.82rem;
  font-weight: 600;
  color: #1e40af;
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}

/* ─────────────────────────────────────────
   RIGHT — form area, fully transparent
   (no card, no border — matches screenshot)
───────────────────────────────────────── */
.auth-right {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  padding: 3.5rem 4rem;
  background: transparent;
  min-height: 100vh;
}

.auth-form-wrap {
  width: 100%;
  max-width: 420px;
}

.auth-form-wrap h2 {
  font-family: 'Outfit', sans-serif;
  font-size: 2rem;
  font-weight: 800;
  color: #0f172a;
  letter-spacing: -0.025em;
  margin-bottom: 0.35rem;
}
.auth-subtitle {
  font-size: 0.9rem;
  color: #64748b;
  margin-bottom: 1.75rem;
}

/* ─── Tabs ─── */
.auth-tabs {
  display: flex;
  border-bottom: 1.5px solid #dde6f0;
  margin-bottom: 1.6rem;
  gap: 0;
}
.auth-tab-btn {
  flex: 1;
  padding: 0.6rem 0.5rem;
  border: none;
  background: transparent;
  font-family: 'Inter', sans-serif;
  font-size: 0.875rem;
  font-weight: 600;
  color: #94a3b8;
  cursor: pointer;
  border-bottom: 2.5px solid transparent;
  margin-bottom: -1.5px;
  transition: all 0.2s;
  text-align: center;
}
.auth-tab-btn.active {
  color: #2563eb;
  border-bottom-color: #2563eb;
}
.auth-tab-btn:hover:not(.active) { color: #475569; }

/* ─── Alert ─── */
.auth-alert {
  display: flex; align-items: center; gap: 0.5rem;
  padding: 0.8rem 1rem; border-radius: 10px;
  font-size: 0.875rem; font-weight: 500;
  margin-bottom: 1.2rem;
}
.auth-alert.error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
.auth-alert.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }

/* ─── Form fields ─── */
.auth-field { margin-bottom: 1rem; }
.auth-field > label {
  display: block;
  font-size: 0.83rem;
  font-weight: 600;
  color: #1e293b;
  margin-bottom: 0.4rem;
}
.input-wrap { position: relative; display: flex; align-items: center; }
.input-icon {
  position: absolute;
  left: 0.9rem;
  color: #94a3b8;
  display: flex; align-items: center;
  pointer-events: none;
  z-index: 1;
}
.auth-input {
  width: 100%;
  padding: 0.72rem 1rem 0.72rem 2.55rem;
  border: 1.5px solid #dde6f2;
  border-radius: 10px;
  font-family: 'Inter', sans-serif;
  font-size: 0.9rem;
  color: #0f172a;
  background: #ffffff;
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.auth-input:focus {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.auth-input.no-icon { padding-left: 1rem; }
select.auth-input   { padding-left: 1rem; cursor: pointer; }

.pw-toggle {
  position: absolute; right: 0.85rem;
  background: none; border: none; cursor: pointer;
  color: #94a3b8; display: flex; align-items: center;
  transition: color 0.2s;
}
.pw-toggle:hover { color: #2563eb; }

/* ─── Remember me row ─── */
.auth-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.3rem;
}
.remember-label {
  display: flex; align-items: center; gap: 0.45rem;
  font-size: 0.84rem; color: #374151;
  cursor: pointer; font-weight: 500;
}
.remember-label input[type="checkbox"] {
  width: 15px; height: 15px;
  accent-color: #2563eb; cursor: pointer;
}
.forgot-link {
  font-size: 0.84rem; color: #2563eb;
  text-decoration: none; font-weight: 500;
}
.forgot-link:hover { text-decoration: underline; }

/* ─── Submit button ─── */
.auth-submit {
  width: 100%;
  padding: 0.85rem;
  background: #2563eb;
  color: #ffffff;
  border: none;
  border-radius: 10px;
  font-family: 'Outfit', sans-serif;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
  letter-spacing: 0.01em;
  box-shadow: 0 4px 16px rgba(37,99,235,0.3);
  transition: background 0.2s, box-shadow 0.2s, transform 0.15s;
}
.auth-submit:hover {
  background: #1d4ed8;
  box-shadow: 0 8px 24px rgba(37,99,235,0.38);
  transform: translateY(-1px);
}
.auth-submit:active { transform: translateY(0); }

/* ─── Password strength ─── */
.strength-wrap  { margin-top: 0.4rem; display: flex; align-items: center; gap: 0.5rem; }
.strength-bar   { flex: 1; height: 4px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
.strength-fill  { height: 100%; border-radius: 4px; transition: width .3s, background .3s; }
.strength-label { font-size: 0.73rem; font-weight: 600; color: #94a3b8; min-width: 40px; }

/* ─── Footer ─── */
.auth-footer {
  margin-top: 1.75rem;
  font-size: 0.78rem;
  color: #94a3b8;
  text-align: center;
}

/* ─── Responsive ─── */
@media (max-width: 820px) {
  .page-wrap { flex-direction: column; }
  .auth-left {
    flex: none;
    padding: 2.5rem 2rem 1.5rem;
  }
  .auth-left-body h1 { font-size: 2.2rem; }
  .auth-right {
    padding: 2rem 1.5rem 3rem;
    align-items: center;
  }
  .bg-waves { width: 100vw; }
}
</style>
</head>
<body>

<!-- SVG Wave lines (bottom-left) — matches screenshot style -->
<svg class="bg-waves" viewBox="0 0 860 500" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMinYMax meet">
  <!-- Multiple flowing wave paths layered for depth -->
  <path d="M-40 460 C 120 390, 340 430, 560 370 S 780 310, 900 330" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" fill="none"/>
  <path d="M-40 440 C 130 370, 350 410, 570 350 S 790 285, 900 310" stroke="rgba(255,255,255,0.55)" stroke-width="2" fill="none"/>
  <path d="M-40 420 C 140 352, 360 392, 580 332 S 800 268, 900 292" stroke="rgba(255,255,255,0.42)" stroke-width="1.5" fill="none"/>
  <path d="M-40 400 C 150 332, 370 374, 590 314 S 810 250, 900 274" stroke="rgba(255,255,255,0.30)" stroke-width="1.5" fill="none"/>
  <path d="M-40 378 C 160 310, 380 354, 600 295 S 820 232, 900 255" stroke="rgba(255,255,255,0.22)" stroke-width="1" fill="none"/>
  <path d="M-40 356 C 170 289, 390 334, 610 276 S 830 213, 900 237" stroke="rgba(255,255,255,0.16)" stroke-width="1" fill="none"/>
  <path d="M-40 334 C 180 268, 400 314, 620 257 S 840 195, 900 218" stroke="rgba(255,255,255,0.11)" stroke-width="1" fill="none"/>
  <path d="M-40 480 C 110 412, 330 452, 550 392 S 770 330, 900 350" stroke="rgba(255,255,255,0.80)" stroke-width="2.5" fill="none"/>
  <path d="M-40 498 C 100 432, 320 470, 540 412 S 760 350, 900 368" stroke="rgba(255,255,255,0.60)" stroke-width="2" fill="none"/>
</svg>

<div class="page-wrap">

  <!-- ══════════ LEFT PANEL ══════════ -->
  <div class="auth-left">

    <!-- Top: Brand logo -->
    <div class="brand-logo">
      <div class="brand-icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24"
          fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="20" x2="18" y2="10"/>
          <line x1="12" y1="20" x2="12" y2="4"/>
          <line x1="6"  y1="20" x2="6"  y2="14"/>
          <line x1="2"  y1="20" x2="22" y2="20"/>
        </svg>
      </div>
    </div>

    <!-- Middle: Heading + description -->
    <div class="auth-left-body">
      <h1>AccounTech<br>AIS Platform</h1>
      <div class="h1-underline"></div>
      <p>Empowering Laguna State Polytechnic University with modern, reliable, and secure accounting tools.</p>
    </div>



  </div><!-- /auth-left -->

  <!-- ══════════ RIGHT PANEL ══════════ -->
  <div class="auth-right">
    <div class="auth-form-wrap">

      <h2>Welcome back</h2>
      <p class="auth-subtitle">Please enter your details to sign in.</p>

      <!-- Tabs -->
      <div class="auth-tabs">
        <button id="tab-login"    class="auth-tab-btn <?= $mode==='login'    ? 'active' : '' ?>" onclick="switchTab('login')">Sign In</button>
        <button id="tab-register" class="auth-tab-btn <?= $mode==='register' ? 'active' : '' ?>" onclick="switchTab('register')">Create Account</button>
      </div>

      <!-- Alerts -->
      <?php if ($error): ?>
      <div class="auth-alert error">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>
      <?php if ($success): ?>
      <div class="auth-alert success">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?= htmlspecialchars($success) ?>
      </div>
      <?php endif; ?>

      <!-- ── LOGIN FORM ── -->
      <form method="POST" id="lf" style="display:<?= $mode==='login' ? 'block' : 'none' ?>">
        <input type="hidden" name="action" value="login">

        <div class="auth-field">
          <label for="login-email">Email Address</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            </span>
            <input type="email" id="login-email" name="email" class="auth-input"
              placeholder="you@example.com"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
          </div>
        </div>

        <div class="auth-field">
          <label for="lpw">Password</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <input type="password" id="lpw" name="password" class="auth-input" placeholder="••••••••" required>
            <button type="button" class="pw-toggle" onclick="togglePw('lpw',this)" aria-label="Show/hide password">
              <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <div class="auth-row">
          <label class="remember-label">
            <input type="checkbox" name="remember" id="remember">
            Remember me
          </label>
          <a href="#" class="forgot-link">Forgot password?</a>
        </div>

        <button type="submit" id="btn-signin" class="auth-submit">Sign In →</button>
      </form>

      <!-- ── REGISTER FORM ── -->
      <form method="POST" id="rf" style="display:<?= $mode==='register' ? 'block' : 'none' ?>">
        <input type="hidden" name="action" value="register">

        <div class="auth-field">
          <label for="reg-name">Full Name</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </span>
            <input type="text" id="reg-name" name="name" class="auth-input"
              placeholder="Juan dela Cruz"
              value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
          </div>
        </div>

        <div class="auth-field">
          <label for="reg-email">Email Address</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            </span>
            <input type="email" id="reg-email" name="email" class="auth-input"
              placeholder="you@example.com"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
          </div>
        </div>

        <div class="auth-field">
          <label for="reg-role">I am a...</label>
          <select id="reg-role" name="role" class="auth-input no-icon" required>
            <option value="Student"    <?= (isset($_POST['role']) && $_POST['role']==='Student')    ? 'selected' : '' ?>>Student</option>
            <option value="Instructor" <?= (isset($_POST['role']) && $_POST['role']==='Instructor') ? 'selected' : '' ?>>Instructor</option>
          </select>
        </div>

        <div class="auth-field">
          <label for="rpw">Password</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <input type="password" id="rpw" name="password" class="auth-input"
              placeholder="Min 6 characters" oninput="strengthCheck(this.value)" required>
            <button type="button" class="pw-toggle" onclick="togglePw('rpw',this)" aria-label="Show/hide password">
              <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          <div class="strength-wrap">
            <div class="strength-bar"><div class="strength-fill" id="sf" style="width:0%"></div></div>
            <span class="strength-label" id="sl"></span>
          </div>
        </div>

        <div class="auth-field">
          <label for="cpw">Confirm Password</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <input type="password" id="cpw" name="confirm_pass" class="auth-input" placeholder="Re-enter password" required>
            <button type="button" class="pw-toggle" onclick="togglePw('cpw',this)" aria-label="Show/hide password">
              <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <button type="submit" id="btn-register" class="auth-submit">Create Account →</button>
      </form>

      <div class="auth-footer">
        © 2025 AccounTech AIS Platform. All rights reserved.
      </div>

    </div>
  </div><!-- /auth-right -->

</div><!-- /page-wrap -->

<script>
function switchTab(tab) {
  const lf = document.getElementById('lf');
  const rf = document.getElementById('rf');
  const tl = document.getElementById('tab-login');
  const tr = document.getElementById('tab-register');
  if (tab === 'login') {
    lf.style.display = 'block'; rf.style.display = 'none';
    tl.classList.add('active'); tr.classList.remove('active');
  } else {
    rf.style.display = 'block'; lf.style.display = 'none';
    tr.classList.add('active'); tl.classList.remove('active');
  }
}

function togglePw(id, btn) {
  const el = document.getElementById(id);
  const isText = el.type === 'password';
  el.type = isText ? 'text' : 'password';
  btn.style.color = isText ? '#2563eb' : '#94a3b8';
}

function strengthCheck(pw) {
  let sc = 0;
  if (pw.length >= 6)           sc++;
  if (pw.length >= 10)          sc++;
  if (/[A-Z]/.test(pw))        sc++;
  if (/[0-9]/.test(pw))        sc++;
  if (/[^a-zA-Z0-9]/.test(pw)) sc++;
  const f = document.getElementById('sf');
  const l = document.getElementById('sl');
  f.style.width = Math.round((sc / 5) * 100) + '%';
  if (sc <= 1)      { f.style.background = '#ef4444'; l.textContent = 'Weak';   l.style.color = '#ef4444'; }
  else if (sc <= 3) { f.style.background = '#f59e0b'; l.textContent = 'Fair';   l.style.color = '#f59e0b'; }
  else              { f.style.background = '#10b981'; l.textContent = 'Strong'; l.style.color = '#10b981'; }
}
</script>
</body>
</html>
