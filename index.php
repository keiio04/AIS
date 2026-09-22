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
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
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
   (Optimized for iOS, iPadOS, macOS, Android & Windows)
   ============================================================ */
*, *::before, *::after {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  -webkit-tap-highlight-color: transparent;
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
  -webkit-text-size-adjust: 100%;
  text-size-adjust: 100%;
}

body {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  background-color: var(--bg-deep);
  background-image: 
    radial-gradient(circle at 50% 0%, rgba(30, 58, 138, 0.45) 0%, transparent 60%),
    radial-gradient(circle at 80% 60%, rgba(37, 99, 235, 0.25) 0%, transparent 50%),
    radial-gradient(circle at 20% 90%, rgba(14, 165, 233, 0.2) 0%, transparent 45%),
    linear-gradient(180deg, #070b14 0%, #0b1329 45%, #0f214d 80%, #173275 100%);
  color: var(--text-white);
  line-height: 1.6;
  min-height: 100vh;
  min-height: -webkit-fill-available;
  overflow-x: hidden;
  position: relative;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

/* Ambient Radial Glow Overlays */
.ambient-glow-top {
  position: absolute;
  top: -120px;
  left: 50%;
  transform: translateX(-50%);
  width: 900px;
  max-width: 100vw;
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
  padding: 0 max(1.5rem, env(safe-area-inset-right)) 0 max(1.5rem, env(safe-area-inset-left));
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
  touch-action: manipulation;
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
  -webkit-transform: translateZ(0);
  transform: translateZ(0);
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
  font-family: 'Outfit', -apple-system, sans-serif;
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
  touch-action: manipulation;
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
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
  touch-action: manipulation;
  -webkit-appearance: none;
  appearance: none;
}

.btn-pill-blue {
  background: linear-gradient(135deg, #0070f3, #0051cc);
  color: #ffffff;
  box-shadow: 0 4px 20px rgba(0, 112, 243, 0.45);
}

.btn-pill-blue:hover, .btn-pill-blue:active {
  background: linear-gradient(135deg, #1a82ff, #0060e6);
  box-shadow: 0 8px 30px rgba(0, 112, 243, 0.7);
  transform: translateY(-2px);
}

.btn-pill-dark {
  background: rgba(255, 255, 255, 0.08);
  color: #ffffff;
  border: 1px solid rgba(255, 255, 255, 0.18);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.btn-pill-dark:hover, .btn-pill-dark:active {
  background: rgba(30, 41, 59, 0.95);
  border-color: rgba(96, 165, 250, 0.5);
  color: #ffffff;
  transform: translateY(-2px);
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
  -webkit-backdrop-filter: blur(10px);
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
  font-family: 'Plus Jakarta Sans', 'Outfit', -apple-system, sans-serif;
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

/* Embedded Circular Chips in Title */
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
  background: radial-gradient(circle at 35% 35%, #38bdf8 0%, #2563eb 55%, #1d4ed8 100%);
  box-shadow: 0 8px 26px rgba(0, 112, 243, 0.75), inset 0 2px 5px rgba(255, 255, 255, 0.85), inset 0 -3px 6px rgba(0, 0, 0, 0.4);
  animation: starChipGlow 3.5s ease-in-out infinite;
}

@keyframes starChipGlow {
  0%, 100% {
    transform: translateY(-4px) scale(1) rotate(0deg);
    box-shadow: 0 8px 24px rgba(0, 112, 243, 0.65), inset 0 2px 5px rgba(255, 255, 255, 0.85);
  }
  50% {
    transform: translateY(-9px) scale(1.06) rotate(4deg);
    box-shadow: 0 14px 32px rgba(56, 189, 248, 0.85), 0 0 20px rgba(59, 130, 246, 0.6), inset 0 2px 5px rgba(255, 255, 255, 0.85);
  }
}

.hero-subtext {
  font-family: 'Inter', -apple-system, sans-serif;
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
  -webkit-transform: translateZ(0);
  transform: translateZ(0);
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
  -webkit-transform-origin: center center;
}

/* ───────────────────────────────────────────
   3D Pure-CSS Illustrations (Hardware Accelerated)
   ─────────────────────────────────────────── */

/* 1. CREDIT CARD & FLOATING COINS (Step 01 - Trusted Setup) */
.scene-card-coins {
  position: relative;
  width: 240px;
  height: 160px;
  perspective: 800px;
  -webkit-perspective: 800px;
  transform-style: preserve-3d;
  -webkit-transform-style: preserve-3d;
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
  -webkit-transform: rotateX(25deg) rotateY(-18deg) rotateZ(6deg);
  box-shadow: -15px 25px 40px rgba(0, 0, 0, 0.5), 0 0 20px rgba(77, 126, 212, 0.3);
  border: 1px solid rgba(255, 255, 255, 0.4);
  transition: transform 0.4s ease;
  -webkit-backface-visibility: hidden;
  backface-visibility: hidden;
}

.feature-card:hover .bank-card-3d {
  transform: rotateX(15deg) rotateY(-10deg) rotateZ(3deg) translateY(-8px);
  -webkit-transform: rotateX(15deg) rotateY(-10deg) rotateZ(3deg) translateY(-8px);
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
  -webkit-backface-visibility: hidden;
  backface-visibility: hidden;
}

.coin-top {
  top: -15px;
  left: 20px;
  transform: rotateX(25deg) rotateY(-15deg);
  -webkit-transform: rotateX(25deg) rotateY(-15deg);
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
  -webkit-transform: rotateX(35deg) rotateY(-15deg);
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
  -webkit-perspective: 800px;
  transform-style: preserve-3d;
  -webkit-transform-style: preserve-3d;
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
  -webkit-transform: rotateX(-40deg);
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
  -webkit-transform: rotate(-15deg) rotateY(10deg);
  box-shadow: 0 10px 25px rgba(37, 99, 235, 0.4);
  padding: 0.65rem;
  transition: transform 0.4s ease;
}

.feature-card:hover .vault-card-emerging {
  transform: rotate(-12deg) translateY(-14px) scale(1.05);
  -webkit-transform: rotate(-12deg) translateY(-14px) scale(1.05);
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
  perspective: 800px;
  -webkit-perspective: 800px;
  transform-style: preserve-3d;
  -webkit-transform-style: preserve-3d;
}

.ledger-book-3d {
  width: 170px;
  height: 110px;
  background: linear-gradient(135deg, #1e3a8a, #2563eb);
  border-radius: 14px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5), 0 0 25px rgba(37, 99, 235, 0.35);
  transform: rotateX(20deg) rotateY(-10deg);
  -webkit-transform: rotateX(20deg) rotateY(-10deg);
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
  perspective: 800px;
  -webkit-perspective: 800px;
  transform-style: preserve-3d;
  -webkit-transform-style: preserve-3d;
}

.statement-doc-sheet {
  width: 160px;
  height: 120px;
  background: #ffffff;
  border-radius: 12px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
  padding: 0.75rem 1rem;
  transform: rotateX(15deg) rotateY(10deg);
  -webkit-transform: rotateX(15deg) rotateY(10deg);
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
  font-family: 'Plus Jakarta Sans', 'Outfit', -apple-system, sans-serif;
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
  font-family: 'Outfit', -apple-system, sans-serif;
  font-size: clamp(2rem, 4vw, 2.75rem);
  font-weight: 800;
  color: #ffffff;
  letter-spacing: -0.02em;
}

.features-grid-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1.25rem;
}

.feature-box {
  background: rgba(15, 23, 42, 0.6);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 20px;
  padding: 1.75rem 1.5rem;
  transition: all 0.2s ease;
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
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
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
}

.cta-box h2 {
  font-family: 'Outfit', -apple-system, sans-serif;
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
  padding-bottom: max(2.5rem, env(safe-area-inset-bottom));
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
   HERO SHOWCASE MOCKUP & INTERACTIVE DEMO STYLES
   ============================================================ */
.showcase-frame-wrapper {
  position: relative;
  z-index: 10;
}

/* Floating Glassmorphic Badges */
.floating-badge {
  position: absolute;
  background: rgba(15, 23, 42, 0.85);
  border: 1px solid rgba(255, 255, 255, 0.16);
  padding: 0.65rem 1rem;
  border-radius: 16px;
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5), 0 0 20px rgba(59, 130, 246, 0.2);
  display: flex;
  align-items: center;
  gap: 0.75rem;
  z-index: 20;
  pointer-events: auto;
  transition: transform 0.3s ease, box-shadow 0.3s ease;
  animation: badgeFloat 4s ease-in-out infinite alternate;
}

.floating-badge:hover {
  transform: translateY(-5px) scale(1.03);
  box-shadow: 0 20px 45px rgba(0, 0, 0, 0.6), 0 0 30px rgba(59, 130, 246, 0.4);
}

.badge-tl { top: -20px; left: -30px; animation-delay: 0s; }
.badge-tr { top: 15px; right: -30px; animation-delay: 1.2s; }
.badge-bl { bottom: 40px; left: -35px; animation-delay: 2.4s; }
.badge-br { bottom: -20px; right: -25px; animation-delay: 0.8s; }

@keyframes badgeFloat {
  0% { transform: translateY(0); }
  100% { transform: translateY(-8px); }
}

.badge-icon-box {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.badge-title {
  font-size: 0.82rem;
  font-weight: 700;
  color: #ffffff;
  line-height: 1.2;
}

.badge-sub {
  font-size: 0.68rem;
  color: #94a3b8;
  font-weight: 500;
}

/* Laptop / Browser Device Mockup Frame */
.mockup-device {
  background: #0d1527;
  border: 1px solid rgba(96, 165, 250, 0.35);
  border-radius: 20px;
  box-shadow: 0 30px 80px rgba(0, 0, 0, 0.7), 0 0 50px rgba(37, 99, 235, 0.25);
  overflow: hidden;
  text-align: left;
  position: relative;
  transition: border-color 0.3s ease;
}

.mockup-header {
  background: rgba(15, 23, 42, 0.95);
  padding: 0.75rem 1.25rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.mockup-dots {
  display: flex;
  align-items: center;
  gap: 6px;
}

.dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  display: inline-block;
}

.dot-red { background: #ef4444; }
.dot-yellow { background: #f59e0b; }
.dot-green { background: #10b981; }

.mockup-address-bar {
  background: rgba(0, 0, 0, 0.35);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 99px;
  padding: 0.25rem 1rem;
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.72rem;
  color: #94a3b8;
  font-family: monospace;
}

.mockup-status-tag {
  font-size: 0.7rem;
  font-weight: 600;
  color: #34d399;
  display: flex;
  align-items: center;
  gap: 5px;
}

.pulse-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 10px #10b981;
  animation: pulseGreen 1.5s infinite;
}

@keyframes pulseGreen {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.4; transform: scale(0.8); }
}

/* Tabs Bar */
.mockup-tabs {
  display: flex;
  background: rgba(11, 19, 41, 0.85);
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  padding: 0.4rem 0.75rem;
  gap: 0.4rem;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.mockup-tab {
  background: transparent;
  border: 1px solid transparent;
  color: #94a3b8;
  padding: 0.55rem 1rem;
  border-radius: 10px;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
  transition: all 0.2s ease;
  touch-action: manipulation;
}

.mockup-tab:hover {
  color: #ffffff;
  background: rgba(255, 255, 255, 0.05);
}

.mockup-tab.active {
  background: rgba(37, 99, 235, 0.25);
  border-color: rgba(96, 165, 250, 0.45);
  color: #ffffff;
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

/* Demo Tour Callout Banner */
.demo-tour-banner {
  background: linear-gradient(135deg, rgba(30, 58, 138, 0.95), rgba(15, 23, 42, 0.95));
  border-bottom: 1px solid rgba(56, 189, 248, 0.4);
  padding: 0.75rem 1.25rem;
  position: relative;
  z-index: 15;
  animation: slideDown 0.3s ease;
}

@keyframes slideDown {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
}

.demo-step-badge {
  background: #0284c7;
  color: #ffffff;
  font-size: 0.65rem;
  font-weight: 800;
  padding: 2px 7px;
  border-radius: 4px;
  letter-spacing: 0.05em;
}

.tour-progress-track {
  width: 160px;
  height: 4px;
  background: rgba(255, 255, 255, 0.15);
  border-radius: 99px;
  overflow: hidden;
}

.tour-progress-bar {
  height: 100%;
  width: 25%;
  background: #38bdf8;
  transition: width 0.3s ease;
}

.btn-tour-ctrl {
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.15);
  color: white;
  width: 26px;
  height: 26px;
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.2s;
}

.btn-tour-ctrl:hover {
  background: rgba(255, 255, 255, 0.2);
}

/* Screen Body & Views */
.mockup-screen-body {
  padding: 1.5rem;
  min-height: 420px;
  background: #080d1a;
}

.mockup-view {
  display: none;
  animation: fadeInView 0.35s ease;
}

.mockup-view.active {
  display: block;
}

@keyframes fadeInView {
  from { opacity: 0; transform: scale(0.99); }
  to { opacity: 1; transform: scale(1); }
}

.view-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.25rem;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.badge-status-green {
  background: rgba(16, 185, 129, 0.15);
  border: 1px solid rgba(52, 211, 153, 0.4);
  color: #34d399;
  font-size: 0.72rem;
  font-weight: 700;
  padding: 0.3rem 0.75rem;
  border-radius: 99px;
}

/* Metrics Preview Grid */
.preview-metrics-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0.85rem;
  margin-bottom: 1.25rem;
}

.prev-card {
  background: rgba(15, 23, 42, 0.75);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 12px;
  padding: 0.85rem 1rem;
}

.prev-card-lbl {
  font-size: 0.65rem;
  color: #64748b;
  font-weight: 700;
  letter-spacing: 0.05em;
  margin-bottom: 0.25rem;
}

.prev-card-val {
  font-size: 1.25rem;
  font-weight: 800;
  letter-spacing: -0.02em;
}

.prev-card-sub {
  font-size: 0.68rem;
  font-weight: 600;
  margin-top: 0.2rem;
}

/* Charts Preview Grid */
.preview-charts-grid {
  display: grid;
  grid-template-columns: 1.4fr 1fr;
  gap: 1rem;
}

.prev-chart-box {
  background: rgba(15, 23, 42, 0.75);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 12px;
  padding: 1rem 1.15rem;
}

/* CSS Bar Chart Simulation */
.sim-bars-container {
  display: flex;
  align-items: flex-end;
  justify-content: space-around;
  height: 120px;
  padding-top: 0.5rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.sim-bar-group {
  display: flex;
  align-items: flex-end;
  gap: 4px;
  height: 100%;
  position: relative;
  padding-bottom: 18px;
}

.sim-bar {
  width: 18px;
  border-radius: 4px 4px 0 0;
  transition: height 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}

.sim-bar-group span {
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  font-size: 0.68rem;
  color: #64748b;
  font-weight: 600;
}

/* CSS Donut Chart Simulation */
.sim-donut-wrapper {
  display: flex;
  align-items: center;
  gap: 1.25rem;
  height: 120px;
}

.sim-donut {
  width: 90px;
  height: 90px;
  border-radius: 50%;
  background: conic-gradient(#3b82f6 0% 58%, #10b981 58% 82%, #f59e0b 82% 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
  flex-shrink: 0;
}

.sim-donut-hole {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: #0d1527;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  line-height: 1.1;
}

.sim-legend {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  font-size: 0.72rem;
  color: #cbd5e1;
}

.legend-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-right: 4px;
}

/* Tables in Mockup */
.preview-table-wrapper {
  overflow-x: auto;
  border-radius: 12px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background: rgba(15, 23, 42, 0.75);
}

.preview-sim-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.78rem;
}

.preview-sim-table th {
  background: rgba(30, 41, 69, 0.6);
  color: #94a3b8;
  font-size: 0.7rem;
  text-transform: uppercase;
  font-weight: 700;
  letter-spacing: 0.05em;
  padding: 0.7rem 0.85rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.preview-sim-table td {
  padding: 0.65rem 0.85rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.05);
  color: #e2e8f0;
}

.preview-sim-table tr:hover td {
  background: rgba(59, 130, 246, 0.06);
}

.journal-pill {
  font-size: 0.7rem;
  font-weight: 600;
  padding: 0.25rem 0.6rem;
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.06);
  color: #94a3b8;
}

.journal-pill.active {
  background: rgba(59, 130, 246, 0.2);
  color: #60a5fa;
  border: 1px solid rgba(59, 130, 246, 0.4);
}

.code-tag {
  background: rgba(255, 255, 255, 0.06);
  padding: 2px 6px;
  border-radius: 4px;
  font-family: monospace;
  font-size: 0.72rem;
  color: #93c5fd;
}

.vat-tag {
  background: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 0.68rem;
  font-weight: 600;
}

.cat-pill {
  font-size: 0.65rem;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 4px;
  text-transform: uppercase;
}

.cat-asset { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
.cat-liab { background: rgba(239, 68, 68, 0.15); color: #f87171; }
.cat-eq { background: rgba(168, 85, 247, 0.15); color: #c084fc; }
.cat-rev { background: rgba(16, 185, 129, 0.15); color: #34d399; }
.cat-exp { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }

.tfoot-balanced {
  background: rgba(16, 185, 129, 0.08);
  border-top: 2px solid rgba(16, 185, 129, 0.3);
}

.tfoot-balanced td {
  color: #ffffff;
  padding: 0.75rem 0.85rem;
}

/* Reports Grid in Mockup */
.preview-reports-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

.prev-report-card {
  background: rgba(15, 23, 42, 0.75);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 12px;
  padding: 1rem 1.15rem;
}

.report-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  padding-bottom: 0.6rem;
  margin-bottom: 0.6rem;
}

.report-card-head h5 {
  font-size: 0.85rem;
  font-weight: 700;
  color: #ffffff;
}

.report-tag {
  font-size: 0.65rem;
  font-weight: 800;
  background: rgba(59, 130, 246, 0.2);
  color: #60a5fa;
  padding: 2px 6px;
  border-radius: 4px;
}

.report-row {
  display: flex;
  justify-content: space-between;
  font-size: 0.78rem;
  padding: 0.35rem 0;
  color: #cbd5e1;
}

.report-highlight {
  border-top: 1px dashed rgba(255, 255, 255, 0.15);
  margin-top: 0.35rem;
  padding-top: 0.5rem;
  color: #ffffff;
}

/* Helpers */
.text-green { color: #34d399 !important; }
.text-amber { color: #fbbf24 !important; }
.text-blue { color: #60a5fa !important; }
.text-cyan { color: #22d3ee !important; }
.font-bold { font-weight: 700 !important; }
.font-mono { font-family: monospace !important; }

/* ============================================================
   TALA INTERACTIVE VIDEO PLAYER COMPONENT
   ============================================================ */
.tala-video-player-container {
  background: #090e1a;
  border-radius: 16px;
  border: 1px solid rgba(56, 189, 248, 0.25);
  overflow: hidden;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7), 0 0 35px rgba(56, 189, 248, 0.12);
  position: relative;
  display: flex;
  flex-direction: column;
}

.tala-video-stage {
  position: relative;
  min-height: 330px;
  background: #0d1527;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

/* Video Play Overlay */
.video-play-overlay {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(8, 14, 26, 0.72);
  backdrop-filter: blur(4px);
  z-index: 90;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: opacity 0.3s ease, visibility 0.3s ease;
  padding: 1.25rem;
  text-align: center;
}

.video-play-overlay.hidden {
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
}

.play-overlay-btn {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: linear-gradient(135deg, #0284c7, #2563eb);
  border: 2px solid rgba(255, 255, 255, 0.5);
  box-shadow: 0 0 25px rgba(56, 189, 248, 0.5), 0 8px 18px rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #ffffff;
  transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.25s ease;
  margin-bottom: 0.75rem;
}

.video-play-overlay:hover .play-overlay-btn {
  transform: scale(1.1);
  box-shadow: 0 0 35px rgba(56, 189, 248, 0.8), 0 10px 24px rgba(0, 0, 0, 0.7);
}

.play-overlay-title {
  font-family: 'Outfit', sans-serif;
  font-size: 0.95rem;
  font-weight: 700;
  color: #ffffff;
  letter-spacing: -0.01em;
}

.play-overlay-sub {
  font-size: 0.72rem;
  color: #94a3b8;
  margin-top: 2px;
}

.video-scene {
  display: none;
  opacity: 0;
  transform: scale(0.98);
  transition: opacity 0.4s ease, transform 0.4s ease;
  width: 100%;
  padding: 0.85rem 1.15rem;
  box-sizing: border-box;
}

.video-scene.active {
  display: block;
  opacity: 1;
  transform: scale(1);
}

/* Virtual Cursor */
.virtual-cursor {
  position: absolute;
  width: 20px;
  height: 20px;
  pointer-events: none;
  z-index: 100;
  transition: top 0.7s cubic-bezier(0.2, 0.8, 0.2, 1), left 0.7s cubic-bezier(0.2, 0.8, 0.2, 1), transform 0.15s ease;
  filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.7));
}

.virtual-cursor.clicking {
  transform: scale(0.85);
}

.cursor-ripple {
  position: absolute;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: 2px solid #38bdf8;
  top: -6px;
  left: -6px;
  opacity: 0;
  pointer-events: none;
  animation: none;
}

.virtual-cursor.clicking .cursor-ripple {
  animation: ripplePop 0.5s ease-out;
}

@keyframes ripplePop {
  0% { transform: scale(0.4); opacity: 1; }
  100% { transform: scale(1.6); opacity: 0; }
}

/* Video Top Scene Title Bar (Non-overlapping, clean & dedicated) */
.video-caption-bar {
  background: #090e1a;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  padding: 0.6rem 1.15rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 0.78rem;
  color: #f1f5f9;
  z-index: 10;
  box-sizing: border-box;
}

.caption-tag {
  background: rgba(56, 189, 248, 0.2);
  color: #38bdf8;
  font-weight: 700;
  font-size: 0.65rem;
  padding: 0.2rem 0.55rem;
  border-radius: 4px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  white-space: nowrap;
  flex-shrink: 0;
}

.caption-text {
  font-weight: 600;
  color: #e2e8f0;
  font-size: 0.78rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Video Controls Bar */
.tala-video-controls {
  background: #080c16;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  padding: 0.65rem 1.25rem 0.85rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  z-index: 90;
}

.video-scrub-track {
  width: 100%;
  height: 6px;
  background: rgba(255, 255, 255, 0.12);
  border-radius: 9999px;
  cursor: pointer;
  position: relative;
  transition: height 0.15s;
}

.video-scrub-track:hover {
  height: 8px;
}

.video-scrub-fill {
  height: 100%;
  background: linear-gradient(90deg, #3b82f6, #06b6d4, #10b981);
  border-radius: 9999px;
  width: 0%;
  position: relative;
  transition: width 0.1s linear;
}

.video-scrub-handle {
  width: 12px;
  height: 12px;
  background: #ffffff;
  border-radius: 50%;
  position: absolute;
  right: -6px;
  top: 50%;
  transform: translateY(-50%);
  box-shadow: 0 0 8px rgba(59, 130, 246, 0.8);
}

.video-bottom-buttons {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.btn-ctrl-play {
  background: #3b82f6;
  color: #ffffff;
  border: none;
  border-radius: 50%;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: transform 0.15s, background 0.15s;
}

.btn-ctrl-play:hover {
  background: #2563eb;
  transform: scale(1.08);
}

.video-scene-pills {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  overflow-x: auto;
  max-width: 60%;
}

.scene-pill-btn {
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.08);
  color: #94a3b8;
  font-size: 0.72rem;
  font-weight: 600;
  padding: 0.25rem 0.65rem;
  border-radius: 9999px;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.2s;
}

.scene-pill-btn.active {
  background: rgba(56, 189, 248, 0.2);
  border-color: rgba(56, 189, 248, 0.5);
  color: #38bdf8;
  font-weight: 700;
}

/* ============================================================
   RESPONSIVE DESIGN (iPad, Tablets, iOS & Mobile)
   ============================================================ */

/* ─── IPAD & TABLET (768px - 1024px, iPad Mini, iPad Air, iPad Pro) ─── */
@media (min-width: 641px) and (max-width: 1024px) {
  .floating-badge {
    display: none;
  }
  .preview-metrics-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .preview-charts-grid {
    grid-template-columns: 1fr;
  }
  .preview-reports-grid {
    grid-template-columns: 1fr;
  }
  .container {
    padding: 0 1.5rem;
  }
  .navbar {
    padding: 1.25rem 0;
  }
  .nav-links {
    gap: 1.25rem;
  }
  .nav-link {
    font-size: 0.85rem;
  }
  .hero {
    padding: 3.5rem 0 2.5rem;
  }
  .hero-heading {
    font-size: 2.85rem;
    line-height: 1.18;
  }
  .cards-grid {
    grid-template-columns: repeat(2, 1fr);
    gap: 1.25rem;
    max-width: 100%;
  }
  .card-visual-stage {
    height: 140px;
    transform: scale(0.78);
  }
  .feature-card {
    padding: 1.25rem 1.1rem 1.4rem;
  }
  .feature-card-title {
    font-size: 1.15rem;
  }
  .feature-card-desc {
    font-size: 0.82rem;
  }
  .features-grid-row {
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
  }
  .cta-box {
    padding: 3rem 1.5rem;
  }
}

/* ─── MOBILE & IPHONES / SMALL SCREENS (<= 640px) ─── */
@media (max-width: 640px) {
  .container {
    padding: 0 1rem;
  }

  /* Compact Clean Mobile Navbar */
  .navbar {
    padding: 0.85rem 0;
  }
  .nav-links {
    display: none;
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
    min-width: 135px;
    padding: 0.75rem 1rem;
    font-size: 0.88rem;
    justify-content: center;
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
    height: 90px;
    margin-bottom: 0.2rem;
    transform: scale(0.55);
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
  /* Showcase Mobile Adjustments */
  .floating-badge {
    display: none !important;
  }
  .showcase-frame-wrapper {
    margin-top: 2rem !important;
  }
  .mockup-device {
    border-radius: 14px !important;
  }
  .mockup-header {
    padding: 0.6rem 0.85rem !important;
  }
  .mockup-address-bar {
    display: none !important;
  }
  .mockup-tabs {
    padding: 0.35rem 0.5rem !important;
    gap: 0.25rem !important;
  }
  .mockup-tab {
    padding: 0.45rem 0.75rem !important;
    font-size: 0.75rem !important;
  }
  .mockup-screen-body {
    padding: 0.85rem 0.75rem !important;
    min-height: unset !important;
  }
  .preview-metrics-grid {
    grid-template-columns: 1fr !important;
    gap: 0.6rem !important;
  }
  .preview-charts-grid {
    grid-template-columns: 1fr !important;
    gap: 0.75rem !important;
  }
  .preview-reports-grid {
    grid-template-columns: 1fr !important;
    gap: 0.75rem !important;
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
    </ul>
  </header>

  <!-- =========================================
       HERO SECTION
       ========================================= -->
  <section class="hero">
    
    <!-- Big Headline with Gradient & Refined Typography -->
    <h1 class="hero-heading">
      <span class="hero-title-main">Learn Accounting</span><br>
      <span class="hero-title-highlight">Through Simulation</span>
    </h1>

    <!-- Subtitle -->
    <p class="hero-subtext">
      Learn and practice accounting through an interactive simulation designed to help students understand accounting concepts and experience real-world accounting processes.
    </p>

    <!-- Call to Action Buttons -->
    <div class="hero-actions">
      <a href="<?= $registerUrl ?>" class="btn-pill btn-pill-blue btn-pill-lg">
        <i data-lucide="user-plus" style="width: 18px; height: 18px;"></i> Create Account
      </a>
      <a href="<?= $targetUrl ?>" class="btn-pill btn-pill-dark btn-pill-lg">
        <i data-lucide="log-in" style="width: 18px; height: 18px;"></i> Sign In
      </a>
    </div>

    <!-- ============================================================
         DRIBBLE-STYLE HERO DEVICE MOCKUP SHOWCASE
         ============================================================ -->
    <div class="showcase-frame-wrapper" style="margin-top: 1.75rem; position: relative; max-width: 700px; margin-left: auto; margin-right: auto;">
      
      <!-- Pure Standalone Video Player -->
      <div class="tala-video-player-container">
              
              <!-- Dedicated Top Scene Caption / Header Bar (Non-overlapping) -->
              <div class="video-caption-bar" id="vCaption">
                <div style="display: flex; align-items: center; gap: 0.6rem; min-width: 0;">
                  <span class="caption-tag" id="vCapTag">SCENE 1/6</span>
                  <span id="vCapText" class="caption-text">Step 1: Signing into Student Simulation Environment</span>
                </div>
                <div style="display: flex; align-items: center; gap: 0.4rem; flex-shrink: 0;">
                  <span class="pulse-dot"></span>
                  <span style="font-size: 0.68rem; color: #34d399; font-weight: 700; text-transform: uppercase;">Simulating</span>
                </div>
              </div>

              <div class="tala-video-stage" id="videoStage">
                
                <!-- Video Play Overlay (User Controlled) -->
                <div class="video-play-overlay" id="videoPlayOverlay" onclick="startVideoPlayback()">
                  <div class="play-overlay-btn">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="#ffffff" stroke="none" style="margin-left: 3px;">
                      <polygon points="5 3 19 12 5 21 5 3"></polygon>
                    </svg>
                  </div>
                  <div class="play-overlay-title">Click to Play System Walkthrough</div>
                  <div class="play-overlay-sub">Watch 6 real-world simulation steps in action • 0:22 min</div>
                </div>
                
                <!-- Virtual Cursor -->
                <div class="virtual-cursor" id="vCursor" style="top: 50%; left: 50%;">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="#38bdf8" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3l7 18 3-7 7-3L3 3z"/>
                  </svg>
                  <div class="cursor-ripple"></div>
                </div>

                <!-- SCENE 1: AUTH / LOGIN -->
                <div class="video-scene active" id="vScene-0">
                  <div style="max-width: 360px; margin: 2rem auto; background: rgba(15,23,42,0.85); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 1.5rem; text-align: left;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                      <div style="width: 28px; height: 28px; background: #3b82f6; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 0.8rem;">★</div>
                      <div>
                        <strong style="color: #fff; font-size: 0.95rem;">TALA-AIS Portal</strong>
                        <div style="font-size: 0.7rem; color: #94a3b8;">Student Sign In</div>
                      </div>
                    </div>
                    <div style="margin-bottom: 0.75rem;">
                      <label style="font-size: 0.72rem; color: #94a3b8; display: block; margin-bottom: 3px;">Student Email</label>
                      <div id="vInputEmail" style="background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; padding: 0.45rem 0.75rem; font-size: 0.8rem; color: #fff; font-family: monospace;">student@lspu.edu.ph</div>
                    </div>
                    <div style="margin-bottom: 1rem;">
                      <label style="font-size: 0.72rem; color: #94a3b8; display: block; margin-bottom: 3px;">Password</label>
                      <div style="background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; padding: 0.45rem 0.75rem; font-size: 0.8rem; color: #94a3b8; font-family: monospace;">••••••••••••</div>
                    </div>
                    <button id="vBtnLogin" style="width: 100%; background: #3b82f6; color: #fff; border: none; padding: 0.6rem; border-radius: 6px; font-weight: 700; font-size: 0.85rem;">Sign In to Workspace →</button>
                  </div>
                </div>

                <!-- SCENE 2: COMPANY SETUP -->
                <div class="video-scene" id="vScene-1">
                  <div style="max-width: 520px; margin: 1rem auto; background: rgba(15,23,42,0.85); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 1.25rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.5rem;">
                      <strong style="color: #fff; font-size: 0.95rem;">Select Practice Entity</strong>
                      <span class="cat-pill cat-rev">Active Course: BSA 2-A</span>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                      <div id="vCompanyCard1" style="background: rgba(59,130,246,0.15); border: 2px solid #3b82f6; border-radius: 8px; padding: 0.85rem; text-align: left;">
                        <div style="font-weight: 700; color: #fff; font-size: 0.88rem;">Apex Solutions Co.</div>
                        <div style="font-size: 0.72rem; color: #93c5fd; margin-top: 2px;">Service Business • VAT Registered</div>
                        <div style="font-size: 0.7rem; color: #34d399; margin-top: 6px; font-weight: 600;">✓ Active Simulation (Selected)</div>
                      </div>
                      <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.85rem; text-align: left;">
                        <div style="font-weight: 700; color: #94a3b8; font-size: 0.88rem;">Lumina Retail Mart</div>
                        <div style="font-size: 0.72rem; color: #64748b; margin-top: 2px;">Merchandising • Non-VAT</div>
                        <div style="font-size: 0.7rem; color: #64748b; margin-top: 6px;">Switch Simulation</div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- SCENE 3: DASHBOARD METRICS & CHARTS -->
                <div class="video-scene" id="vScene-2">
                  <div class="view-header" style="margin-bottom: 0.75rem;">
                    <div>
                      <h4 style="font-size: 1rem; font-weight: 700; color: #ffffff;">Executive Dashboard Overview</h4>
                      <p style="font-size: 0.72rem; color: #94a3b8;">Apex Solutions Co. • Live Trial Balance Synchronized</p>
                    </div>
                    <span class="badge-status-green">All Entries In Balance</span>
                  </div>

                  <div class="preview-metrics-grid" style="margin-bottom: 0.85rem;">
                    <div class="prev-card">
                      <div class="prev-card-lbl">TOTAL REVENUE</div>
                      <div class="prev-card-val" id="vRevVal" style="color: #34d399;">₱350,000.00</div>
                      <div class="prev-card-sub text-green">↑ +18.4% this period</div>
                    </div>
                    <div class="prev-card">
                      <div class="prev-card-lbl">TOTAL EXPENSES</div>
                      <div class="prev-card-val" id="vExpVal" style="color: #fbbf24;">₱125,000.00</div>
                      <div class="prev-card-sub text-amber">Operational &amp; Tax</div>
                    </div>
                    <div class="prev-card">
                      <div class="prev-card-lbl">NET INCOME</div>
                      <div class="prev-card-val" id="vNetVal" style="color: #60a5fa;">₱225,000.00</div>
                      <div class="prev-card-sub text-blue">64.3% Profit Margin</div>
                    </div>
                    <div class="prev-card">
                      <div class="prev-card-lbl">CASH BALANCE</div>
                      <div class="prev-card-val" id="vCashVal" style="color: #22d3ee;">₱280,000.00</div>
                      <div class="prev-card-sub text-cyan">Verified in GL</div>
                    </div>
                  </div>

                  <div class="preview-charts-grid">
                    <div class="prev-chart-box">
                      <div style="display: flex; justify-content: space-between; font-size: 0.75rem; color: #e2e8f0; font-weight: 700; margin-bottom: 0.4rem;">
                        <span>Monthly Revenue vs Expense</span>
                        <span style="color: #60a5fa;">Q3 2026</span>
                      </div>
                      <div class="sim-bars-container">
                        <div class="sim-bar-group"><div class="sim-bar" style="height: 50%; background: #3b82f6;"></div><div class="sim-bar" style="height: 35%; background: #ef4444;"></div><span>Jul</span></div>
                        <div class="sim-bar-group"><div class="sim-bar" style="height: 75%; background: #3b82f6;"></div><div class="sim-bar" style="height: 40%; background: #ef4444;"></div><span>Aug</span></div>
                        <div class="sim-bar-group"><div class="sim-bar" style="height: 95%; background: #3b82f6;"></div><div class="sim-bar" style="height: 32%; background: #ef4444;"></div><span>Sep</span></div>
                      </div>
                    </div>
                    <div class="prev-chart-box">
                      <div style="font-size: 0.75rem; color: #e2e8f0; font-weight: 700; margin-bottom: 0.4rem;">Asset Distribution</div>
                      <div class="sim-donut-wrapper">
                        <div class="sim-donut"><div class="sim-donut-hole"><span style="font-size: 0.62rem; color: #94a3b8;">Total</span><strong style="font-size: 0.75rem; color: #fff;">₱482k</strong></div></div>
                        <div class="sim-legend">
                          <div><span class="legend-dot" style="background: #3b82f6;"></span>Cash (58%)</div>
                          <div><span class="legend-dot" style="background: #10b981;"></span>Receivables (24%)</div>
                          <div><span class="legend-dot" style="background: #f59e0b;"></span>Equipment (18%)</div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- SCENE 4: JOURNAL ENTRY POSTING -->
                <div class="video-scene" id="vScene-3">
                  <div class="view-header" style="margin-bottom: 0.5rem;">
                    <div>
                      <h4 style="font-size: 1rem; font-weight: 700; color: #ffffff;">Specialized Journals &amp; 12% VAT Computation</h4>
                      <p style="font-size: 0.72rem; color: #94a3b8;">Automated debit-credit split with real-time tax validation</p>
                    </div>
                    <span class="badge-status-green" id="vJournalStatus">✓ EQUAL &amp; VALIDATED</span>
                  </div>

                  <div class="preview-table-wrapper">
                    <table class="preview-sim-table">
                      <thead>
                        <tr>
                          <th>Date</th>
                          <th>Ref</th>
                          <th>Account Title</th>
                          <th>Tax Split</th>
                          <th class="text-right">Debit (₱)</th>
                          <th class="text-right">Credit (₱)</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr id="vJournalRow1">
                          <td>Sep 15</td>
                          <td><span class="code-tag">SJ-0042</span></td>
                          <td><strong>Accounts Receivable</strong></td>
                          <td><span class="cat-pill cat-asset">Gross 112%</span></td>
                          <td class="text-right text-green font-bold">56,000.00</td>
                          <td class="text-right text-muted">-</td>
                        </tr>
                        <tr id="vJournalRow2">
                          <td>Sep 15</td>
                          <td><span class="code-tag">SJ-0042</span></td>
                          <td><strong>Service Revenue</strong></td>
                          <td><span class="vat-tag">12% Base</span></td>
                          <td class="text-right text-muted">-</td>
                          <td class="text-right text-amber font-bold">50,000.00</td>
                        </tr>
                        <tr id="vJournalRow3">
                          <td>Sep 15</td>
                          <td><span class="code-tag">SJ-0042</span></td>
                          <td><strong>Output VAT Payable</strong></td>
                          <td><span class="vat-tag">12% Tax</span></td>
                          <td class="text-right text-muted">-</td>
                          <td class="text-right text-amber font-bold">6,000.00</td>
                        </tr>
                      </tbody>
                      <tfoot>
                        <tr class="tfoot-balanced">
                          <td colspan="4"><strong>TRANSACTION STATUS: BALANCED</strong></td>
                          <td class="text-right text-green font-bold">₱56,000.00</td>
                          <td class="text-right text-green font-bold">₱56,000.00</td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                </div>

                <!-- SCENE 5: TRIAL BALANCE & LEDGER -->
                <div class="video-scene" id="vScene-4">
                  <div class="view-header" style="margin-bottom: 0.5rem;">
                    <div>
                      <h4 style="font-size: 1rem; font-weight: 700; color: #ffffff;">Trial Balance Synchronization</h4>
                      <p style="font-size: 0.72rem; color: #94a3b8;">Automated T-Account summation ensuring equality across all account types</p>
                    </div>
                    <span class="badge-status-green">Discrepancy: ₱0.00</span>
                  </div>

                  <div class="preview-table-wrapper">
                    <table class="preview-sim-table">
                      <thead>
                        <tr>
                          <th>Code</th>
                          <th>Account Title</th>
                          <th>Category</th>
                          <th class="text-right">Debit Balance</th>
                          <th class="text-right">Credit Balance</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr><td><span class="code-tag">101</span></td><td>Cash on Hand</td><td><span class="cat-pill cat-asset">Assets</span></td><td class="text-right font-mono text-green">₱280,000.00</td><td class="text-right font-mono text-muted">-</td></tr>
                        <tr><td><span class="code-tag">105</span></td><td>Accounts Receivable</td><td><span class="cat-pill cat-asset">Assets</span></td><td class="text-right font-mono text-green">₱115,500.00</td><td class="text-right font-mono text-muted">-</td></tr>
                        <tr><td><span class="code-tag">201</span></td><td>Accounts Payable</td><td><span class="cat-pill cat-liab">Liabilities</span></td><td class="text-right font-mono text-muted">-</td><td class="text-right font-mono text-amber">₱85,000.00</td></tr>
                        <tr><td><span class="code-tag">301</span></td><td>Owner's Equity</td><td><span class="cat-pill cat-eq">Equity</span></td><td class="text-right font-mono text-muted">-</td><td class="text-right font-mono text-amber">₱200,000.00</td></tr>
                        <tr><td><span class="code-tag">401</span></td><td>Service Revenue</td><td><span class="cat-pill cat-rev">Revenue</span></td><td class="text-right font-mono text-muted">-</td><td class="text-right font-mono text-amber">₱350,000.00</td></tr>
                        <tr><td><span class="code-tag">501</span></td><td>Salaries &amp; Utilities</td><td><span class="cat-pill cat-exp">Expenses</span></td><td class="text-right font-mono text-green">₱239,500.00</td><td class="text-right font-mono text-muted">-</td></tr>
                      </tbody>
                      <tfoot>
                        <tr class="tfoot-balanced">
                          <td colspan="3"><strong>TOTAL TRIAL BALANCE</strong></td>
                          <td class="text-right text-green font-bold">₱635,000.00</td>
                          <td class="text-right text-green font-bold">₱635,000.00</td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                </div>

                <!-- SCENE 6: FINANCIAL STATEMENTS & PDF EXPORT -->
                <div class="video-scene" id="vScene-5">
                  <div class="view-header" style="margin-bottom: 0.5rem;">
                    <div>
                      <h4 style="font-size: 1rem; font-weight: 700; color: #ffffff;">Automated Financial Reports &amp; PDF Export</h4>
                      <p style="font-size: 0.72rem; color: #94a3b8;">One-click generation of audited statements ready for instructor evaluation</p>
                    </div>
                    <button class="btn-pill btn-pill-blue btn-pill-sm" id="vBtnExport">
                      <i data-lucide="download" style="width: 13px; height: 13px;"></i> <span id="vExportLabel">Exporting PDF...</span>
                    </button>
                  </div>

                  <div class="preview-reports-grid">
                    <div class="prev-report-card">
                      <div class="report-card-head">
                        <h5>Statement of Comprehensive Income</h5>
                        <span class="report-tag">Income Statement</span>
                      </div>
                      <div class="report-row"><span>Service Revenue</span><strong class="text-green">₱350,000.00</strong></div>
                      <div class="report-row"><span>Less: Operating Expenses</span><strong class="text-amber">(₱125,000.00)</strong></div>
                      <div class="report-row report-highlight"><span>NET PROFIT</span><strong class="text-green font-bold">₱225,000.00</strong></div>
                    </div>

                    <div class="prev-report-card">
                      <div class="report-card-head">
                        <h5>Statement of Financial Position</h5>
                        <span class="report-tag">Balance Sheet</span>
                      </div>
                      <div class="report-row"><span>Total Assets</span><strong>₱482,500.00</strong></div>
                      <div class="report-row"><span>Total Liabilities</span><strong>₱85,000.00</strong></div>
                      <div class="report-row report-highlight"><span>TOTAL ASSETS = LIAB &amp; EQUITY</span><strong class="text-blue font-bold">₱482,500.00</strong></div>
                    </div>
                  </div>
                </div>

              </div> <!-- end tala-video-stage -->

              <!-- Video Player Scrub & Control Bar -->
              <div class="tala-video-controls">
                
                <!-- Scrub Bar -->
                <div class="video-scrub-track" id="videoScrubTrack" onclick="scrubVideo(event)">
                  <div class="video-scrub-fill" id="videoScrubFill">
                    <div class="video-scrub-handle"></div>
                  </div>
                </div>

                <!-- Control Buttons & Timestamp -->
                <div class="video-bottom-buttons">
                  <div class="flex items-center gap-3">
                    <button class="btn-ctrl-play" id="btnVideoPlayPause" onclick="toggleVideoPlayback()">
                      <i data-lucide="play" style="width: 16px; height: 16px;" id="videoPlayIcon"></i>
                    </button>
                    <span id="videoTimeDisplay" style="font-size: 0.78rem; font-family: monospace; color: #cbd5e1; font-weight: 600;">0:00 / 0:22</span>
                  </div>

                  <!-- Scene Quick Jump Pills -->
                  <div class="video-scene-pills">
                    <button class="scene-pill-btn active" onclick="jumpToVideoScene(0)">1. Sign In</button>
                    <button class="scene-pill-btn" onclick="jumpToVideoScene(1)">2. Setup</button>
                    <button class="scene-pill-btn" onclick="jumpToVideoScene(2)">3. Dashboard</button>
                    <button class="scene-pill-btn" onclick="jumpToVideoScene(3)">4. Journals</button>
                    <button class="scene-pill-btn" onclick="jumpToVideoScene(4)">5. Trial Balance</button>
                    <button class="scene-pill-btn" onclick="jumpToVideoScene(5)">6. Reports</button>
                  </div>

                  <div class="flex items-center gap-2">
                    <!-- Voice Narration Toggle -->
                    <button id="btnVoiceToggle" onclick="toggleVoiceNarration()" class="btn-pill btn-pill-sm" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); font-size: 0.72rem;" title="Toggle Voice Narration">
                      <i data-lucide="volume-2" style="width: 13px; height: 13px;" id="voiceIcon"></i> <span id="voiceLabel">Voice: ON</span>
                    </button>
                    <button onclick="restartVideoPlayback()" class="btn-pill btn-pill-dark btn-pill-sm" title="Replay Video">
                      <i data-lucide="rotate-ccw" style="width: 13px; height: 13px;"></i> Replay
                    </button>
                  </div>
                </div>

              </div> <!-- end tala-video-controls -->

      </div> <!-- end tala-video-player-container -->

    </div> <!-- end showcase-frame-wrapper -->

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

  // Initialize video in paused state (User controls playback)
  window.addEventListener('DOMContentLoaded', function() {
    updateVideoUI();
  });

  // (Old interactive demo tour code removed — video player handles all demos)

  /* ============================================================
     TALA INTERACTIVE VIDEO DEMO PLAYER CONTROLLER
     ============================================================ */
  const videoScenes = [
    {
      id: 0,
      duration: 3.5,
      tag: 'SCENE 1/6 • AUTHENTICATION',
      caption: 'Step 1: Signing into Student Simulation Environment',
      cursorX: '65%',
      cursorY: '68%',
      action: function() {
        const btn = document.getElementById('vBtnLogin');
        const cursor = document.getElementById('vCursor');
        if (cursor) {
          cursor.style.left = '52%';
          cursor.style.top = '65%';
          setTimeout(() => {
            cursor.classList.add('clicking');
            if (btn) btn.style.transform = 'scale(0.97)';
            setTimeout(() => {
              cursor.classList.remove('clicking');
              if (btn) {
                btn.style.transform = 'scale(1)';
                btn.innerText = '✓ Authenticated! Loading Workspace...';
                btn.style.background = '#10b981';
              }
            }, 250);
          }, 700);
        }
      }
    },
    {
      id: 1,
      duration: 3.5,
      tag: 'SCENE 2/6 • COMPANY SETUP',
      caption: 'Step 2: Selecting Practice Business Entity (Apex Solutions Co.)',
      cursorX: '35%',
      cursorY: '45%',
      action: function() {
        const card = document.getElementById('vCompanyCard1');
        const cursor = document.getElementById('vCursor');
        if (cursor) {
          cursor.style.left = '32%';
          cursor.style.top = '48%';
          setTimeout(() => {
            cursor.classList.add('clicking');
            if (card) {
              card.style.borderColor = '#38bdf8';
              card.style.boxShadow = '0 0 15px rgba(56, 189, 248, 0.4)';
            }
            setTimeout(() => cursor.classList.remove('clicking'), 250);
          }, 700);
        }
      }
    },
    {
      id: 2,
      duration: 4.0,
      tag: 'SCENE 3/6 • EXECUTIVE DASHBOARD',
      caption: 'Step 3: Real-Time Executive Dashboard KPIs & Asset Distribution',
      cursorX: '75%',
      cursorY: '30%',
      action: function() {
        const cursor = document.getElementById('vCursor');
        if (cursor) {
          cursor.style.left = '25%';
          cursor.style.top = '35%';
          setTimeout(() => {
            cursor.style.left = '75%';
            cursor.style.top = '70%';
          }, 900);
        }
      }
    },
    {
      id: 3,
      duration: 4.0,
      tag: 'SCENE 4/6 • JOURNAL ENTRIES',
      caption: 'Step 4: Posting Sales Journal Entry with Automated 12% VAT Split',
      cursorX: '80%',
      cursorY: '50%',
      action: function() {
        const cursor = document.getElementById('vCursor');
        const status = document.getElementById('vJournalStatus');
        if (cursor) {
          cursor.style.left = '70%';
          cursor.style.top = '55%';
          setTimeout(() => {
            if (status) {
              status.style.transform = 'scale(1.08)';
              status.style.boxShadow = '0 0 12px rgba(16, 185, 129, 0.6)';
              setTimeout(() => { status.style.transform = 'scale(1)'; }, 350);
            }
          }, 800);
        }
      }
    },
    {
      id: 4,
      duration: 3.5,
      tag: 'SCENE 5/6 • TRIAL BALANCE',
      caption: 'Step 5: Verifying Zero Discrepancy & Balanced General Ledger',
      cursorX: '85%',
      cursorY: '85%',
      action: function() {
        const cursor = document.getElementById('vCursor');
        if (cursor) {
          cursor.style.left = '80%';
          cursor.style.top = '88%';
        }
      }
    },
    {
      id: 5,
      duration: 3.5,
      tag: 'SCENE 6/6 • FINANCIAL STATEMENTS',
      caption: 'Step 6: Instant Balance Sheet Generation & One-Click PDF Export',
      cursorX: '88%',
      cursorY: '25%',
      action: function() {
        const cursor = document.getElementById('vCursor');
        const btn = document.getElementById('vBtnExport');
        const lbl = document.getElementById('vExportLabel');
        if (cursor) {
          cursor.style.left = '86%';
          cursor.style.top = '22%';
          setTimeout(() => {
            cursor.classList.add('clicking');
            if (lbl) lbl.innerText = '✓ PDF Exported!';
            if (btn) btn.style.background = '#10b981';
            setTimeout(() => cursor.classList.remove('clicking'), 250);
          }, 800);
        }
      }
    }
  ];

  let videoCurrentScene = 0;
  let videoCurrentTime = 0;
  let lastSpokenScene = -1;
  let isVoiceEnabled = true;
  const videoTotalDuration = 22; // seconds
  let videoPlayInterval = null;
  let isVideoPlaying = false;

  function toggleVoiceNarration() {
    isVoiceEnabled = !isVoiceEnabled;
    const btn = document.getElementById('btnVoiceToggle');
    const icon = document.getElementById('voiceIcon');
    const label = document.getElementById('voiceLabel');
    
    if (isVoiceEnabled) {
      if (icon) icon.setAttribute('data-lucide', 'volume-2');
      if (label) label.innerText = 'Voice: ON';
      if (btn) {
        btn.style.background = 'rgba(56, 189, 248, 0.15)';
        btn.style.color = '#38bdf8';
        btn.style.borderColor = 'rgba(56, 189, 248, 0.3)';
      }
      if (isVideoPlaying) {
        speakSceneNarration(videoCurrentScene);
      }
    } else {
      if ('speechSynthesis' in window) {
        window.speechSynthesis.cancel();
      }
      if (icon) icon.setAttribute('data-lucide', 'volume-x');
      if (label) label.innerText = 'Voice: OFF';
      if (btn) {
        btn.style.background = 'rgba(255, 255, 255, 0.05)';
        btn.style.color = '#94a3b8';
        btn.style.borderColor = 'rgba(255, 255, 255, 0.1)';
      }
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
  }

  function speakSceneNarration(sceneIndex) {
    if (!isVoiceEnabled || !('speechSynthesis' in window)) return;
    
    try {
      window.speechSynthesis.cancel();

      // Perfectly timed lines that match the ~3.5s scene pacing with zero dead air
      const narrationScripts = [
        "Sign in to launch your simulation workspace.",
        "Select your practice company to configure accounts.",
        "Monitor real-time revenues, expenses, and profit margins.",
        "Post specialized journals with automated 12 percent VAT.",
        "Verify zero discrepancy in your trial balance.",
        "Generate balance sheets and export audited PDF reports."
      ];

      const text = narrationScripts[sceneIndex];
      if (!text) return;

      const utterance = new SpeechSynthesisUtterance(text);
      utterance.rate = 1.05;
      utterance.pitch = 1.0;
      utterance.volume = 1.0;
      utterance.lang = 'en-US';

      const voices = window.speechSynthesis.getVoices();
      const preferredVoice = voices.find(v => v.lang.startsWith('en') && (v.name.includes('Google') || v.name.includes('Natural') || v.name.includes('Samantha') || v.name.includes('Zira') || v.name.includes('Jenny') || v.name.includes('David')));
      if (preferredVoice) {
        utterance.voice = preferredVoice;
      }

      window.speechSynthesis.speak(utterance);
      lastSpokenScene = sceneIndex;
    } catch(e) {
      console.log('Voice narration error:', e);
    }
  }

  function updateVideoUI() {
    const scene = videoScenes[videoCurrentScene];
    
    // Switch active scene DOM
    document.querySelectorAll('.video-scene').forEach((sc, idx) => {
      sc.classList.toggle('active', idx === videoCurrentScene);
    });

    // Update scene pills
    document.querySelectorAll('.scene-pill-btn').forEach((p, idx) => {
      p.classList.toggle('active', idx === videoCurrentScene);
    });

    // Update Caption
    const capTag = document.getElementById('vCapTag');
    const capText = document.getElementById('vCapText');
    if (capTag) capTag.innerText = scene.tag;
    if (capText) capText.innerText = scene.caption;

    // Trigger scene script action
    if (typeof scene.action === 'function') {
      scene.action();
    }

    // Voice narration on scene switch while playing
    if (isVideoPlaying && lastSpokenScene !== videoCurrentScene) {
      speakSceneNarration(videoCurrentScene);
    }

    // Update progress bar
    const progressPercent = Math.min(100, (videoCurrentTime / videoTotalDuration) * 100);
    const scrubFill = document.getElementById('videoScrubFill');
    if (scrubFill) scrubFill.style.width = progressPercent + '%';

    // Update timestamp text
    const timeDisplay = document.getElementById('videoTimeDisplay');
    if (timeDisplay) {
      const curM = Math.floor(videoCurrentTime / 60);
      const curS = Math.floor(videoCurrentTime % 60).toString().padStart(2, '0');
      const totM = Math.floor(videoTotalDuration / 60);
      const totS = Math.floor(videoTotalDuration % 60).toString().padStart(2, '0');
      timeDisplay.innerText = `${curM}:${curS} / ${totM}:${totS}`;
    }

    if (typeof lucide !== 'undefined') lucide.createIcons();
  }

  function startVideoPlayback() {
    if (isVideoPlaying) return;
    isVideoPlaying = true;
    const playIcon = document.getElementById('videoPlayIcon');
    if (playIcon) playIcon.setAttribute('data-lucide', 'pause');
    const overlay = document.getElementById('videoPlayOverlay');
    if (overlay) overlay.classList.add('hidden');

    updateVideoUI();

    clearInterval(videoPlayInterval);
    videoPlayInterval = setInterval(() => {
      videoCurrentTime += 0.5;
      
      // Calculate scene threshold
      let accum = 0;
      for (let i = 0; i < videoScenes.length; i++) {
        accum += videoScenes[i].duration;
        if (videoCurrentTime <= accum || i === videoScenes.length - 1) {
          if (videoCurrentScene !== i) {
            videoCurrentScene = i;
          }
          break;
        }
      }

      if (videoCurrentTime >= videoTotalDuration) {
        videoCurrentTime = 0;
        videoCurrentScene = 0;
      }

      updateVideoUI();
    }, 500);

    if (typeof lucide !== 'undefined') lucide.createIcons();
  }

  function pauseVideoPlayback() {
    isVideoPlaying = false;
    clearInterval(videoPlayInterval);
    if ('speechSynthesis' in window) {
      window.speechSynthesis.cancel();
    }
    const playIcon = document.getElementById('videoPlayIcon');
    if (playIcon) playIcon.setAttribute('data-lucide', 'play');
    const overlay = document.getElementById('videoPlayOverlay');
    if (overlay) overlay.classList.remove('hidden');
    if (typeof lucide !== 'undefined') lucide.createIcons();
  }

  function toggleVideoPlayback() {
    if (isVideoPlaying) {
      pauseVideoPlayback();
    } else {
      startVideoPlayback();
    }
  }

  function restartVideoPlayback() {
    videoCurrentTime = 0;
    videoCurrentScene = 0;
    lastSpokenScene = -1;
    startVideoPlayback();
  }

  function jumpToVideoScene(sceneIndex) {
    let targetTime = 0;
    for (let i = 0; i < sceneIndex; i++) {
      targetTime += videoScenes[i].duration;
    }
    videoCurrentTime = targetTime;
    videoCurrentScene = sceneIndex;
    lastSpokenScene = -1;
    updateVideoUI();
    if (!isVideoPlaying) {
      startVideoPlayback();
    }
  }

  function scrubVideo(event) {
    const track = document.getElementById('videoScrubTrack');
    if (!track) return;
    const rect = track.getBoundingClientRect();
    const clickX = event.clientX - rect.left;
    const percent = Math.max(0, Math.min(1, clickX / rect.width));
    videoCurrentTime = percent * videoTotalDuration;

    let accum = 0;
    for (let i = 0; i < videoScenes.length; i++) {
      accum += videoScenes[i].duration;
      if (videoCurrentTime <= accum || i === videoScenes.length - 1) {
        videoCurrentScene = i;
        break;
      }
    }
    updateVideoUI();
  }

  function simulatePdfDownload() {
    const txt = document.getElementById('btnExportText');
    if (txt) {
      txt.innerText = 'Generating PDF...';
      setTimeout(() => {
        txt.innerText = '✓ PDF Ready!';
        setTimeout(() => { txt.innerText = 'Download PDF'; }, 2000);
      }, 1000);
    }
  }
</script>

</body>
</html>