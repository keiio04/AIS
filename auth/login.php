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
    } elseif ($_POST['action'] === 'google_login') {
        $email = trim($_POST['google_email'] ?? '');
        $name  = trim($_POST['google_name'] ?? '');
        $credential = $_POST['credential'] ?? '';

        if (!empty($credential)) {
            $parts = explode('.', $credential);
            if (count($parts) >= 2) {
                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                if (!empty($payload['email'])) {
                    $email = $payload['email'];
                    $name  = $payload['name'] ?? ($payload['given_name'] ?? explode('@', $email)[0]);
                }
            }
        }

        if (empty($email)) {
            $error = 'Google authentication failed: Email address not provided.';
        } else {
            $db = get_db();
            $stmt = $db->prepare("SELECT id, name, email, role, password, active_company_id FROM users WHERE email = ?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if (!$user) {
                $safeName = !empty($name) ? $name : explode('@', $email)[0];
                $randomPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
                $ins = $db->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'Student')");
                $ins->bind_param('sss', $safeName, $email, $randomPass);
                $ins->execute();
                $newUserId = $ins->insert_id;

                $user = [
                    'id' => $newUserId,
                    'name' => $safeName,
                    'email' => $email,
                    'role' => 'Student',
                    'active_company_id' => null
                ];
            }

            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role']  = $user['role'];
            if (!empty($user['active_company_id'])) {
                $_SESSION['active_company_id'] = $user['active_company_id'];
            }

            $stmtLog = $db->prepare("INSERT INTO activity_logs (user_id, action, module, description) VALUES (?, 'Google Login', 'Authentication', 'User signed in with Google')");
            $stmtLog->execute([$user['id']]);

            if ($user['role'] === 'Admin') {
                header('Location: ' . BASE_URL . 'admin/dashboard.php');
            } else {
                header('Location: ' . BASE_URL . 'pages/dashboard.php');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TALA-AIS – Authentication</title>
<meta name="description" content="Sign in or create an account on TALA-AIS – Laguna State Polytechnic University Accounting Information System.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800;900&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg-deep: #070b14;
  --bg-card: rgba(13, 21, 41, 0.78);
  --border-card: rgba(59, 130, 246, 0.25);
  --border-card-hover: rgba(96, 165, 250, 0.55);

  --brand-blue: #0070f3;
  --brand-electric: #007aff;
  --brand-glow: #3b82f6;
  --brand-cyan: #06b6d4;

  --text-white: #ffffff;
  --text-muted: #94a3b8;
  --text-light-blue: #cbd5e1;

  --shadow-card: 0 20px 50px rgba(0, 0, 0, 0.5), 0 0 30px rgba(37, 99, 235, 0.15);
  --shadow-glow: 0 0 35px rgba(0, 112, 243, 0.6);
}

html, body {
  min-height: 100%;
  font-family: 'Inter', sans-serif;
  color: var(--text-white);
  background-color: var(--bg-deep);
  background-image: 
    radial-gradient(circle at 50% 0%, rgba(30, 58, 138, 0.45) 0%, transparent 60%),
    radial-gradient(circle at 80% 60%, rgba(37, 99, 235, 0.25) 0%, transparent 50%),
    radial-gradient(circle at 20% 90%, rgba(14, 165, 233, 0.2) 0%, transparent 45%),
    linear-gradient(180deg, #070b14 0%, #0b1329 45%, #0f214d 80%, #173275 100%);
  position: relative;
  overflow-x: hidden;
}

/* Hero Background Layer matching index.php */
.hero-bg-layer {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-image: 
    linear-gradient(180deg, rgba(6, 10, 18, 0.55) 0%, rgba(7, 11, 20, 0.45) 45%, rgba(11, 19, 41, 0.88) 88%, #0b1329 100%),
    radial-gradient(circle at 50% 35%, rgba(0, 112, 243, 0.15) 0%, rgba(6, 10, 18, 0.6) 80%),
    url('<?= BASE_URL ?>assets/images/hero-accounting-bg.jpg');
  background-size: cover;
  background-position: center;
  background-repeat: no-repeat;
  pointer-events: none;
  z-index: 1;
  opacity: 0.5;
}

/* Ambient Radial Glow Overlays */
.ambient-glow-top {
  position: absolute;
  top: -120px;
  left: 50%;
  transform: translateX(-50%);
  width: 900px;
  height: 500px;
  background: radial-gradient(ellipse at center, rgba(37, 99, 235, 0.45) 0%, rgba(6, 10, 18, 0) 70%);
  pointer-events: none;
  z-index: 0;
}

.ambient-glow-bottom {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 400px;
  background: radial-gradient(ellipse at 50% 100%, rgba(37, 99, 235, 0.35) 0%, transparent 70%);
  pointer-events: none;
  z-index: 0;
}

/* Background Glowing Wave lines (bottom-left) */
.bg-waves {
  position: fixed;
  bottom: -20px;
  left: -20px;
  width: 52vw;
  min-width: 360px;
  pointer-events: none;
  z-index: 1;
  opacity: 0.25;
}

/* ─────────────────────────────────────────
   PAGE LAYOUT
───────────────────────────────────────── */
.page-wrap {
  position: relative;
  z-index: 2;
  display: flex;
  width: 100%;
  min-height: 100vh;
}

/* ─────────────────────────────────────────
   LEFT — brand area
───────────────────────────────────────── */
.auth-left {
  flex: 0 0 48%;
  display: flex;
  flex-direction: column;
  padding: 3.5rem 2rem 3.5rem 7rem;
  min-height: 100vh;
  position: relative;
}

/* Back Link (Top Left Arrow) */
.back-link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 12px;
  background: rgba(18, 28, 51, 0.6);
  border: 1px solid rgba(59, 130, 246, 0.25);
  color: var(--text-light-blue);
  text-decoration: none;
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
  margin-bottom: 2rem;
  backdrop-filter: blur(10px);
}
.back-link:hover {
  background: rgba(0, 112, 243, 0.25);
  border-color: rgba(96, 165, 250, 0.6);
  color: #ffffff;
  transform: translateX(-3px);
  box-shadow: 0 0 20px rgba(0, 112, 243, 0.4);
}

/* Brand icon + name */
.brand-logo {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.brand-icon {
  width: 48px;
  height: 48px;
  background: linear-gradient(135deg, #0ea5e9, #3b82f6, #1d4ed8);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 0 25px rgba(0, 112, 243, 0.7);
  color: #ffffff;
  flex-shrink: 0;
  animation: starFloat 4s ease-in-out infinite;
}

.brand-icon svg {
  animation: starTwinkle 4s ease-in-out infinite alternate;
}

@keyframes starFloat {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
}

@keyframes starTwinkle {
  0% { transform: scale(0.97); }
  50% { transform: scale(1.05) rotate(2deg); }
  100% { transform: scale(1); }
}

/* Main heading block */
.auth-left-body {
  display: flex;
  flex-direction: column;
  justify-content: center;
  margin: auto 0;
  padding: 2rem 0;
}

.auth-left-body h1 {
  font-family: 'Plus Jakarta Sans', 'Outfit', sans-serif;
  font-size: clamp(3.8rem, 5.5vw, 5.4rem);
  font-weight: 900;
  background: linear-gradient(180deg, #ffffff 40%, #cbd5e1 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  line-height: 1.05;
  letter-spacing: -0.04em;
  margin-bottom: 0.85rem;
  filter: drop-shadow(0 4px 20px rgba(0, 0, 0, 0.7));
}

.auth-left-body h1 .gradient-text {
  background: linear-gradient(135deg, #60a5fa 0%, #38bdf8 50%, #93c5fd 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

.h1-underline {
  width: 80px;
  height: 5px;
  background: linear-gradient(90deg, #0070f3, #00d2ff);
  border-radius: 6px;
  margin-bottom: 1.85rem;
  box-shadow: 0 0 14px rgba(0, 112, 243, 0.7);
}

.auth-left-body p {
  font-size: 1.25rem;
  color: var(--text-light-blue);
  line-height: 1.7;
  max-width: 480px;
  font-weight: 400;
}

/* ─────────────────────────────────────────
   RIGHT — form area
───────────────────────────────────────── */
.auth-right {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  padding: 2.5rem 4rem;
  min-height: 100vh;
}

.auth-form-wrap {
  width: 100%;
  max-width: 440px;
  background: rgba(13, 21, 41, 0.82);
  padding: 2.25rem 2rem;
  border-radius: 24px;
  box-shadow: 0 25px 60px rgba(0, 0, 0, 0.6), 0 0 35px rgba(37, 99, 235, 0.18);
  border: 1px solid rgba(59, 130, 246, 0.28);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
}

.auth-form-wrap h2 {
  font-family: 'Plus Jakarta Sans', 'Outfit', sans-serif;
  font-size: 1.85rem;
  font-weight: 800;
  color: #ffffff;
  letter-spacing: -0.025em;
  text-align: center;
  margin-bottom: 1.25rem;
}

/* ─── Tabs ─── */
.auth-tabs {
  display: flex;
  border-bottom: 1.5px solid rgba(255, 255, 255, 0.1);
  margin-bottom: 1.5rem;
  gap: 0;
}

.auth-tab-btn {
  flex: 1;
  padding: 0.75rem 0.5rem;
  border: none;
  background: transparent;
  font-family: 'Inter', sans-serif;
  font-size: 0.95rem;
  font-weight: 600;
  color: var(--text-muted);
  cursor: pointer;
  border-bottom: 2.5px solid transparent;
  margin-bottom: -1.5px;
  transition: all 0.2s ease;
  text-align: center;
}

.auth-tab-btn.active {
  color: #60a5fa;
  border-bottom-color: #0070f3;
  font-weight: 700;
  text-shadow: 0 0 10px rgba(96, 165, 250, 0.35);
}

.auth-tab-btn:hover:not(.active) { 
  color: #ffffff; 
}

/* ─── Alerts ─── */
.auth-alert {
  display: flex; 
  align-items: center; 
  gap: 0.6rem;
  padding: 0.8rem 1rem; 
  border-radius: 12px;
  font-size: 0.85rem; 
  font-weight: 500;
  margin-bottom: 1.2rem;
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

/* ─── Form fields ─── */
.auth-field { margin-bottom: 1rem; }
.auth-field > label {
  display: block;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--text-light-blue);
  margin-bottom: 0.4rem;
}
.input-wrap { position: relative; display: flex; align-items: center; }
.input-icon {
  position: absolute;
  left: 0.95rem;
  color: #60a5fa;
  display: flex; 
  align-items: center;
  pointer-events: none;
  z-index: 1;
  transition: color 0.2s ease;
}
.input-wrap:focus-within .input-icon {
  color: #38bdf8;
}
.auth-input {
  width: 100%;
  padding: 0.75rem 1rem 0.75rem 2.6rem;
  border: 1.5px solid rgba(59, 130, 246, 0.25);
  border-radius: 12px;
  font-family: 'Inter', sans-serif;
  font-size: 0.925rem;
  color: #ffffff;
  background: rgba(10, 18, 36, 0.75);
  outline: none;
  transition: all 0.2s ease;
}
.auth-input:focus {
  border-color: #0070f3;
  box-shadow: 0 0 0 3px rgba(0, 112, 243, 0.25);
  background: rgba(14, 25, 50, 0.95);
}
.auth-input::placeholder {
  color: #64748b;
}
.auth-input.no-icon { padding-left: 1rem; }
select.auth-input { 
  padding-left: 1rem; 
  cursor: pointer;
  background-color: #0a1224;
}
select.auth-input option {
  background: #0a1224;
  color: #ffffff;
}

.pw-toggle {
  position: absolute; 
  right: 0.85rem;
  background: none; 
  border: none; 
  cursor: pointer;
  color: #64748b; 
  display: flex; 
  align-items: center;
  transition: color 0.2s;
}
.pw-toggle:hover { color: #60a5fa; }

/* ─── Remember me row ─── */
.auth-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.35rem;
}
.remember-label {
  display: flex; 
  align-items: center; 
  gap: 0.5rem;
  font-size: 0.875rem; 
  color: var(--text-muted);
  cursor: pointer; 
  font-weight: 500;
}
.remember-label input[type="checkbox"] {
  width: 16px; 
  height: 16px;
  accent-color: #0070f3; 
  cursor: pointer;
}
.forgot-link {
  font-size: 0.875rem; 
  color: #60a5fa;
  text-decoration: none; 
  font-weight: 500;
  transition: color 0.2s;
}
.forgot-link:hover { 
  color: #93c5fd; 
  text-decoration: underline; 
}

/* ─── Submit button ─── */
.auth-submit {
  width: 100%;
  padding: 0.85rem;
  background: linear-gradient(135deg, #0070f3, #0051cc);
  color: #ffffff;
  border: none;
  border-radius: 9999px;
  font-family: 'Inter', sans-serif;
  font-size: 0.95rem;
  font-weight: 700;
  cursor: pointer;
  letter-spacing: 0.01em;
  box-shadow: 0 4px 20px rgba(0, 112, 243, 0.45);
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.auth-submit:hover {
  background: linear-gradient(135deg, #1a82ff, #0060e6);
  box-shadow: 0 8px 30px rgba(0, 112, 243, 0.7);
  transform: translateY(-2px);
}
.auth-submit:active { transform: translateY(0); }

/* Google Sign-in Button & Divider */
.btn-google {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  width: 100%;
  padding: 0.8rem 1rem;
  background-color: #ffffff;
  color: #374151;
  border: 1px solid rgba(255, 255, 255, 0.2);
  border-radius: 9999px;
  font-family: 'Inter', sans-serif;
  font-size: 0.95rem;
  font-weight: 600;
  cursor: pointer;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.25);
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
  text-decoration: none;
  margin-bottom: 1.15rem;
}
.btn-google:hover {
  background-color: #f8fafc;
  color: #111827;
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(255, 255, 255, 0.2);
}
.btn-google:active {
  transform: translateY(0);
}
.btn-google svg {
  width: 19px;
  height: 19px;
  flex-shrink: 0;
}

.auth-divider {
  display: flex;
  align-items: center;
  text-align: center;
  margin: 1.15rem 0 1.35rem;
  color: #64748b;
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.auth-divider::before,
.auth-divider::after {
  content: '';
  flex: 1;
  border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}
.auth-divider span {
  padding: 0 0.75rem;
}

/* Google Prompt Modal */
.g-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.75);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  padding: 1rem;
}
.g-modal-card {
  background: #0f172a;
  border: 1px solid rgba(59, 130, 246, 0.35);
  border-radius: 20px;
  padding: 2rem 1.75rem;
  max-width: 400px;
  width: 100%;
  box-shadow: 0 25px 60px rgba(0,0,0,0.8), 0 0 35px rgba(37,99,235,0.3);
  text-align: center;
  position: relative;
  animation: modalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalPop {
  0% { transform: scale(0.92); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}

/* ─── Password strength ─── */
.strength-wrap  { margin-top: 0.4rem; display: flex; align-items: center; gap: 0.5rem; }
.strength-bar   { flex: 1; height: 5px; background: rgba(255, 255, 255, 0.1); border-radius: 4px; overflow: hidden; }
.strength-fill  { height: 100%; border-radius: 4px; transition: width .3s, background .3s; }
.strength-label { font-size: 0.73rem; font-weight: 600; color: #94a3b8; min-width: 40px; }

/* ─── Responsive ─── */
@media (max-width: 900px) {
  .page-wrap {
    flex-direction: column;
    min-height: auto;
  }
  .auth-left {
    flex: none;
    min-height: auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 2.25rem 1.25rem 0.5rem;
    position: relative;
  }
  .back-link {
    position: absolute;
    top: 1.25rem;
    left: 1.25rem;
    margin-bottom: 0;
    width: 38px;
    height: 38px;
  }
  .brand-logo {
    justify-content: center;
    margin-bottom: 0.5rem;
  }
  .brand-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
  }
  .auth-left-body {
    padding: 0.25rem 0;
    align-items: center;
  }
  .auth-left-body h1 {
    font-size: 2.2rem;
    margin-bottom: 0.25rem;
  }
  .h1-underline {
    margin: 0.35rem auto 0.75rem;
    height: 3px;
    width: 48px;
  }
  .auth-left-body p {
    font-size: 0.9rem;
    line-height: 1.5;
    max-width: 320px;
  }
  .auth-right {
    min-height: auto;
    padding: 1rem 1rem 3rem;
    width: 100%;
    align-items: center;
  }
  .auth-form-wrap {
    width: 100%;
    max-width: 420px;
    padding: 1.6rem 1.35rem;
    border-radius: 20px;
  }
  .auth-form-wrap h2 {
    font-size: 1.5rem;
  }
  .bg-waves {
    width: 100vw;
    opacity: 0.15;
  }
}
</style>
</head>
<body>

<div class="hero-bg-layer"></div>
<div class="ambient-glow-top"></div>
<div class="ambient-glow-bottom"></div>

<!-- SVG Glowing Wave lines (bottom-left) -->
<svg class="bg-waves" viewBox="0 0 860 500" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMinYMax meet">
  <path d="M-40 460 C 120 390, 340 430, 560 370 S 780 310, 900 330" stroke="rgba(59,130,246,0.3)" stroke-width="2.5" fill="none"/>
  <path d="M-40 440 C 130 370, 350 410, 570 350 S 790 285, 900 310" stroke="rgba(59,130,246,0.22)" stroke-width="2" fill="none"/>
  <path d="M-40 420 C 140 352, 360 392, 580 332 S 800 268, 900 292" stroke="rgba(14,165,233,0.18)" stroke-width="1.5" fill="none"/>
  <path d="M-40 400 C 150 332, 370 374, 590 314 S 810 250, 900 274" stroke="rgba(14,165,233,0.12)" stroke-width="1.5" fill="none"/>
  <path d="M-40 480 C 110 412, 330 452, 550 392 S 770 330, 900 350" stroke="rgba(59,130,246,0.35)" stroke-width="2.5" fill="none"/>
</svg>

<div class="page-wrap">

  <!-- ══════════ LEFT PANEL ══════════ -->
  <div class="auth-left">

    <!-- Top: Back Link & Brand logo -->
    <div>
      <a href="<?= BASE_URL ?>" class="back-link" title="Back to Home" aria-label="Back to Home">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
      </a>
      <div class="brand-logo">
        <div class="brand-icon">
          <!-- TALA-AIS Glowing Star -->
          <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"
            fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
          </svg>
        </div>
      </div>
    </div>

    <!-- Middle: Heading + description -->
    <div class="auth-left-body">
      <h1>TALA-<span class="gradient-text">AIS</span></h1>
      <div class="h1-underline"></div>
      <p>Making accounting learning more practical, interactive, and accessible through guided simulation and hands-on practice.</p>
    </div>

  </div><!-- /auth-left -->

  <!-- ══════════ RIGHT PANEL ══════════ -->
  <div class="auth-right">
    <div class="auth-form-wrap">

      <h2 id="form-title"><?= $mode === 'register' ? 'Create Account' : 'Welcome back' ?></h2>

      <!-- Tabs -->
      <div class="auth-tabs">
        <button id="tab-login"    class="auth-tab-btn <?= $mode==='login'    ? 'active' : '' ?>" onclick="switchTab('login')">Sign In</button>
        <button id="tab-register" class="auth-tab-btn <?= $mode==='register' ? 'active' : '' ?>" onclick="switchTab('register')">Create Account</button>
      </div>

      <!-- Alerts -->
      <?php if ($error): ?>
      <div class="auth-alert error">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>
      <?php if ($success): ?>
      <div class="auth-alert success">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?= htmlspecialchars($success) ?>
      </div>
      <?php endif; ?>

      <!-- ── GOOGLE SIGN IN BUTTON ── -->
      <button type="button" class="btn-google" id="btn-google-auth" onclick="handleGoogleSignIn()">
        <svg viewBox="0 0 24 24">
          <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
          <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
          <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
          <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
        </svg>
        <span id="google-btn-text"><?= $mode === 'register' ? 'Sign up with Google' : 'Sign in with Google' ?></span>
      </button>

      <div class="auth-divider">
        <span>or continue with email</span>
      </div>

      <!-- Hidden Google Auth Form -->
      <form id="google-auth-form" method="POST" style="display:none;">
        <input type="hidden" name="action" value="google_login">
        <input type="hidden" name="credential" id="google-credential">
        <input type="hidden" name="google_email" id="google-email-input">
        <input type="hidden" name="google_name" id="google-name-input">
      </form>

      <!-- ── LOGIN FORM ── -->
      <form method="POST" id="lf" style="display:<?= $mode==='login' ? 'block' : 'none' ?>">
        <input type="hidden" name="action" value="login">

        <div class="auth-field">
          <label for="login-email">Email Address</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            </span>
            <input type="email" id="login-email" name="email" class="auth-input"
              placeholder="Enter your email"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
          </div>
        </div>

        <div class="auth-field">
          <label for="lpw">Password</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <input type="password" id="lpw" name="password" class="auth-input" placeholder="Enter your password" required>
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
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </span>
            <input type="text" id="reg-name" name="name" class="auth-input"
              placeholder="Enter your full name"
              value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
          </div>
        </div>

        <div class="auth-field">
          <label for="reg-email">Email Address</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            </span>
            <input type="email" id="reg-email" name="email" class="auth-input"
              placeholder="Enter your email"
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
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <input type="password" id="rpw" name="password" class="auth-input"
              placeholder="Create a password" oninput="strengthCheck(this.value)" required>
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
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <input type="password" id="cpw" name="confirm_pass" class="auth-input" placeholder="Confirm your password" required>
            <button type="button" class="pw-toggle" onclick="togglePw('cpw',this)" aria-label="Show/hide password">
              <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <button type="submit" id="btn-register" class="auth-submit">Create Account →</button>
      </form>

    </div>
  </div><!-- /auth-right -->

</div><!-- /page-wrap -->

<!-- Google Quick Login Modal -->
<div class="g-modal-backdrop" id="google-modal" onclick="closeGoogleModal(event)">
  <div class="g-modal-card" onclick="event.stopPropagation()">
    <div style="width: 48px; height: 48px; background: #ffffff; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; box-shadow: 0 4px 15px rgba(0,0,0,0.3);">
      <svg viewBox="0 0 24 24" width="26" height="26">
        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
      </svg>
    </div>
    <h3 style="font-size: 1.25rem; font-weight: 700; color: #ffffff; margin-bottom: 0.35rem;">Sign in with Google</h3>
    <p style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 1.25rem;">Enter your Gmail address to instantly sign in or register to TALA-AIS.</p>
    
    <div style="margin-bottom: 1rem; text-align: left;">
      <label style="font-size: 0.8rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 0.4rem;">Gmail / Google Account</label>
      <input type="email" id="modal-g-email" class="auth-input" placeholder="example@gmail.com" style="width: 100%; border-radius: 12px; padding: 0.75rem 1rem; background: rgba(30, 41, 59, 0.8); border: 1px solid rgba(59, 130, 246, 0.3); color: #fff;">
    </div>

    <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
      <button type="button" onclick="closeGoogleModal()" style="flex: 1; padding: 0.7rem; border-radius: 9999px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); color: #cbd5e1; font-weight: 600; cursor: pointer;">Cancel</button>
      <button type="button" onclick="submitGoogleModal()" style="flex: 1; padding: 0.7rem; border-radius: 9999px; background: linear-gradient(135deg, #0070f3, #0051cc); border: none; color: #fff; font-weight: 700; cursor: pointer; box-shadow: 0 4px 15px rgba(0,112,243,0.4);">Continue →</button>
    </div>
  </div>
</div>

<?php if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID): ?>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<?php endif; ?>

<script>
const GOOGLE_CLIENT_ID = "<?= defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '' ?>";

window.onload = function() {
  if (GOOGLE_CLIENT_ID && typeof google !== 'undefined' && google.accounts) {
    google.accounts.id.initialize({
      client_id: GOOGLE_CLIENT_ID,
      callback: handleGoogleCredentialResponse
    });
  }
};

function handleGoogleCredentialResponse(response) {
  if (response && response.credential) {
    document.getElementById('google-credential').value = response.credential;
    document.getElementById('google-auth-form').submit();
  }
}

function handleGoogleSignIn() {
  if (GOOGLE_CLIENT_ID && typeof google !== 'undefined' && google.accounts) {
    google.accounts.id.prompt((notification) => {
      if (notification.isNotDisplayed() || notification.isSkippedMoment()) {
        openGoogleModal();
      }
    });
  } else {
    openGoogleModal();
  }
}

function openGoogleModal() {
  const modal = document.getElementById('google-modal');
  if (modal) {
    modal.style.display = 'flex';
    const input = document.getElementById('modal-g-email');
    if (input) setTimeout(() => input.focus(), 50);
  }
}

function closeGoogleModal(e) {
  const modal = document.getElementById('google-modal');
  if (modal) modal.style.display = 'none';
}

function submitGoogleModal() {
  const emailInput = document.getElementById('modal-g-email');
  const email = emailInput ? emailInput.value.trim() : '';
  if (!email) {
    alert('Please enter your Google/Gmail address.');
    return;
  }
  if (!email.includes('@')) {
    alert('Please enter a valid email address.');
    return;
  }
  document.getElementById('google-email-input').value = email;
  document.getElementById('google-auth-form').submit();
}

function switchTab(tab) {
  const lf = document.getElementById('lf');
  const rf = document.getElementById('rf');
  const tl = document.getElementById('tab-login');
  const tr = document.getElementById('tab-register');
  const ft = document.getElementById('form-title');
  const gBtnText = document.getElementById('google-btn-text');

  if (tab === 'login') {
    lf.style.display = 'block'; rf.style.display = 'none';
    tl.classList.add('active'); tr.classList.remove('active');
    if (ft) ft.textContent = 'Welcome back';
    if (gBtnText) gBtnText.textContent = 'Sign in with Google';
  } else {
    rf.style.display = 'block'; lf.style.display = 'none';
    tr.classList.add('active'); tl.classList.remove('active');
    if (ft) ft.textContent = 'Create Account';
    if (gBtnText) gBtnText.textContent = 'Sign up with Google';
  }
}

function togglePw(id, btn) {
  const el = document.getElementById(id);
  const isText = el.type === 'password';
  el.type = isText ? 'text' : 'password';
  btn.style.color = isText ? '#2563eb' : '#64748b';
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
