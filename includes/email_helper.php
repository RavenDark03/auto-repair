<?php
declare(strict_types=1);

/**
 * MECHANIX Email Helper
 *
 * Wraps PHPMailer for actual SMTP delivery.
 * Falls back gracefully — only logs to email_logs; no crash if SMTP is unconfigured.
 *
 * Usage:
 *   require_once __DIR__ . '/email_helper.php';
 *   mechanix_send_email($to, $subject, $htmlBody, $registrationId, $emailType, $pdo);
 */

/**
 * Send an HTML email via SMTP (PHPMailer) and update email_logs.
 *
 * @param string      $to             Recipient email address
 * @param string      $subject        Email subject
 * @param string      $htmlBody       Full HTML body
 * @param int|null    $registrationId FK for email_logs (nullable)
 * @param string      $emailType      enum value for email_logs.email_type
 * @param PDO|null    $pdo            Database connection for logging (optional)
 * @param int|null    $logId          If non-null, update this existing email_log row instead of inserting
 * @return bool                       true if delivered, false if only logged
 */
function mechanix_send_email(
    string $to,
    string $subject,
    string $htmlBody,
    ?int $registrationId,
    string $emailType,
    ?PDO $pdo = null,
    ?int $logId = null
): bool {
    // Ensure config constants are available
    if (!defined('SMTP_HOST')) {
        return false;
    }

    $sent = false;
    $sentAt = null;

    // --- Attempt SMTP delivery via PHPMailer if available ---
    $smtpUser = defined('SMTP_USER') ? SMTP_USER : '';
    $smtpHost = SMTP_HOST;

    if ($smtpUser !== '' && $smtpHost !== '' && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $smtpHost;
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtpUser;
            $mail->Password   = defined('SMTP_PASS') ? SMTP_PASS : '';
            $mail->SMTPSecure = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls';
            $mail->Port       = defined('SMTP_PORT') ? (int) SMTP_PORT : 587;

            $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : $smtpUser;
            $fromName  = defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : 'MECHANIX';

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

            $mail->send();
            $sent   = true;
            $sentAt = date('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            // Log failure but don't throw — email is non-critical
            $sent = false;
        }
    }

    // --- Update or insert email_log row ---
    if ($pdo !== null) {
        try {
            if ($logId !== null) {
                $pdo->prepare("
                    UPDATE email_logs
                    SET send_status = :status,
                        sent_at     = :sent_at
                    WHERE email_log_id = :id
                ")->execute([
                    'status'  => $sent ? 'sent' : 'failed',
                    'sent_at' => $sentAt,
                    'id'      => $logId,
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO email_logs
                        (registration_id, recipient_email, subject, body, email_type, send_status, sent_at)
                    VALUES
                        (:registration_id, :recipient_email, :subject, :body, :email_type, :send_status, :sent_at)
                ")->execute([
                    'registration_id' => $registrationId,
                    'recipient_email' => $to,
                    'subject'         => $subject,
                    'body'            => strip_tags($htmlBody),
                    'email_type'      => $emailType,
                    'send_status'     => $sent ? 'sent' : 'pending',
                    'sent_at'         => $sentAt,
                ]);
            }
        } catch (\Throwable $ignored) {
            // DB logging failure is non-fatal
        }
    }

    return $sent;
}

/**
 * Build the branded HTML wrapper used for all outgoing MECHANIX emails.
 *
 * @param string $bodyContent Inner HTML content (cards, paragraphs, buttons)
 * @return string             Full HTML email document
 */
function mechanix_email_html(string $bodyContent): string
{
    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MECHANIX</title>
<style>
  body{margin:0;padding:0;background:#0f1117;font-family:"Segoe UI",Arial,sans-serif;color:#e2e8f0}
  .wrapper{max-width:560px;margin:40px auto;background:#1a1d27;border-radius:16px;overflow:hidden;border:1px solid #2d3148}
  .header{background:linear-gradient(135deg,#6c63ff 0%,#4f46e5 100%);padding:32px 36px;text-align:center}
  .header .brand-mark{display:inline-block;width:48px;height:48px;background:rgba(255,255,255,.15);border-radius:12px;font-size:24px;font-weight:800;color:#fff;line-height:48px;text-align:center;margin-bottom:12px}
  .header h1{margin:0;font-size:22px;font-weight:700;color:#fff;letter-spacing:-.5px}
  .header p{margin:6px 0 0;font-size:13px;color:rgba(255,255,255,.7)}
  .body{padding:32px 36px}
  .body h2{margin:0 0 8px;font-size:20px;font-weight:700;color:#f1f5f9}
  .body p{margin:0 0 16px;font-size:14px;line-height:1.7;color:#94a3b8}
  .btn{display:inline-block;padding:14px 28px;background:linear-gradient(135deg,#6c63ff,#4f46e5);color:#fff!important;text-decoration:none;border-radius:10px;font-size:15px;font-weight:600;letter-spacing:.2px;margin:8px 0}
  .info-box{background:#12141e;border:1px solid #2d3148;border-radius:10px;padding:16px 20px;margin:16px 0}
  .info-box p{margin:0;font-size:13px;color:#64748b}
  .info-box .val{font-size:14px;color:#c7d2fe;font-weight:600;margin-top:4px}
  .divider{border:none;border-top:1px solid #2d3148;margin:24px 0}
  .footer{padding:20px 36px;text-align:center;font-size:12px;color:#374151;background:#12141e}
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <div class="brand-mark">M</div>
    <h1>MECHANIX</h1>
    <p>Subscription-Based Auto Repair Platform</p>
  </div>
  <div class="body">' . $bodyContent . '</div>
  <div class="footer">© ' . date('Y') . ' MECHANIX · This is an automated message, please do not reply.</div>
</div>
</body>
</html>';
}

/**
 * Generate a cryptographically secure URL-safe token.
 */
function mechanix_generate_token(int $bytes = 48): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * Create a magic login token in the login_tokens table.
 *
 * @param PDO $pdo
 * @param int $tenantId
 * @param int $userId
 * @param int $expiryHours  How many hours until the link expires (default 72)
 * @return string           The plain token string to embed in the URL
 */
function mechanix_create_login_token(PDO $pdo, int $tenantId, int $userId, int $expiryHours = 72): string
{
    $token     = mechanix_generate_token(48);
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryHours} hours"));

    $pdo->prepare("
        INSERT INTO login_tokens (tenant_id, user_id, token, purpose, expires_at)
        VALUES (:tenant_id, :user_id, :token, 'verified_login', :expires_at)
    ")->execute([
        'tenant_id'  => $tenantId,
        'user_id'    => $userId,
        'token'      => $token,
        'expires_at' => $expiresAt,
    ]);

    return $token;
}
