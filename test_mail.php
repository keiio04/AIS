<?php
// test_mail.php — Immediate Email Diagnostics for Railway / Localhost
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/mailer.php';

header('Content-Type: text/html; charset=utf-8');

$to = $_GET['to'] ?? 'catrinaguevarra19@gmail.com';

echo "<!DOCTYPE html><html><head><title>TALA Mail Diagnostic</title><style>body{font-family:monospace;background:#0b1329;color:#e2e8f0;padding:2rem;} .box{background:#111c3a;padding:1.5rem;border-radius:10px;border:1px solid #3b82f6;max-width:700px;margin:auto;} h2{color:#38bdf8;} pre{background:#060a12;padding:1rem;border-radius:6px;overflow-x:auto;color:#a5f3fc;} .ok{color:#4ade80;} .bad{color:#f87171;}</style></head><body><div class='box'>";
echo "<h2>🔍 TALA-AIS Email Diagnostics</h2>";

$rawKey = '';
if (defined('BREVO_API_KEY') && !empty(BREVO_API_KEY)) {
    $rawKey = BREVO_API_KEY;
} elseif (function_exists('app_env')) {
    $rawKey = app_env('BREVO_API_KEY', app_env('BREVO_APT_KEY', ''));
}
if (empty($rawKey)) {
    $rawKey = getenv('BREVO_API_KEY') ?: ($_ENV['BREVO_API_KEY'] ?? ($_SERVER['BREVO_API_KEY'] ?? ''));
}

$maskedKey = $rawKey ? (substr($rawKey, 0, 12) . '...' . substr($rawKey, -6)) : '<EMPTY>';
$fromEmail = defined('SMTP_FROM_EMAIL') && SMTP_FROM_EMAIL ? SMTP_FROM_EMAIL : (app_env('SMTP_FROM_EMAIL') ?: 'catrinaguevarra19@gmail.com');

echo "<p><b>BREVO_API_KEY:</b> <code>{$maskedKey}</code></p>";
echo "<p><b>Sender Email (From):</b> <code>{$fromEmail}</code></p>";
echo "<p><b>Recipient Email (To):</b> <code>{$to}</code></p>";

if (empty($rawKey)) {
    echo "<p class='bad'>❌ BREVO_API_KEY was not detected in environment variables.</p>";
} else {
    echo "<p class='ok'>✅ BREVO_API_KEY detected! Testing Brevo HTTPS API (Port 443)...</p>";
    
    // Direct raw test to Brevo API to see full payload and response
    $payload = [
        'sender' => [
            'name'  => 'TALA-AIS Security Test',
            'email' => $fromEmail,
        ],
        'to' => [
            ['email' => $to, 'name' => 'Recipient']
        ],
        'subject'     => 'TALA-AIS Live Test: ' . date('H:i:s'),
        'htmlContent' => '<h3>Live Test</h3><p>Your TALA-AIS email integration is working perfectly!</p>',
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . trim($rawKey),
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    echo "<p><b>HTTP Status Code:</b> <code>{$httpCode}</code></p>";
    if ($curlErr) {
        echo "<p class='bad'>cURL Error: {$curlErr}</p>";
    }
    
    echo "<p><b>Raw Brevo Response:</b></p>";
    echo "<pre>" . htmlspecialchars((string)$response) . "</pre>";
    
    if ($httpCode >= 200 && $httpCode < 300) {
        echo "<h3 class='ok'>🎉 SUCCESS! Email dispatched by Brevo. Check your inbox and spam folder.</h3>";
    } else {
        echo "<h3 class='bad'>⚠️ Brevo rejected the email. Check the error message above.</h3>";
    }
}

echo "<hr style='border-color:#1e3a8a;margin:20px 0;'>";
echo "<p><a href='auth/login.php' style='color:#38bdf8;'>&larr; Back to Login</a></p>";
echo "</div></body></html>";
