<?php
// ============================================================
// includes/mailer.php — Lightweight Native SMTP Email Sender
// Works with Brevo, Mailtrap, Hostinger, Gmail, custom SMTP.
// Zero composer / external library dependencies required.
// ============================================================

require_once __DIR__ . '/../config.php';

/**
 * Send an email via SMTP.
 *
 * @param string $toEmail Recipient email
 * @param string $toName Recipient name
 * @param string $subject Email subject line
 * @param string $htmlBody HTML content
 * @param string|null $plainBody Optional plain text fallback
 * @return array ['success' => bool, 'error' => string]
 */
function send_smtp_mail(string $toEmail, string $toName, string $subject, string $htmlBody, ?string $plainBody = null): array {
    $host   = defined('SMTP_HOST') ? SMTP_HOST : '';
    $port   = defined('SMTP_PORT') ? SMTP_PORT : 587;
    $user   = defined('SMTP_USER') ? SMTP_USER : '';
    $pass   = defined('SMTP_PASS') ? SMTP_PASS : '';
    $secure = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'tls';
    $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : $user;
    $fromName  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'TALA-AIS';

    if (empty($host) || empty($user) || empty($pass)) {
        return [
            'success' => false,
            'error' => 'SMTP is not configured yet. Please enter your SMTP Host, Username, and Password in config.php.'
        ];
    }

    $timeout = 15;
    $errorNumber = 0;
    $errorMessage = '';

    // Determine connection protocol
    $remoteSocket = ($secure === 'ssl' || $port == 465) ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);

    $socket = @stream_socket_client($remoteSocket, $errorNumber, $errorMessage, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        return [
            'success' => false,
            'error' => "Failed to connect to SMTP server ({$host}:{$port}): {$errorMessage} (Code: {$errorNumber})"
        ];
    }

    stream_set_timeout($socket, $timeout);

    // Read initial greeting
    $response = smtp_read_response($socket);
    if (!smtp_is_ok($response, [220])) {
        fclose($socket);
        return ['success' => false, 'error' => "SMTP greeting error: " . trim($response)];
    }

    // Send EHLO
    $clientHost = !empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
    smtp_write($socket, "EHLO {$clientHost}");
    $response = smtp_read_response($socket);
    if (!smtp_is_ok($response, [250])) {
        smtp_write($socket, "HELO {$clientHost}");
        $response = smtp_read_response($socket);
        if (!smtp_is_ok($response, [250])) {
            fclose($socket);
            return ['success' => false, 'error' => "EHLO/HELO failed: " . trim($response)];
        }
    }

    // Handle STARTTLS for TLS connections (usually port 587)
    if (($secure === 'tls' || $port == 587) && stripos($remoteSocket, 'ssl://') === false) {
        smtp_write($socket, "STARTTLS");
        $response = smtp_read_response($socket);
        if (!smtp_is_ok($response, [220])) {
            fclose($socket);
            return ['success' => false, 'error' => "STARTTLS failed: " . trim($response)];
        }

        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            fclose($socket);
            return ['success' => false, 'error' => "Failed to establish TLS encryption with SMTP server."];
        }

        // Re-issue EHLO after STARTTLS
        smtp_write($socket, "EHLO {$clientHost}");
        $response = smtp_read_response($socket);
        if (!smtp_is_ok($response, [250])) {
            fclose($socket);
            return ['success' => false, 'error' => "EHLO after TLS failed: " . trim($response)];
        }
    }

    // Authenticate with AUTH LOGIN
    smtp_write($socket, "AUTH LOGIN");
    $response = smtp_read_response($socket);
    if (!smtp_is_ok($response, [334])) {
        fclose($socket);
        return ['success' => false, 'error' => "AUTH LOGIN rejected: " . trim($response)];
    }

    // Send Username
    smtp_write($socket, base64_encode($user));
    $response = smtp_read_response($socket);
    if (!smtp_is_ok($response, [334])) {
        fclose($socket);
        return ['success' => false, 'error' => "SMTP username rejected: " . trim($response)];
    }

    // Send Password
    smtp_write($socket, base64_encode($pass));
    $response = smtp_read_response($socket);
    if (!smtp_is_ok($response, [235])) {
        fclose($socket);
        return ['success' => false, 'error' => "SMTP authentication failed. Please verify your SMTP credentials. Response: " . trim($response)];
    }

    // MAIL FROM
    smtp_write($socket, "MAIL FROM:<{$fromEmail}>");
    $response = smtp_read_response($socket);
    if (!smtp_is_ok($response, [250])) {
        fclose($socket);
        return ['success' => false, 'error' => "MAIL FROM rejected: " . trim($response)];
    }

    // RCPT TO
    smtp_write($socket, "RCPT TO:<{$toEmail}>");
    $response = smtp_read_response($socket);
    if (!smtp_is_ok($response, [250, 251])) {
        fclose($socket);
        return ['success' => false, 'error' => "Recipient rejected ({$toEmail}): " . trim($response)];
    }

    // DATA
    smtp_write($socket, "DATA");
    $response = smtp_read_response($socket);
    if (!smtp_is_ok($response, [354])) {
        fclose($socket);
        return ['success' => false, 'error' => "DATA rejected: " . trim($response)];
    }

    // Build MIME message
    $boundary = "----=_Part_" . md5(uniqid((string)time(), true));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encodedToName = !empty($toName) ? '=?UTF-8?B?' . base64_encode($toName) . '?= ' : '';

    $headers = [];
    $headers[] = "Date: " . date('r');
    $headers[] = "From: {$encodedFromName} <{$fromEmail}>";
    $headers[] = "To: {$encodedToName}<{$toEmail}>";
    $headers[] = "Subject: {$encodedSubject}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
    $headers[] = "X-Mailer: TALA-AIS-Mailer/1.0";

    if ($plainBody === null) {
        $plainBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));
    }

    $body  = implode("\r\n", $headers) . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($plainBody)) . "\r\n";

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";

    $body .= "--{$boundary}--\r\n";

    // Standard SMTP line endings & dot stuffing
    $body = str_replace("\r\n.", "\r\n..", $body);
    smtp_write($socket, $body . "\r\n.");

    $response = smtp_read_response($socket);
    if (!smtp_is_ok($response, [250])) {
        fclose($socket);
        return ['success' => false, 'error' => "Message body rejected: " . trim($response)];
    }

    // QUIT
    smtp_write($socket, "QUIT");
    fclose($socket);

    return ['success' => true, 'error' => ''];
}

/**
 * Send OTP Verification Email with a responsive HTML template.
 */
function send_otp_email(string $recipientEmail, string $recipientName, string $otpCode): array {
    $subject = "Your Password Reset OTP: {$otpCode} - TALA-AIS";

    $nameDisplay = !empty($recipientName) ? htmlspecialchars($recipientName) : 'User';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Password Reset OTP</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0b1329; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #e2e8f0;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #0b1329; padding: 40px 15px;">
    <tr>
      <td align="center">
        <table width="100%" max-width="540px" cellpadding="0" cellspacing="0" style="max-width: 540px; background: #0f1c3f; border: 1px solid rgba(59, 130, 246, 0.35); border-radius: 20px; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.6);">
          <!-- Header -->
          <tr>
            <td style="padding: 30px 30px 20px; text-align: center; background: linear-gradient(180deg, rgba(37, 99, 235, 0.2) 0%, transparent 100%);">
              <div style="display: inline-block; padding: 10px 18px; border-radius: 9999px; background: rgba(37, 99, 235, 0.15); border: 1px solid rgba(59, 130, 246, 0.4); margin-bottom: 12px;">
                <span style="color: #60a5fa; font-size: 13px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase;">Accounting Information System</span>
              </div>
              <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 800; letter-spacing: -0.02em;">Password Reset Verification</h1>
            </td>
          </tr>

          <!-- Content -->
          <tr>
            <td style="padding: 20px 35px 35px;">
              <p style="margin: 0 0 16px; font-size: 15px; line-height: 1.6; color: #cbd5e1;">
                Hello <strong>{$nameDisplay}</strong>,
              </p>
              <p style="margin: 0 0 24px; font-size: 14px; line-height: 1.6; color: #94a3b8;">
                We received a request to reset the password for your <strong>TALA-AIS</strong> account. Use the 6-digit One-Time Password (OTP) below to verify your identity:
              </p>

              <!-- OTP Code Display -->
              <div style="background: rgba(6, 10, 18, 0.85); border: 2px dashed #3b82f6; border-radius: 14px; padding: 22px; text-align: center; margin-bottom: 24px;">
                <span style="font-family: 'Courier New', Courier, monospace; font-size: 38px; font-weight: 800; letter-spacing: 12px; color: #38bdf8; display: inline-block; padding-left: 12px;">{$otpCode}</span>
                <div style="margin-top: 8px; font-size: 12px; color: #94a3b8; letter-spacing: 0.05em;">
                  This code expires in <strong style="color: #f59e0b;">15 minutes</strong>.
                </div>
              </div>

              <p style="margin: 0 0 20px; font-size: 13px; line-height: 1.6; color: #94a3b8;">
                ⚠️ <strong>Security Notice:</strong> Never share this code with anyone. TALA-AIS administrators will never ask for your OTP. If you did not request this password reset, please ignore this email; your account remains secure.
              </p>

              <hr style="border: none; border-top: 1px solid rgba(255, 255, 255, 0.1); margin: 25px 0;">

              <p style="margin: 0; font-size: 12px; color: #64748b; text-align: center;">
                &copy; 2026 TALA-AIS. All rights reserved. Automated security notification.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

    return send_smtp_mail($recipientEmail, $recipientName, $subject, $html);
}

// ── Low-Level SMTP Helpers ──────────────────────────────────────────

function smtp_write($socket, string $data): void {
    fwrite($socket, $data . "\r\n");
}

function smtp_read_response($socket): string {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 1024);
        if ($line === false) break;
        $response .= $line;
        // Check for multi-line response (continuation line has hyphen, e.g. 250-)
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }
    return $response;
}

function smtp_is_ok(string $response, array $validCodes): bool {
    if (preg_match('/^(\d{3})/', trim($response), $matches)) {
        $code = (int)$matches[1];
        return in_array($code, $validCodes, true);
    }
    return false;
}
