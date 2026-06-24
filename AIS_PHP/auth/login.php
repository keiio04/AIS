<?php
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
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email']= $user['email'];
                $_SESSION['user_role'] = $user['role'];

                // Load active company into session so sidebar is immediately enabled
                $stmtCo = $db->prepare("SELECT active_company_id FROM users WHERE id = ?");
                $stmtCo->bind_param('i', $user['id']);
                $stmtCo->execute();
                $coRow = $stmtCo->get_result()->fetch_assoc();
                if ($coRow && $coRow['active_company_id']) {
                    $_SESSION['active_company_id'] = $coRow['active_company_id'];
                }

                $db->prepare("INSERT INTO activity_logs (user_id, action) VALUES (?, 'Logged in')")->execute([$user['id']]);
                header('Location: ' . BASE_URL . 'pages/dashboard.php');
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
                $hashed = password_hash($pass, PASSWORD_BCRYPT);
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
<title>AccounTech AIS</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap');
* { box-sizing: border-box; }
body { margin: 0; padding: 0; }
@keyframes animateGradient {
  0% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}
@keyframes floatUp {
  0%   { opacity: 0; transform: translateY(40px) scale(0.98); }
  100% { opacity: 1; transform: translateY(0) scale(1); }
}
@keyframes floatOrbit {
  0% { transform: translate(0, 0) rotate(0deg) scale(1); }
  33% { transform: translate(30px, -50px) rotate(120deg) scale(1.1); }
  66% { transform: translate(-20px, 20px) rotate(240deg) scale(0.9); }
  100% { transform: translate(0, 0) rotate(360deg) scale(1); }
}
.auth-root {
  display: flex; align-items: center; justify-content: center;
  min-height: 100vh; font-family: 'Inter', sans-serif;
  background: linear-gradient(-45deg, #a78bfa, #38bdf8, #818cf8, #2dd4bf);
  background-size: 400% 400%; animation: animateGradient 15s ease infinite;
  padding: 2rem; position: relative; overflow: hidden;
}
.orb { position: absolute; border-radius: 50%; filter: blur(80px); opacity: 0.7; z-index: 0; }
.orb-1 { width: 600px; height: 600px; background: #fdf4ff; top: -10%; left: -10%; animation: floatOrbit 20s infinite linear; }
.orb-2 { width: 700px; height: 700px; background: #e0e7ff; bottom: -20%; right: -10%; animation: floatOrbit 25s infinite linear reverse; }
.orb-3 { width: 500px; height: 500px; background: #ccfbf1; top: 30%; left: 40%; animation: floatOrbit 30s infinite linear; }

.auth-glass-container {
  display: flex; flex-direction: column; width: 100%; max-width: 1050px; min-height: 620px;
  background: rgba(255, 255, 255, 0.3); backdrop-filter: blur(40px); -webkit-backdrop-filter: blur(40px);
  border: 1px solid rgba(255, 255, 255, 0.6); border-radius: 36px;
  box-shadow: 0 30px 60px rgba(0, 0, 0, 0.1), inset 0 1px 0 rgba(255,255,255,0.8);
  overflow: hidden; position: relative; z-index: 10;
  animation: floatUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
}
@media (min-width: 900px) { .auth-glass-container { flex-direction: row; } }

.auth-left {
  flex: 1; padding: 4rem; display: flex; flex-direction: column; justify-content: center; align-items: flex-start;
  background: linear-gradient(135deg, rgba(255,255,255,0.6) 0%, rgba(255,255,255,0.2) 100%);
  border-right: 1px solid rgba(255,255,255,0.4); position: relative;
}
@media (max-width: 899px) { .auth-left { display: none; } }
.auth-brand-icon {
  width: 80px; height: 80px; border-radius: 24px; background: #ffffff;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 12px 30px rgba(99,102,241,0.15), inset 0 2px 4px rgba(255,255,255,1);
  margin-bottom: 2rem; color: #6366f1;
}
.auth-left h1 {
  font-family: 'Outfit', sans-serif; font-size: 2.75rem; font-weight: 800; color: #0f172a; margin: 0 0 1rem 0;
  line-height: 1.1; letter-spacing: -0.03em;
}
.auth-left p { font-size: 1.05rem; color: #334155; margin: 0; line-height: 1.6; font-weight: 500; }
.auth-left-pill {
  display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem;
  background: rgba(255,255,255,0.5); border: 1px solid rgba(255,255,255,0.8);
  border-radius: 50px; font-size: 0.85rem; font-weight: 600; color: #4f46e5;
  margin-top: auto; box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.auth-right {
  flex: 1.1; padding: 3rem 2rem; display: flex; flex-direction: column; justify-content: center;
  background: rgba(255,255,255,0.65);
}
@media (min-width: 900px) { .auth-right { padding: 4rem; } }
.auth-right-inner { width: 100%; max-width: 400px; margin: 0 auto; }

.auth-right-title { font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 800; color: #0f172a; letter-spacing: -0.03em; margin: 0 0 0.5rem 0; }
.auth-right-sub { font-size: 0.95rem; color: #475569; margin: 0 0 2rem 0; }

.auth-tabs { display: flex; background: rgba(255,255,255,0.5); border: 1px solid rgba(255,255,255,0.8); border-radius: 14px; padding: 6px; margin-bottom: 2rem; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); }
.auth-tab-btn { flex: 1; padding: 0.7rem; border: none; border-radius: 10px; font-family: 'Inter', sans-serif; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; background: transparent; color: #64748b; }
.auth-tab-btn.active { background: #ffffff; color: #0f172a; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }

.auth-field { margin-bottom: 1.25rem; }
.auth-field-label { display: block; font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 0.5rem; }
.auth-field-input {
  width: 100%; padding: 0.8rem 1.25rem; border: 1px solid rgba(255,255,255,0.8); border-radius: 12px;
  background: rgba(255,255,255,0.5); color: #0f172a; font-family: 'Inter', sans-serif; font-size: 0.95rem; outline: none; transition: all 0.2s;
}
.auth-field-input:focus { background: #ffffff; border-color: #818cf8; box-shadow: 0 0 0 4px rgba(129,140,248,0.15); }
.auth-field-pw { position: relative; }
.auth-field-pw .auth-field-input { padding-right: 3rem; }
.auth-pw-toggle { position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #94a3b8; }

.auth-submit-btn {
  width: 100%; padding: 0.9rem; background: linear-gradient(135deg, #6366f1, #4f46e5); color: #ffffff; border: none; border-radius: 12px;
  font-family: 'Outfit', sans-serif; font-size: 1.05rem; font-weight: 700; cursor: pointer; box-shadow: 0 8px 20px rgba(99,102,241,0.3); transition: all 0.2s;
}
.auth-submit-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(99,102,241,0.4); }
.auth-alert { display: flex; align-items: center; gap: 0.5rem; padding: 0.85rem 1rem; border-radius: 12px; font-size: 0.9rem; font-weight: 500; margin-bottom: 1.5rem; }
.auth-alert.error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
.auth-alert.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
.auth-strength { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem; }
.auth-strength-bar { flex: 1; height: 4px; background: rgba(0,0,0,0.05); border-radius: 4px; overflow: hidden; }
.auth-strength-fill { height: 100%; border-radius: 4px; transition: width 0.3s; }
</style>
</head>
<body>
<div class="auth-root">
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <div class="orb orb-3"></div>
  <div class="auth-glass-container">
    
    <div class="auth-left">
      <div class="auth-brand-icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg>
      </div>
      <h1>AccounTech<br/>AIS Platform</h1>
      <p>Empowering Laguna State Polytechnic University with modern, reliable, and secure accounting tools.</p>
      <div class="auth-left-pill">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        San Pablo Campus Edition
      </div>
    </div>

    <div class="auth-right">
      <div class="auth-right-inner">
        <h2 class="auth-right-title">Welcome back</h2>
        <p class="auth-right-sub">Please enter your details to sign in.</p>

        <div class="auth-tabs">
          <button class="auth-tab-btn <?= $mode==='login'?'active':'' ?>" onclick="document.getElementById('lf').style.display='block';document.getElementById('rf').style.display='none';this.classList.add('active');this.nextElementSibling.classList.remove('active');">Sign In</button>
          <button class="auth-tab-btn <?= $mode==='register'?'active':'' ?>" onclick="document.getElementById('rf').style.display='block';document.getElementById('lf').style.display='none';this.classList.add('active');this.previousElementSibling.classList.remove('active');">Create Account</button>
        </div>

        <?php if ($error): ?><div class="auth-alert error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="auth-alert success">✓ <?= htmlspecialchars($success) ?></div><?php endif; ?>

        <!-- LOGIN FORM -->
        <form method="POST" id="lf" style="display:<?= $mode==='login'?'block':'none' ?>">
          <input type="hidden" name="action" value="login">
          <div class="auth-field">
            <label class="auth-field-label">Email Address</label>
            <input type="email" name="email" class="auth-field-input" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email']??'') ?>" required>
          </div>
          <div class="auth-field">
            <label class="auth-field-label">Password</label>
            <div class="auth-field-pw">
              <input type="password" name="password" id="lpw" class="auth-field-input" placeholder="••••••••" required>
              <button type="button" class="auth-pw-toggle" onclick="t('lpw')">👁</button>
            </div>
          </div>
          <button type="submit" class="auth-submit-btn">Sign In →</button>
        </form>

        <!-- REGISTER FORM -->
        <form method="POST" id="rf" style="display:<?= $mode==='register'?'block':'none' ?>">
          <input type="hidden" name="action" value="register">
          <div class="auth-field">
            <label class="auth-field-label">Full Name</label>
            <input type="text" name="name" class="auth-field-input" placeholder="Juan dela Cruz" value="<?= htmlspecialchars($_POST['name']??'') ?>" required>
          </div>
          <div class="auth-field">
            <label class="auth-field-label">Email Address</label>
            <input type="email" name="email" class="auth-field-input" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email']??'') ?>" required>
          </div>
          <div class="auth-field">
            <label class="auth-field-label">Password</label>
            <div class="auth-field-pw">
              <input type="password" name="password" id="rpw" class="auth-field-input" placeholder="Min 6 chars" oninput="cs(this.value)" required>
              <button type="button" class="auth-pw-toggle" onclick="t('rpw')">👁</button>
            </div>
            <div class="auth-strength"><div class="auth-strength-bar"><div class="auth-strength-fill" id="pf"></div></div></div>
          </div>
          <div class="auth-field">
            <label class="auth-field-label">Confirm Password</label>
            <div class="auth-field-pw">
              <input type="password" name="confirm_pass" id="cpw" class="auth-field-input" required>
              <button type="button" class="auth-pw-toggle" onclick="t('cpw')">👁</button>
            </div>
          </div>
          <button type="submit" class="auth-submit-btn">Create Account →</button>
        </form>

      </div>
    </div>
  </div>
</div>
<script>
function t(id){ const e = document.getElementById(id); e.type = e.type === 'password' ? 'text' : 'password'; }
function cs(pw){
  let sc = 0; if(pw.length>=6)sc++; if(pw.length>=10)sc++; if(/[A-Z]/.test(pw))sc++; if(/[0-9]/.test(pw))sc++; if(/[^a-zA-Z0-9]/.test(pw))sc++;
  const f = document.getElementById('pf');
  f.style.width = ((sc/5)*100)+'%';
  f.style.background = sc<=1 ? '#ef4444' : sc<=3 ? '#f59e0b' : '#10b981';
}
</script>
</body>
</html>
