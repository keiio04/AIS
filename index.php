<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isLoggedIn = !empty($_SESSION['user_id']);
$targetUrl = $isLoggedIn
    ? BASE_URL . 'pages/dashboard.php'
    : BASE_URL . 'auth/login.php';
$registerUrl = $isLoggedIn
    ? BASE_URL . 'pages/dashboard.php'
    : BASE_URL . 'auth/login.php?mode=register';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TALA-AIS – Accounting Simulation System</title>
<meta name="description" content="TALA-AIS: Smart, automated accounting information system for Laguna State Polytechnic University. Setup accounts, record specialized journals, and generate instant financial statements.">

<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800;900&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">

<!-- Lucide Icons -->
<script src="https://unpkg.com/lucide@latest"></script>

<style>
/* ============================================================
   DESIGN TOKENS & RESET — DARK ROYAL / ELECTRIC BLUE THEME
   (Matching Reference Design)
   ============================================================ */
*, *::before, *::after {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

:root {
  --bg-deep: #060a12;
  --bg-card: rgba(18, 28, 51, 0.75);
  --bg-card-hover: rgba(26, 40, 74, 0.85);
  --border-card: rgba(59, 130, 246, 0.22);
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

html {
  scroll-behavior: smooth;
}

body {
  font-family: 'Inter', sans-serif;
  background-color: var(--bg-deep);
  background-image: 
    radial-gradient(circle at 50% 0%, rgba(30, 58, 138, 0.45) 0%, transparent 60%),
    radial-gradient(circle at 80% 60%, rgba(37, 99, 235, 0.25) 0%, transparent 50%),
    radial-gradient(circle at 20% 90%, rgba(14, 165, 233, 0.2) 0%, transparent 45%),
    linear-gradient(180deg, #070b14 0%, #0b1329 45%, #0f214d 80%, #173275 100%);
  color: var(--text-white);
  line-height: 1.6;
  min-height: 100vh;
  overflow-x: hidden;
  position: relative;
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

/* Realistic Accounting Hero Background Layer */
.hero-bg-layer {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 680px;
  background-image: 
    linear-gradient(180deg, rgba(6, 10, 18, 0.38) 0%, rgba(7, 11, 20, 0.28) 45%, rgba(11, 19, 41, 0.88) 88%, #0b1329 100%),
    radial-gradient(circle at 50% 35%, rgba(0, 112, 243, 0.12) 0%, rgba(6, 10, 18, 0.5) 80%),
    url('<?= BASE_URL ?>assets/images/hero-accounting-bg.jpg');
  background-size: cover;
  background-position: center 30%;
  background-repeat: no-repeat;
  pointer-events: none;
  z-index: 1;
  opacity: 0.65;
  mask-image: linear-gradient(180deg, rgba(0,0,0,1) 0%, rgba(0,0,0,0.92) 72%, rgba(0,0,0,0) 100%);
  -webkit-mask-image: linear-gradient(180deg, rgba(0,0,0,1) 0%, rgba(0,0,0,0.92) 72%, rgba(0,0,0,0) 100%);
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

.container {
  width: 100%;
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 1.5rem;
  position: relative;
  z-index: 2;
}

/* ============================================================
   NAVBAR
   ============================================================ */
.navbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1.5rem 0;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  text-decoration: none;
}

.brand-icon {
  width: 44px;
  height: 44px;
  background: linear-gradient(135deg, #0ea5e9, #3b82f6, #1d4ed8);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 18px rgba(59, 130, 246, 0.6), inset 0 2px 4px rgba(255,255,255,0.4);
  color: #ffffff;
  flex-shrink: 0;
  animation: starFloat 3.5s ease-in-out infinite, starGlow 2.5s ease-in-out infinite alternate;
}

.brand-icon svg {
  animation: starTwinkle 3s ease-in-out infinite alternate;
}

@keyframes starFloat {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-4px); }
}

@keyframes starGlow {
  0% { box-shadow: 0 4px 15px rgba(59, 130, 246, 0.5); }
  100% { box-shadow: 0 8px 30px rgba(59, 130, 246, 0.85), 0 0 20px rgba(14, 165, 233, 0.6); }
}

@keyframes starTwinkle {
  0% { transform: scale(0.95); }
  50% { transform: scale(1.1) rotate(4deg); }
  100% { transform: scale(1); }
}

.brand-text {
  display: flex;
  flex-direction: column;
}

.brand-name {
  font-family: 'Outfit', sans-serif;
  font-size: 1.35rem;
  font-weight: 800;
  color: #ffffff;
  line-height: 1.1;
  letter-spacing: -0.02em;
}

.brand-sub {
  font-size: 0.7rem;
  color: #60a5fa;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.nav-links {
  display: flex;
  align-items: center;
  gap: 2rem;
  list-style: none;
}

.nav-link {
  color: #94a3b8;
  font-size: 0.9rem;
  font-weight: 600;
  text-decoration: none;
  transition: color 0.2s;
}

.nav-link:hover {
  color: #ffffff;
}

.nav-actions {
  display: flex;
  align-items: center;
  gap: 0.85rem;
}

/* ============================================================
   PILL BUTTONS (Matching Reference)
   ============================================================ */
.btn-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.75rem 1.75rem;
  border-radius: 9999px;
  font-size: 0.925rem;
  font-weight: 700;
  text-decoration: none;
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
  cursor: pointer;
  border: none;
  font-family: 'Inter', sans-serif;
}

.btn-pill-blue {
  background: linear-gradient(135deg, #0070f3, #0051cc);
  color: #ffffff;
  box-shadow: 0 4px 20px rgba(0, 112, 243, 0.45);
}

.btn-pill-blue:hover {
  background: linear-gradient(135deg, #1a82ff, #0060e6);
  box-shadow: 0 8px 30px rgba(0, 112, 243, 0.7);
  transform: translateY(-2px);
}

.btn-pill-dark {
  background: rgba(255, 255, 255, 0.07);
  color: #ffffff;
  border: 1px solid rgba(255, 255, 255, 0.16);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.btn-pill-dark:hover {
  background: rgba(30, 41, 59, 0.95);
  border-color: rgba(96, 165, 250, 0.5);
  color: #ffffff;
  transform: translateY(-2px);
}

.hero-signin-mobile {
  display: none !important;
}

.btn-pill-sm {
  padding: 0.5rem 1.15rem;
  font-size: 0.825rem;
}

.btn-pill-lg {
  padding: 0.9rem 2.25rem;
  font-size: 1rem;
}

/* ============================================================
   HERO SECTION
   ============================================================ */
.hero {
  text-align: center;
  padding: 4.5rem 0 3rem;
  position: relative;
}

/* Top divider pill badge */
.top-divider-pill {
  display: inline-flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 2rem;
}

.divider-line {
  width: 60px;
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3));
}

.divider-line.right {
  background: linear-gradient(90deg, rgba(255, 255, 255, 0.3), transparent);
}

.divider-badge-content {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.825rem;
  font-weight: 600;
  color: #cbd5e1;
  background: rgba(255, 255, 255, 0.05);
  padding: 0.35rem 1rem;
  border-radius: 9999px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  backdrop-filter: blur(10px);
}

.badge-thumb-icon {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #ffffff;
  color: #0f172a;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
}

/* Big Hero Heading - Modern High-End Typography */
.hero-heading {
  font-family: 'Plus Jakarta Sans', 'Outfit', sans-serif;
  font-size: clamp(2.35rem, 5.2vw, 4.15rem);
  font-weight: 800;
  line-height: 1.16;
  letter-spacing: -0.035em;
  margin-bottom: 1.25rem;
  color: #ffffff;
}

.hero-title-main {
  display: inline-block;
  background: linear-gradient(180deg, #ffffff 40%, #e2e8f0 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  filter: drop-shadow(0 4px 20px rgba(0, 0, 0, 0.9));
}

.hero-title-highlight {
  display: inline-block;
  background: linear-gradient(135deg, #60a5fa 0%, #38bdf8 50%, #93c5fd 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  filter: drop-shadow(0 4px 24px rgba(37, 99, 235, 0.7));
}

/* Embedded Circular Chips in Title (Matching Reference) */
.title-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 46px;
  height: 46px;
  background: radial-gradient(circle at 35% 35%, #ffffff 0%, #d1d5db 70%, #9ca3af 100%);
  border-radius: 50%;
  vertical-align: middle;
  margin: 0 0.35rem;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4), inset 0 2px 4px rgba(255, 255, 255, 0.8), inset 0 -3px 6px rgba(0, 0, 0, 0.3);
  font-size: 1.3rem;
  transform: translateY(-4px);
  animation: chipFloat 3.5s ease-in-out infinite;
}

.title-chip.gem {
  background: radial-gradient(circle at 35% 35%, #60a5fa 0%, #2563eb 60%, #1e3a8a 100%);
  box-shadow: 0 8px 24px rgba(37, 99, 235, 0.6), inset 0 2px 4px rgba(255, 255, 255, 0.7), inset 0 -3px 6px rgba(0, 0, 0, 0.4);
  animation-delay: 1.75s;
}

@keyframes chipFloat {
  0%, 100% { transform: translateY(-4px) rotate(0deg); }
  50% { transform: translateY(-9px) rotate(5deg); }
}

.hero-subtext {
  font-family: 'Inter', sans-serif;
  font-size: clamp(0.95rem, 1.8vw, 1.08rem);
  font-weight: 400;
  color: #cbd5e1;
  max-width: 640px;
  margin: 0 auto 2.25rem;
  line-height: 1.65;
  letter-spacing: -0.01em;
  text-shadow: 0 2px 16px rgba(0, 0, 0, 0.95), 0 1px 4px rgba(0, 0, 0, 0.8);
}

.hero-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1.25rem;
  flex-wrap: wrap;
}

/* ============================================================
   3D STEPS / WORKFLOW GUIDE CARDS
   ============================================================ */
.cards-section {
  padding: 2rem 0 4rem;
  position: relative;
}

.cards-container-wrapper {
  position: relative;
}

.cards-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1.5rem;
  max-width: 980px;
  margin: 0 auto 2rem;
}

/* Card Outer Container */
.feature-card {
  background: linear-gradient(180deg, rgba(20, 32, 59, 0.85) 0%, rgba(13, 21, 41, 0.95) 100%);
  border: 1px solid rgba(59, 130, 246, 0.25);
  border-radius: 20px;
  padding: 1.5rem 1.35rem 1.6rem;
  text-align: center;
  position: relative;
  overflow: visible;
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  box-shadow: 0 16px 36px rgba(0, 0, 0, 0.35), inset 0 1px 1px rgba(255, 255, 255, 0.1);
  transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
  display: flex;
  flex-direction: column;
  align-items: center;
}

.feature-card:hover {
  transform: translateY(-6px);
  border-color: rgba(96, 165, 250, 0.6);
  box-shadow: 0 20px 48px rgba(0, 0, 0, 0.45), 0 0 30px rgba(37, 99, 235, 0.28);
}

/* Visual Stage on top of card */
.card-visual-stage {
  width: 100%;
  height: 155px;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  margin-bottom: 0.85rem;
  transform: scale(0.85);
  transform-origin: center center;
}

/* ───────────────────────────────────────────
   3D Pure-CSS Illustrations (Matching Reference)
   ─────────────────────────────────────────── */

/* 1. CREDIT CARD & FLOATING COINS (Step 01 - Trusted Setup) */
.scene-card-coins {
  position: relative;
  width: 240px;
  height: 160px;
  perspective: 800px;
}

.bank-card-3d {
  position: absolute;
  top: 25px;
  left: 20px;
  width: 200px;
  height: 125px;
  background: linear-gradient(135deg, #7da5ea 0%, #4d7ed4 50%, #2f5eb5 100%);
  border-radius: 16px;
  padding: 1rem;
  transform: rotateX(25deg) rotateY(-18deg) rotateZ(6deg);
  box-shadow: -15px 25px 40px rgba(0, 0, 0, 0.5), 0 0 20px rgba(77, 126, 212, 0.3);
  border: 1px solid rgba(255, 255, 255, 0.4);
  transition: transform 0.4s ease;
}

.feature-card:hover .bank-card-3d {
  transform: rotateX(15deg) rotateY(-10deg) rotateZ(3deg) translateY(-8px);
}

.chip-smart {
  width: 28px;
  height: 22px;
  background: linear-gradient(135deg, #fcd34d, #f59e0b);
  border-radius: 5px;
  border: 1px solid rgba(255, 255, 255, 0.6);
  margin-left: auto;
  margin-top: 15px;
}

.coin-3d {
  position: absolute;
  width: 68px;
  height: 68px;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 35%, #ffffff 0%, #e2e8f0 60%, #94a3b8 100%);
  border: 4px solid #cbd5e1;
  box-shadow: 0 15px 30px rgba(0, 0, 0, 0.45), inset 0 2px 4px rgba(255, 255, 255, 0.9);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #1e3a8a;
  font-weight: 800;
  font-size: 1.2rem;
  transition: transform 0.4s ease;
}

.coin-top {
  top: -15px;
  left: 20px;
  transform: rotateX(25deg) rotateY(-15deg);
  animation: coinFloat 3s ease-in-out infinite;
}

.coin-base {
  top: 35px;
  left: 15px;
  width: 72px;
  height: 72px;
  background: radial-gradient(circle at 35% 35%, #86efac 0%, #22c55e 60%, #15803d 100%);
  border-color: #4ade80;
  transform: rotateX(35deg) rotateY(-15deg);
}

@keyframes coinFloat {
  0%, 100% { transform: rotateX(25deg) rotateY(-15deg) translateY(0); }
  50% { transform: rotateX(25deg) rotateY(-15deg) translateY(-10px); }
}

/* 2. OPEN VAULT / CHEST & CARDS (Step 02 - Smart Banking/Journals) */
.scene-vault-card {
  position: relative;
  width: 240px;
  height: 170px;
  perspective: 800px;
}

.vault-body {
  position: absolute;
  bottom: 0px;
  left: 30px;
  width: 180px;
  height: 100px;
  background: linear-gradient(180deg, #d1dbe8 0%, #9aaec7 60%, #697f9c 100%);
  border-radius: 20px;
  border: 3px solid rgba(255, 255, 255, 0.7);
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5), inset 0 4px 8px rgba(255, 255, 255, 0.8);
}

.vault-lid {
  position: absolute;
  top: -20px;
  left: 45px;
  width: 150px;
  height: 45px;
  background: linear-gradient(180deg, #e8edf5 0%, #b8c7db 100%);
  border-radius: 16px 16px 8px 8px;
  border: 3px solid #ffffff;
  transform: rotateX(-40deg);
  box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
}

.vault-card-emerging {
  position: absolute;
  top: 5px;
  left: 30px;
  width: 140px;
  height: 90px;
  background: linear-gradient(135deg, #4f87e8, #2563eb);
  border-radius: 12px;
  border: 1px solid rgba(255, 255, 255, 0.6);
  transform: rotate(-15deg) rotateY(10deg);
  box-shadow: 0 10px 25px rgba(37, 99, 235, 0.4);
  padding: 0.65rem;
  transition: transform 0.4s ease;
}

.feature-card:hover .vault-card-emerging {
  transform: rotate(-12deg) translateY(-14px) scale(1.05);
}

.floating-green-coin {
  position: absolute;
  top: 35px;
  right: 25px;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 35%, #86efac, #16a34a);
  border: 2px solid #bbf7d0;
  box-shadow: 0 8px 20px rgba(22, 163, 74, 0.5);
  animation: coinFloat 2.8s ease-in-out infinite alternate;
}

/* 3. LEDGER BALANCE BOOK & SCALES (Step 03) */
.scene-ledger-balance {
  position: relative;
  width: 240px;
  height: 160px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.ledger-book-3d {
  width: 170px;
  height: 110px;
  background: linear-gradient(135deg, #1e3a8a, #2563eb);
  border-radius: 14px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5), 0 0 25px rgba(37, 99, 235, 0.35);
  transform: rotateX(20deg) rotateY(-10deg);
  padding: 1rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  color: white;
}

.balance-pill-float {
  position: absolute;
  top: 15px;
  right: 15px;
  background: #10b981;
  color: white;
  font-size: 0.75rem;
  font-weight: 800;
  padding: 6px 14px;
  border-radius: 99px;
  box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
  display: flex;
  align-items: center;
  gap: 5px;
  animation: coinFloat 3s ease-in-out infinite;
}

/* 4. FINANCIAL STATEMENTS & PDF EXPORT (Step 04) */
.scene-statements-doc {
  position: relative;
  width: 240px;
  height: 160px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.statement-doc-sheet {
  width: 160px;
  height: 120px;
  background: #ffffff;
  border-radius: 12px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
  padding: 0.75rem 1rem;
  transform: rotateX(15deg) rotateY(10deg);
  color: #0f172a;
  text-align: left;
}

.pdf-export-float-tag {
  position: absolute;
  bottom: 10px;
  right: 20px;
  background: linear-gradient(135deg, #0ea5e9, #2563eb);
  color: white;
  padding: 6px 14px;
  border-radius: 99px;
  font-size: 0.75rem;
  font-weight: 800;
  box-shadow: 0 8px 20px rgba(37, 99, 235, 0.5);
  display: flex;
  align-items: center;
  gap: 5px;
}

/* Card Content Typography */
.feature-card-title {
  font-family: 'Plus Jakarta Sans', 'Outfit', sans-serif;
  font-size: 1.28rem;
  font-weight: 700;
  color: #ffffff;
  margin-bottom: 0.45rem;
  letter-spacing: -0.015em;
}

.feature-card-desc {
  font-size: 0.85rem;
  color: #94a3b8;
  line-height: 1.55;
  max-width: 360px;
}

.card-step-num {
  font-size: 0.7rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #60a5fa;
  margin-bottom: 0.25rem;
}

/* Carousel Control Tabs (Allows viewing Step 1/2 or Step 3/4) */
.steps-nav-tabs {
  display: flex;
  justify-content: center;
  gap: 0.75rem;
  margin-top: 1rem;
}

.step-tab-btn {
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.15);
  color: #cbd5e1;
  padding: 0.5rem 1.25rem;
  border-radius: 99px;
  font-size: 0.825rem;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s ease;
}

.step-tab-btn.active, .step-tab-btn:hover {
  background: #2563eb;
  color: #ffffff;
  border-color: #3b82f6;
  box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);
}

/* ============================================================
   SECONDARY FEATURES GRID
   ============================================================ */
.features-section {
  padding: 4rem 0;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
}

.section-header {
  text-align: center;
  margin-bottom: 3.5rem;
}

.section-tag {
  font-size: 0.8rem;
  font-weight: 800;
  color: #60a5fa;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-bottom: 0.5rem;
}

.section-title {
  font-family: 'Outfit', sans-serif;
  font-size: clamp(2rem, 4vw, 2.75rem);
  font-weight: 800;
  color: #ffffff;
  letter-spacing: -0.02em;
}

.features-grid-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 1.25rem;
}

.feature-box {
  background: rgba(15, 23, 42, 0.6);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 20px;
  padding: 1.75rem 1.5rem;
  transition: all 0.2s ease;
}

.feature-box:hover {
  background: rgba(30, 41, 69, 0.8);
  border-color: rgba(96, 165, 250, 0.4);
  transform: translateY(-4px);
}

.feature-box-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: rgba(59, 130, 246, 0.15);
  border: 1px solid rgba(59, 130, 246, 0.3);
  color: #60a5fa;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1rem;
}

.feature-box h4 {
  font-size: 1.1rem;
  font-weight: 700;
  color: #ffffff;
  margin-bottom: 0.35rem;
}

.feature-box p {
  font-size: 0.85rem;
  color: #94a3b8;
  line-height: 1.6;
}

/* ============================================================
   CALL TO ACTION BANNER
   ============================================================ */
.cta-section {
  padding: 5rem 0 6rem;
  text-align: center;
}

.cta-box {
  background: linear-gradient(135deg, rgba(30, 58, 138, 0.8) 0%, rgba(37, 99, 235, 0.6) 100%);
  border: 1px solid rgba(96, 165, 250, 0.4);
  border-radius: 32px;
  padding: 4rem 2rem;
  position: relative;
  overflow: hidden;
  box-shadow: 0 25px 60px rgba(0, 0, 0, 0.5), 0 0 40px rgba(37, 99, 235, 0.3);
}

.cta-box h2 {
  font-family: 'Outfit', sans-serif;
  font-size: clamp(2rem, 4.5vw, 3rem);
  font-weight: 800;
  color: #ffffff;
  margin-bottom: 0.85rem;
  letter-spacing: -0.02em;
}

.cta-box p {
  font-size: 1.05rem;
  color: #cbd5e1;
  max-width: 580px;
  margin: 0 auto 2.25rem;
}

/* ============================================================
   FOOTER
   ============================================================ */
.footer {
  padding: 2.5rem 0;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  font-size: 0.85rem;
  color: #64748b;
}

.footer-links {
  display: flex;
  gap: 1.75rem;
}

.footer-link {
  color: #94a3b8;
  text-decoration: none;
  font-weight: 600;
  transition: color 0.2s;
}

.footer-link:hover {
  color: #ffffff;
}

/* ============================================================
   RESPONSIVE DESIGN (Flawless Mobile, Tablet & Desktop)
   ============================================================ */
@media (max-width: 992px) {
  .cards-grid {
    grid-template-columns: 1fr;
    gap: 1.5rem;
  }
  .features-grid-row {
    grid-template-columns: repeat(2, 1fr);
  }
  .nav-links {
    display: none;
  }
}

@media (max-width: 640px) {
  .container {
    padding: 0 1rem;
  }

  /* Compact Clean Mobile Navbar */
  .navbar {
    padding: 0.85rem 0;
  }
  .brand {
    gap: 0.6rem;
  }
  .brand-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
  }
  .brand-icon svg {
    width: 18px;
    height: 18px;
  }
  .brand-name {
    font-size: 1.1rem;
  }
  .brand-sub {
    font-size: 0.62rem;
  }
  .nav-actions {
    gap: 0.4rem;
  }
  .nav-actions .btn-pill {
    padding: 0.45rem 0.85rem;
    font-size: 0.78rem;
    white-space: nowrap;
    border-radius: 9999px;
  }
  .nav-actions .btn-pill i, .nav-actions .btn-pill svg {
    width: 13px;
    height: 13px;
  }

  /* Compact Hero Section */
  .hero-bg-layer {
    height: 520px;
    opacity: 0.55;
  }
  .hero {
    padding: 2.25rem 0 1.5rem;
  }
  .top-divider-pill {
    margin-bottom: 1.25rem;
    gap: 0;
  }
  .divider-line {
    display: none;
  }
  .divider-badge-content {
    font-size: 0.72rem;
    padding: 0.3rem 0.85rem;
    gap: 0.4rem;
  }
  .badge-thumb-icon {
    width: 17px;
    height: 17px;
    font-size: 0.62rem;
  }

  /* Mobile Title & Chips */
  .hero-heading {
    font-size: 2.1rem;
    line-height: 1.15;
    margin-bottom: 1rem;
    letter-spacing: -0.025em;
  }
  .title-chip {
    width: 32px;
    height: 32px;
    font-size: 0.95rem;
    margin: 0 0.15rem;
    transform: translateY(-2px);
  }
  .hero-subtext {
    font-size: 0.875rem;
    line-height: 1.55;
    margin-bottom: 1.75rem;
    padding: 0 0.25rem;
  }
  .hero-actions {
    gap: 0.75rem;
    width: 100%;
  }
  .hero-actions .btn-pill {
    flex: 1;
    min-width: 140px;
    padding: 0.75rem 1rem;
    font-size: 0.9rem;
    justify-content: center;
  }
  .hero-signin-mobile {
    display: inline-flex !important;
  }

  /* COMPACT & ORGANIZED MOBILE 3D GUIDE CARDS */
  .cards-section {
    padding: 1.25rem 0 2.5rem;
  }
  .workflow-subtext {
    display: none !important;
  }
  .cards-grid {
    grid-template-columns: 1fr;
    gap: 0.85rem;
    margin-bottom: 1.15rem;
  }
  .feature-card {
    padding: 0.95rem 0.85rem 1.15rem;
    border-radius: 16px;
  }
  .card-visual-stage {
    height: 85px;
    margin-bottom: 0.2rem;
    transform: scale(0.52);
    transform-origin: center center;
  }
  .card-step-num {
    font-size: 0.62rem;
    margin-bottom: 0.15rem;
  }
  .feature-card-title {
    font-size: 1.08rem;
    margin-bottom: 0.25rem;
  }
  .feature-card-desc {
    font-size: 0.77rem;
    line-height: 1.42;
  }

  /* Features band & CTA on Mobile */
  .features-section {
    padding: 2.5rem 0;
  }
  .section-header {
    margin-bottom: 2rem;
  }
  .section-title {
    font-size: 1.65rem;
  }
  .features-grid-row {
    grid-template-columns: 1fr;
    gap: 0.85rem;
  }
  .feature-box {
    padding: 1.25rem 1rem;
    border-radius: 16px;
  }
  .feature-box h4 {
    font-size: 1rem;
  }
  .feature-box p {
    font-size: 0.8rem;
  }
  .cta-section {
    padding: 2.5rem 0 3.5rem;
  }
  .cta-box {
    padding: 2rem 1.15rem;
    border-radius: 24px;
  }
  .cta-box h2 {
    font-size: 1.55rem;
    margin-bottom: 0.5rem;
  }
  .cta-box p {
    font-size: 0.875rem;
    margin-bottom: 1.5rem;
  }
  .footer {
    padding: 1.75rem 0;
    flex-direction: column;
    gap: 1rem;
    text-align: center;
    font-size: 0.78rem;
  }
}
</style>
</head>
<body>

<div class="ambient-glow-top"></div>
<div class="hero-bg-layer"></div>
<div class="ambient-glow-bottom"></div>

<div class="container">

  <!-- =========================================
       NAVBAR
       ========================================= -->
  <header class="navbar">
    <a href="<?= BASE_URL ?>" class="brand">
      <div class="brand-icon">
        <!-- TALA-AIS Glowing Star -->
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
          fill="#ffffff" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
        </svg>
      </div>
      <div class="brand-text">
        <span class="brand-name">TALA-AIS</span>
        <span class="brand-sub">Accounting System</span>
      </div>
    </a>

    <ul class="nav-links">
      <li><a href="#workflow" class="nav-link">Workflow Guide</a></li>
      <li><a href="#features" class="nav-link">Key Features</a></li>
      <li><a href="<?= BASE_URL ?>auth/login.php" class="nav-link">Sign In</a></li>
    </ul>
  </header>

  <!-- =========================================
       HERO SECTION
       ========================================= -->
  <section class="hero">
    
    <!-- Big Headline with Gradient & Refined Typography -->
    <h1 class="hero-heading">
      <span class="hero-title-main">Learn Accounting</span><br>
      <span class="hero-title-highlight">Through Simlation</span> <span class="title-chip gem">🌍</span>
    </h1>

    <!-- Subtitle -->
    <p class="hero-subtext">
      Learn and practice accounting through an interactive simulation designed to help students understand accounting concepts and experience real-world accounting processes.
    </p>

    <!-- Call to Action Buttons (Matching Reference) -->
    <div class="hero-actions">
      <a href="<?= $registerUrl ?>" class="btn-pill btn-pill-blue btn-pill-lg">
        <i data-lucide="user-plus" style="width: 18px; height: 18px;"></i> Create Account
      </a>
      <a href="<?= $targetUrl ?>" class="btn-pill btn-pill-dark btn-pill-lg hero-signin-mobile">
        <i data-lucide="log-in" style="width: 18px; height: 18px;"></i> Sign In
      </a>
    </div>

  </section>

  <!-- =========================================
       WORKFLOW & GUIDE CARDS
       ========================================= -->
  <section class="cards-section" id="workflow">

    <div style="text-align: center; margin-bottom: 1.5rem;">
      <h2 style="font-family: 'Outfit', sans-serif; font-size: clamp(1.05rem, 1.6vw, 1.35rem); font-weight: 700; color: #ffffff; letter-spacing: -0.015em;">
        Learn TALA in 4 Steps
      </h2>
      <p class="workflow-subtext" style="color: #94a3b8; font-size: 0.875rem; max-width: 500px; margin: 0.4rem auto 0;">
        A simple step-by-step guide from company setup to financial reports.
      </p>
    </div>
    
    <!-- Group 1: Steps 1 & 2 -->
    <div class="cards-grid" id="steps-group-1">
      
      <!-- CARD 1: Company Setup -->
      <div class="feature-card">
        <div class="card-visual-stage">
          <div class="scene-card-coins">
            <div class="bank-card-3d">
              <div style="font-size: 0.65rem; color: #dbeafe; font-weight: 700; text-transform: uppercase;">Entity Setup</div>
              <div style="font-size: 0.95rem; font-weight: 800; color: #ffffff; margin-top: 2px;">Apex Services Corp.</div>
              <div class="chip-smart"></div>
            </div>
            <div class="coin-3d coin-base">✓</div>
            <div class="coin-3d coin-top">₱</div>
          </div>
        </div>
        <div class="card-step-num">Step 01 • Company Configuration</div>
        <h3 class="feature-card-title">Select &amp; Setup Company</h3>
        <p class="feature-card-desc">
          Select or create your business profile, set your tax classification, and configure your accounting period to get started.
        </p>
      </div>

      <!-- CARD 2: Chart of Accounts -->
      <div class="feature-card">
        <div class="card-visual-stage">
          <div class="scene-vault-card">
            <div class="vault-body"></div>
            <div class="vault-lid"></div>
            <div class="vault-card-emerging">
              <div style="font-size: 0.6rem; color: #bfdbfe; font-weight: 700;">CHART OF ACCOUNTS</div>
              <div style="font-size: 0.75rem; font-weight: 800; color: #ffffff; margin-top: 4px;">1-110 Cash on Hand</div>
              <div style="font-size: 0.7rem; font-family: monospace; color: #93c5fd; margin-top: 10px;">₱150,000.00</div>
            </div>
            <div class="floating-green-coin"></div>
          </div>
        </div>
        <div class="card-step-num">Step 02 • Master Records</div>
        <h3 class="feature-card-title">Setup Accounts &amp; Card Lists</h3>
        <p class="feature-card-desc">
          Review your Chart of Accounts and add your customer and supplier records for accurate ledger tracking.
        </p>
      </div>

    </div>

    <!-- Group 2: Steps 3 & 4 -->
    <div class="cards-grid" id="steps-group-2">
      
      <!-- CARD 3: Specialized Journals -->
      <div class="feature-card">
        <div class="card-visual-stage">
          <div class="scene-ledger-balance">
            <div class="ledger-book-3d">
              <div style="font-size: 0.65rem; color: #93c5fd; font-weight: 700;">SPECIALIZED JOURNALS</div>
              <div style="font-size: 0.85rem; font-weight: 800;">General • Sales • Purchases • Cash</div>
              <div style="font-size: 0.65rem; color: #cbd5e1;">Debit = Credit Balanced</div>
            </div>
            <div class="balance-pill-float">
              <i data-lucide="check" style="width: 14px; height: 14px;"></i> Balanced
            </div>
          </div>
        </div>
        <div class="card-step-num">Step 03 • Transaction Recording</div>
        <h3 class="feature-card-title">Record Journal Entries</h3>
        <p class="feature-card-desc">
          Post transactions in General, Sales, Purchases, and Cash Journals with automated debit/credit balancing.
        </p>
      </div>

      <!-- CARD 4: Instant Financial Statements -->
      <div class="feature-card">
        <div class="card-visual-stage">
          <div class="scene-statements-doc">
            <div class="statement-doc-sheet">
              <div style="font-size: 0.7rem; font-weight: 800; border-bottom: 1px solid #e2e8f0; padding-bottom: 2px; margin-bottom: 4px;">Statement of Financial Position</div>
              <div style="font-size: 0.55rem; color: #64748b; display: flex; justify-content: space-between;"><span>Total Assets</span><span style="font-weight:700;">₱482,500</span></div>
              <div style="font-size: 0.55rem; color: #64748b; display: flex; justify-content: space-between; margin-top: 2px;"><span>Liabilities & Equity</span><span style="font-weight:700;">₱482,500</span></div>
            </div>
            <div class="pdf-export-float-tag">
              <i data-lucide="download" style="width: 12px; height: 12px;"></i> Export PDF
            </div>
          </div>
        </div>
        <div class="card-step-num">Step 04 • Financial Reporting</div>
        <h3 class="feature-card-title">Generate Financial Statements</h3>
        <p class="feature-card-desc">
          Instantly view and export your General Ledger, Trial Balance, Income Statement, and Balance Sheet to PDF.
        </p>
      </div>

    </div>

  </section>

  <!-- =========================================
       CORE FEATURES GRID
       ========================================= -->
  <section class="features-section" id="features">
    <div class="section-header">
      <div class="section-tag">Key Features</div>
      <h2 class="section-title">Built for Better Accounting Learning</h2>
    </div>

    <div class="features-grid-row">
      <div class="feature-box">
        <div class="feature-box-icon">
          <i data-lucide="scale" style="width: 22px; height: 22px;"></i>
        </div>
        <h4>Auto Balance Guard</h4>
        <p>Checks and requires debit and credit equality before saving journal entries.</p>
      </div>

      <div class="feature-box">
        <div class="feature-box-icon">
          <i data-lucide="receipt" style="width: 22px; height: 22px;"></i>
        </div>
        <h4>Tax Compliance</h4>
        <p>Automatic computation of VAT and Percentage Tax on transactions.</p>
      </div>

      <div class="feature-box">
        <div class="feature-box-icon">
          <i data-lucide="graduation-cap" style="width: 22px; height: 22px;"></i>
        </div>
        <h4>Instructor Output Review</h4>
        <p>Enables instructors to check, audit, and evaluate student journal entries and reports in real-time.</p>
      </div>

      <div class="feature-box">
        <div class="feature-box-icon">
          <i data-lucide="trash-2" style="width: 22px; height: 22px;"></i>
        </div>
        <h4>Trash Bin &amp; Audit</h4>
        <p>Restore deleted transactions and review user activity logs anytime.</p>
      </div>

      <div class="feature-box">
        <div class="feature-box-icon">
          <i data-lucide="file-check" style="width: 22px; height: 22px;"></i>
        </div>
        <h4>Instant PDF Reports</h4>
        <p>One-click PDF download and printing of financial reports and ledgers.</p>
      </div>
    </div>
  </section>

  <!-- =========================================
       FOOTER
       ========================================= -->
  <footer class="footer">
    <div>
      © <?= date('Y') ?> <strong>TALA-AIS</strong>. Laguna State Polytechnic University. All rights reserved.
    </div>
  </footer>

</div>

<script>
  if (typeof lucide !== 'undefined') {
    lucide.createIcons();
  }
</script>

</body>
</html>