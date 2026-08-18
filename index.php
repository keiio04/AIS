<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$isLoggedIn = !empty($_SESSION['user_id']);
$targetUrl = $isLoggedIn ? BASE_URL . 'pages/dashboard.php' : BASE_URL . 'auth/login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TALA-AIS – Smart Accounting, Made Simple</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
<!-- Lucide Icons -->
<script src="https://unpkg.com/lucide@latest"></script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', sans-serif;
  background-color: #fafbfc;
  color: #1e293b;
  line-height: 1.5;
  overflow-x: hidden;
}

.container {
  max-width: 1100px;
  margin: 0 auto;
  padding: 0 2rem;
}

/* ─────────────────────────────────────────
   NAVBAR
───────────────────────────────────────── */
.navbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.5rem 0;
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.brand-icon {
  width: 40px;
  height: 40px;
  background: #2563eb;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
}

.brand-text {
  display: flex;
  flex-direction: column;
}
.brand-text-name {
  font-family: 'Outfit', sans-serif;
  font-size: 1.25rem;
  font-weight: 800;
  color: #0f172a;
  line-height: 1;
}
.brand-text-sub {
  font-size: 0.75rem;
  color: #64748b;
  font-weight: 500;
}

.help-link {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: #2563eb;
  font-weight: 600;
  font-size: 0.95rem;
  text-decoration: none;
}

/* ─────────────────────────────────────────
   HERO SECTION
───────────────────────────────────────── */
.hero {
  display: flex;
  gap: 3rem;
  padding: 1rem 0;
  align-items: center;
}

.hero-content {
  flex: 1;
}

.hero-subtitle {
  color: #2563eb;
  font-weight: 700;
  font-size: 0.85rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  margin-bottom: 1rem;
}

.hero-title {
  font-family: 'Outfit', sans-serif;
  font-size: 3.2rem;
  font-weight: 800;
  color: #0f172a;
  line-height: 1.1;
  margin-bottom: 1rem;
  letter-spacing: -0.02em;
}
.hero-title .highlight {
  color: #2563eb;
}
.hero-title-line {
  width: 50px;
  height: 4px;
  background: #cbd5e1;
  margin-bottom: 1rem;
}

.hero-desc {
  font-size: 1rem;
  color: #64748b;
  max-width: 400px;
  margin-bottom: 2rem;
}

/* Abstract Icons graphic */
.hero-graphic {
  position: relative;
  width: 100%;
  max-width: 300px;
  height: 250px;
}
.graphic-box {
  position: absolute;
  width: 60px;
  height: 60px;
  background: white;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 10px 30px rgba(0,0,0,0.05);
  border: 1px solid #f1f5f9;
  transform: rotate(45deg);
  transition: transform 0.3s;
}
.graphic-box i {
  transform: rotate(-45deg);
  color: #2563eb;
  width: 24px;
  height: 24px;
}
.gb-1 { top: 30px; left: 110px; }
.gb-2 { top: 80px; left: 60px; }
.gb-3 { top: 80px; left: 160px; }
.gb-4 { top: 130px; left: 110px; }
.hero-graphic::after {
  content: '';
  position: absolute;
  top: 80px;
  left: 200px;
  width: 200px;
  height: 200px;
  background-image: radial-gradient(#cbd5e1 2px, transparent 2px);
  background-size: 16px 16px;
  opacity: 0.5;
  z-index: -1;
}

/* ─────────────────────────────────────────
   TIMELINE SECTION
───────────────────────────────────────── */
.timeline-section {
  flex: 1;
  padding-left: 2rem;
}

.timeline-title {
  font-size: 1.15rem;
  font-weight: 700;
  color: #0f172a;
  margin-bottom: 1.5rem;
}
.timeline-title .highlight {
  color: #2563eb;
}

.timeline {
  position: relative;
  border-left: 2px solid #bfdbfe;
  padding-left: 2.5rem;
  padding-bottom: 1rem;
}

.timeline-item {
  position: relative;
  margin-bottom: 1.75rem;
  display: flex;
  align-items: flex-start;
  gap: 1.25rem;
}
.timeline-item:last-child {
  margin-bottom: 0;
}

.timeline-num {
  position: absolute;
  left: -2.5rem;
  top: 0;
  width: 28px;
  height: 28px;
  background: #2563eb;
  color: white;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.8rem;
  transform: translateX(-50%);
  box-shadow: 0 0 0 4px #fafbfc;
}

.timeline-icon {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.timeline-item:nth-child(1) .timeline-icon { background: #eff6ff; color: #2563eb; }
.timeline-item:nth-child(2) .timeline-icon { background: #f5f3ff; color: #7c3aed; }
.timeline-item:nth-child(3) .timeline-icon { background: #f0fdf4; color: #16a34a; }
.timeline-item:nth-child(4) .timeline-icon { background: #fff7ed; color: #ea580c; }

.timeline-content h3 {
  font-size: 0.95rem;
  font-weight: 700;
  color: #0f172a;
  margin-bottom: 0.2rem;
}
.timeline-content p {
  font-size: 0.85rem;
  color: #64748b;
  line-height: 1.5;
}

/* ─────────────────────────────────────────
   FEATURES SECTION
───────────────────────────────────────── */
.features-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  background: white;
  border-radius: 12px;
  padding: 1.5rem;
  margin: 1.5rem 0;
  box-shadow: 0 4px 20px rgba(0,0,0,0.03);
  gap: 1.5rem;
  border: 1px solid #f1f5f9;
}

.feature-item {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
}
.feature-icon {
  color: #2563eb;
  margin-top: 0.2rem;
}
.feature-text h4 {
  font-size: 0.95rem;
  font-weight: 700;
  color: #0f172a;
  margin-bottom: 0.25rem;
}
.feature-text p {
  font-size: 0.85rem;
  color: #64748b;
  line-height: 1.5;
}

/* ─────────────────────────────────────────
   CTA SECTION
───────────────────────────────────────── */
.cta-box {
  background: #f4f7fc;
  border-radius: 12px;
  padding: 1.5rem 2rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 2rem;
}

.cta-content {
  display: flex;
  align-items: center;
  gap: 1.5rem;
}
.cta-icon {
  width: 48px;
  height: 48px;
  background: white;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #94a3b8;
  box-shadow: 0 4px 10px rgba(0,0,0,0.05);
}
.cta-text h2 {
  font-size: 1.25rem;
  font-weight: 700;
  color: #0f172a;
  margin-bottom: 0.2rem;
}
.cta-text p {
  font-size: 0.95rem;
  color: #64748b;
}

.cta-actions {
  display: flex;
  align-items: center;
  gap: 2rem;
}

.btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  background: #2563eb;
  color: white;
  padding: 0.6rem 1.25rem;
  border-radius: 8px;
  font-weight: 600;
  font-size: 0.9rem;
  text-decoration: none;
  transition: background 0.3s;
}
.btn-primary:hover {
  background: #1d4ed8;
}

.btn-outline {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  background: transparent;
  color: #2563eb;
  padding: 0.6rem 1.25rem;
  border-radius: 8px;
  font-weight: 600;
  font-size: 0.9rem;
  text-decoration: none;
  border: 1.5px solid #bfdbfe;
  transition: all 0.3s;
}
.btn-outline:hover {
  background: #eff6ff;
  border-color: #93c5fd;
}

.link-secondary {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  color: #2563eb;
  font-weight: 600;
  text-decoration: none;
}
.link-secondary:hover {
  text-decoration: underline;
}

/* Responsive */
@media (max-width: 1024px) {
  .hero { flex-direction: column; gap: 3rem; }
  .timeline-section { padding-left: 0; }
  .features-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
  .features-grid { grid-template-columns: 1fr; }
  .cta-box { flex-direction: column; text-align: center; gap: 2rem; }
  .cta-content { flex-direction: column; }
  .cta-actions { flex-direction: column; gap: 1rem; }
  .hero-title { font-size: 3rem; }
}
</style>
</head>
<body>

<div class="container">
  
  <nav class="navbar">
    <div class="brand">
      <div class="brand-icon">
        <!-- Abstract star icon -->
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
          fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
        </svg>
      </div>
      <div class="brand-text">
        <span class="brand-text-name">TALA-AIS</span>
        <span class="brand-text-sub">Accounting System</span>
      </div>
    </div>
    <div style="display: flex; gap: 1rem; align-items: center;">
      <a href="<?= BASE_URL ?>auth/login.php" class="btn-outline">
        <i data-lucide="log-in" style="width: 16px; height: 16px;"></i> Login
      </a>
      <a href="<?= BASE_URL ?>auth/login.php?mode=register" class="btn-primary">
        <i data-lucide="user-plus" style="width: 16px; height: 16px;"></i> Sign Up
      </a>
    </div>
  </nav>

  <div class="hero">
    <div class="hero-content">
      <div class="hero-subtitle">WELCOME TO TALA-AIS</div>
      <h1 class="hero-title">Smart accounting,<br>made <span class="highlight">simple.</span></h1>
      <div class="hero-title-line"></div>
      <p class="hero-desc">TALA-AIS helps you manage your accounting processes efficiently and accurately in just a few simple steps.</p>
      
      <div class="hero-graphic">
        <div class="graphic-box gb-1"><i data-lucide="bar-chart-3" style="width: 32px; height: 32px;"></i></div>
        <div class="graphic-box gb-2"><i data-lucide="calculator" style="width: 32px; height: 32px;"></i></div>
        <div class="graphic-box gb-3"><i data-lucide="building-2" style="width: 32px; height: 32px;"></i></div>
        <div class="graphic-box gb-4"></div>
      </div>
    </div>

    <div class="timeline-section">
      <div class="timeline-title">Get started in <span class="highlight">4 easy steps</span></div>
      
      <div class="timeline">
        <div class="timeline-item">
          <div class="timeline-num">01</div>
          <div class="timeline-icon"><i data-lucide="building-2" style="width: 24px; height: 24px;"></i></div>
          <div class="timeline-content">
            <h3>Select Company</h3>
            <p>Start by selecting or creating a company. This ensures all your records are grouped correctly under the right business entity.</p>
          </div>
        </div>

        <div class="timeline-item">
          <div class="timeline-num">02</div>
          <div class="timeline-icon"><i data-lucide="users" style="width: 24px; height: 24px;"></i></div>
          <div class="timeline-content">
            <h3>Setup Accounts</h3>
            <p>Go to the Chart of Accounts to manage ledger titles. Add customers and suppliers so they are ready for transactions.</p>
          </div>
        </div>

        <div class="timeline-item">
          <div class="timeline-num">03</div>
          <div class="timeline-icon"><i data-lucide="book-open" style="width: 24px; height: 24px;"></i></div>
          <div class="timeline-content">
            <h3>Record Entries</h3>
            <p>Use specialized Journals (Sales, Purchases, Cash Receipts, Disbursements) to accurately record your transactions.</p>
          </div>
        </div>

        <div class="timeline-item">
          <div class="timeline-num">04</div>
          <div class="timeline-icon"><i data-lucide="pie-chart" style="width: 24px; height: 24px;"></i></div>
          <div class="timeline-content">
            <h3>View Reports</h3>
            <p>Let the system do the heavy lifting! Generate Ledger, Trial Balance, and Financial Statements in real-time.</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="features-grid">
    <div class="feature-item">
      <div class="feature-icon"><i data-lucide="shield-check" style="width: 28px; height: 28px;"></i></div>
      <div class="feature-text">
        <h4>Accurate & Reliable</h4>
        <p>Ensure the accuracy and reliability of your financial data.</p>
      </div>
    </div>
    
    <div class="feature-item">
      <div class="feature-icon"><i data-lucide="lock" style="width: 28px; height: 28px;"></i></div>
      <div class="feature-text">
        <h4>Secure Data</h4>
        <p>Your data is protected with industry-standard security.</p>
      </div>
    </div>

    <div class="feature-item">
      <div class="feature-icon"><i data-lucide="line-chart" style="width: 28px; height: 28px;"></i></div>
      <div class="feature-text">
        <h4>Real-time Reports</h4>
        <p>Access up-to-date reports whenever you need them.</p>
      </div>
    </div>

    <div class="feature-item">
      <div class="feature-icon"><i data-lucide="clock" style="width: 28px; height: 28px;"></i></div>
      <div class="feature-text">
        <h4>Save Time</h4>
        <p>Automate tasks and focus on growing your business.</p>
      </div>
    </div>
  </div>

  <div class="cta-box">
    <div class="cta-content">
      <div class="cta-icon">
        <i data-lucide="flag" style="width: 20px; height: 20px;"></i>
      </div>
      <div class="cta-text">
        <h2>Ready to get started?</h2>
        <p>Click the button to proceed to your dashboard.</p>
      </div>
    </div>
    <div class="cta-actions">
      <a href="<?= $targetUrl ?>" class="btn-primary">
        Let's Get Started <i data-lucide="arrow-right" style="width: 18px; height: 18px;"></i>
      </a>
      <a href="<?= BASE_URL ?>" class="link-secondary">
        Go to Homepage <i data-lucide="arrow-right" style="width: 18px; height: 18px;"></i>
      </a>
    </div>
  </div>

</div>

<script>
  lucide.createIcons();
</script>
</body>
</html>
