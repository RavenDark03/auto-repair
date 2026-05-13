<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/email_helper.php';

echo "Testing SMTP Settings...\n";
echo "SMTP_HOST: " . SMTP_HOST . "\n";
echo "SMTP_USER: " . SMTP_USER . "\n";
echo "SMTP_PORT: " . SMTP_PORT . "\n";
echo "PHPMailer exists: " . (class_exists('PHPMailer\PHPMailer\PHPMailer') ? 'Yes' : 'No') . "\n";

try {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->SMTPDebug = 2; // Enable verbose debug output
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_ENCRYPTION;
    $mail->Port       = SMTP_PORT;

    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress(SMTP_USER); // Send to self
    $mail->Subject = 'SMTP Test';
    $mail->Body    = 'This is a test email to verify SMTP configuration.';

    $mail->send();
    echo "Message has been sent successfully!\n";
} catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}\n";
} catch (\Throwable $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
}
