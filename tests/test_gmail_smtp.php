<?php
/**
 * Diagnostic Test Script: Real Gmail SMTP Delivery Test
 *
 * Usage:
 *   php tests/test_gmail_smtp.php [recipient@example.com]
 *
 * Requirements:
 *   Populate SMTP_PASSWORD in .env (App Password from Google Account).
 *   This script attempts a real email send and outputs status without exposing secrets.
 */

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../auth/send_mail.php';

echo "====================================================\n";
echo "   Gmail SMTP Diagnostic & Delivery Test            \n";
echo "====================================================\n\n";

$targetEmail = $argv[1] ?? getenv('SENDER_EMAIL') ?: 'mailproject112@gmail.com';

$driver     = getenv('MAIL_DRIVER') ?: 'not_set';
$host       = getenv('SMTP_HOST') ?: 'not_set';
$port       = getenv('SMTP_PORT') ?: 'not_set';
$encryption = getenv('SMTP_ENCRYPTION') ?: getenv('SMTP_SECURE') ?: 'not_set';
$username   = getenv('SMTP_USERNAME') ?: getenv('SMTP_USER') ?: 'not_set';
$hasPassword = !empty(getenv('SMTP_PASSWORD') ?: getenv('SMTP_PASS'));
$sender     = getenv('SENDER_EMAIL') ?: 'not_set';

echo "Configuration Check:\n";
echo "  MAIL_DRIVER:     {$driver}\n";
echo "  SMTP_HOST:       {$host}\n";
echo "  SMTP_PORT:       {$port}\n";
echo "  SMTP_ENCRYPTION: {$encryption}\n";
echo "  SMTP_USERNAME:   {$username}\n";
echo "  SMTP_PASSWORD:   " . ($hasPassword ? "[CONFIGURED (" . strlen(getenv('SMTP_PASSWORD') ?: getenv('SMTP_PASS')) . " chars)]" : "[MISSING / EMPTY]") . "\n";
echo "  SENDER_EMAIL:    {$sender}\n";
echo "  Target Recipient: {$targetEmail}\n\n";

if (!$hasPassword) {
    echo "❌ ERROR: SMTP_PASSWORD is missing or empty in .env.\n";
    echo "   Please set SMTP_PASSWORD=<your 16-char Gmail App Password> in .env before running this test.\n";
    exit(1);
}

echo "Attempting real SMTP mail send via PHPMailer...\n";

// Force MAIL_DRIVER=smtp for diagnostic run
putenv("MAIL_DRIVER=smtp");

$otp = rand(100000, 999999);
$subject = "Gmail SMTP Test - Online Examination System";
$body = buildOtpEmailHtml("Gmail SMTP Diagnostic Test", $otp, 10);

$startTime = microtime(true);
$sent = sendMail($targetEmail, $subject, $body);
$duration = round(microtime(true) - $startTime, 2);

if ($sent) {
    echo "✅ SUCCESS: Real email delivered to {$targetEmail} via Gmail SMTP in {$duration} seconds.\n";
    echo "   OTP Sent: {$otp}\n";
    exit(0);
} else {
    echo "❌ FAILURE: Real SMTP mail send failed after {$duration} seconds.\n";
    echo "   Check PHP error logs or logs/mail.log for sanitized error diagnostics.\n";
    exit(1);
}
