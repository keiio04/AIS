<?php
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms &amp; Conditions and Privacy Policy — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #090e1a;
            --surface: #0f172a;
            --border: #1e293b;
            --border-hover: #334155;
            --text-heading: #f8fafc;
            --text-body: #94a3b8;
            --text-strong: #e2e8f0;
            --accent: #3b82f6;
        }

        body {
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #070b14;
            background-image: 
                radial-gradient(circle at 50% 0%, rgba(30, 58, 138, 0.45) 0%, transparent 60%),
                radial-gradient(circle at 80% 50%, rgba(37, 99, 235, 0.22) 0%, transparent 50%),
                linear-gradient(180deg, #070b14 0%, #0b1329 50%, #0f1c3f 100%);
            color: var(--text-body);
            line-height: 1.6;
            padding: 2.5rem 1rem 4rem;
            -webkit-font-smoothing: antialiased;
            position: relative;
        }

        /* Ambient Accounting Background matching Home & Login */
        .hero-bg-layer {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                linear-gradient(180deg, rgba(7, 11, 20, 0.78) 0%, rgba(11, 19, 41, 0.88) 55%, rgba(7, 11, 20, 0.95) 100%),
                radial-gradient(circle at 50% 30%, rgba(0, 112, 243, 0.12) 0%, rgba(6, 10, 18, 0.5) 80%),
                url('<?= BASE_URL ?>assets/images/hero-accounting-bg.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            pointer-events: none;
            z-index: 0;
            opacity: 0.5;
        }

        .container {
            position: relative;
            z-index: 1;
            max-width: 920px;
            margin: 0 auto;
        }

        .top-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
            font-size: 0.8125rem;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: var(--text-body);
            text-decoration: none;
            padding: 0.4rem 0.85rem;
            border-radius: 8px;
            background: rgba(18, 28, 51, 0.65);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(59, 130, 246, 0.25);
            transition: all 0.2s ease;
        }

        .back-link:hover {
            color: var(--text-heading);
            border-color: rgba(96, 165, 250, 0.55);
            background: rgba(30, 58, 138, 0.35);
        }

        .system-tag {
            color: #94a3b8;
            font-weight: 500;
        }

        .doc-sheet {
            background-color: rgba(13, 21, 41, 0.82);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(59, 130, 246, 0.25);
            border-radius: 16px;
            padding: 2.25rem 2.75rem;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.55), 0 0 35px rgba(37, 99, 235, 0.12);
        }

        .doc-header {
            padding-bottom: 1.15rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.35rem;
        }

        .doc-header h1 {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 0.35rem;
            letter-spacing: -0.02em;
        }

        .doc-header p {
            font-size: 0.85rem;
            color: var(--text-body);
        }

        .section {
            margin-bottom: 1.25rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--border);
        }

        .section:last-of-type {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        h2 {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-heading);
            margin-bottom: 0.45rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .num {
            color: var(--accent);
        }

        p {
            font-size: 0.85rem;
            color: var(--text-body);
            margin-bottom: 0.4rem;
        }

        p:last-child {
            margin-bottom: 0;
        }

        ul {
            list-style: none;
            margin: 0.35rem 0;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        li {
            font-size: 0.85rem;
            color: var(--text-body);
            position: relative;
            padding-left: 1.1rem;
        }

        li::before {
            content: "•";
            position: absolute;
            left: 0.25rem;
            color: var(--accent);
        }

        strong {
            color: var(--text-strong);
            font-weight: 600;
        }

        .action-bar {
            margin-top: 1.5rem;
            padding-top: 1.15rem;
            border-top: 1px solid var(--border);
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.8125rem;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-body);
            border: 1px solid var(--border);
            transition: all 0.15s ease;
        }

        .btn-back:hover {
            color: var(--text-heading);
            border-color: var(--border-hover);
            background: rgba(255, 255, 255, 0.04);
        }

        .footer {
            text-align: center;
            margin-top: 1.25rem;
            font-size: 0.75rem;
            color: #475569;
        }

        @media (max-width: 600px) {
            body {
                padding: 1rem 0.75rem 2rem;
            }
            .doc-sheet {
                padding: 1.25rem 1rem;
            }
            .btn-back {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<div class="hero-bg-layer"></div>

<div class="container">

    <div class="top-nav">
        <a href="<?= BASE_URL ?>auth/login.php" class="back-link">&larr; Return to Sign In</a>
        <span class="system-tag">TALA-AIS</span>
    </div>

    <main class="doc-sheet">
        <header class="doc-header">
            <h1>Terms &amp; Conditions and Privacy Policy</h1>
            <p>Simple guidelines on how to use the system and how your data is protected.</p>
        </header>

        <!-- 1. Terms of Use -->
        <section class="section">
            <h2><span class="num">1.</span> Terms of Use</h2>
            <ul>
                <li><strong>Academic Use Only:</strong> This system is strictly for accounting practice, simulations, and student coursework.</li>
                <li><strong>Account Safety:</strong> Keep your login details private. Do not share your password with others.</li>
                <li><strong>Honesty &amp; Integrity:</strong> Do not attempt to tamper with or alter records that do not belong to you.</li>
            </ul>
        </section>

        <!-- 2. Information We Collect -->
        <section class="section">
            <h2><span class="num">2.</span> Information We Collect</h2>
            <ul>
                <li><strong>Profile Details:</strong> Your name, school email, and account role (Student or Instructor).</li>
                <li><strong>Accounting Records:</strong> Journal entries, chart of accounts, and financial reports you create.</li>
                <li><strong>System Logs:</strong> Login times, IP address, and security logs to protect your account.</li>
            </ul>
        </section>

        <!-- 3. Why We Collect It -->
        <section class="section">
            <h2><span class="num">3.</span> Why We Collect It</h2>
            <p>Your information is used solely for educational simulation and grading by your instructor. It is never sold or shared with any outside party.</p>
        </section>

        <!-- 4. Security & Privacy (RA 10173) -->
        <section class="section">
            <h2><span class="num">4.</span> Security &amp; Privacy (RA 10173)</h2>
            <p>In compliance with the <strong>Philippine Data Privacy Act of 2012 (RA 10173)</strong>:</p>
            <ul>
                <li>Passwords are encrypted and never stored in plain text.</li>
                <li>Sessions automatically log out after 30 minutes of inactivity for workstation safety.</li>
                <li>Students can only access their own assigned company records.</li>
            </ul>
        </section>

        <!-- 5. Your Rights -->
        <section class="section">
            <h2><span class="num">5.</span> Your Rights</h2>
            <p>Under RA 10173, you have the right to view your profile and request corrections if your information is inaccurate.</p>
        </section>

        <!-- 6. Contact -->
        <section class="section">
            <h2><span class="num">6.</span> Contact</h2>
            <p>For questions or account assistance, please contact your course instructor or the system administrator.</p>
        </section>

        <div class="action-bar">
            <a href="<?= BASE_URL ?>auth/login.php" class="btn-back">&larr; Return to Sign In</a>
        </div>
    </main>

    <footer class="footer">
        &copy; <?= date('Y') ?> TALA-AIS &bull; RA 10173 Compliant
    </footer>

</div>

</body>
</html>
