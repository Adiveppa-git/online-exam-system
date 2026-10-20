<?php
require_once __DIR__ . '/../config/env_loader.php';

if (!defined('OTP_REQUEST_COOLDOWN_SECONDS')) {
    define('OTP_REQUEST_COOLDOWN_SECONDS', 60);
}
if (!defined('OTP_MAX_VERIFICATION_ATTEMPTS')) {
    define('OTP_MAX_VERIFICATION_ATTEMPTS', 5);
}

// Include PHPMailer classes from vendor if available
$phpmailerException = __DIR__ . '/../vendor/PHPMailer/src/Exception.php';
$phpmailerMain      = __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
$phpmailerSmtp      = __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';

if (file_exists($phpmailerException) && file_exists($phpmailerMain) && file_exists($phpmailerSmtp)) {
    require_once $phpmailerException;
    require_once $phpmailerMain;
    require_once $phpmailerSmtp;
}

$GLOBALS['brevoHttpHandler'] = null;
$GLOBALS['smtpHandler']      = null;
$GLOBALS['last_mail_error']  = null;

/**
 * Get the last sanitized mail error message (if any).
 *
 * @return string|null
 */
function getLastMailError()
{
    return $GLOBALS['last_mail_error'] ?? null;
}

/**
 * Get the sanitized user-facing mail error message (if any).
 * Never exposes technical, network, or credential details to end users.
 *
 * @return string|null
 */
function getUserFacingMailError()
{
    if (!empty($GLOBALS['last_mail_error'])) {
        return "Email service is temporarily unavailable. Please try again later.";
    }
    return null;
}

/**
 * Set a mock HTTP handler for testing Brevo mail delivery without network requests.
 *
 * @param callable|null $handler Callback taking ($url, $headers, $payloadJson) and returning ['code' => int, 'response' => string, 'error' => string|null]
 */
function setBrevoHttpHandler(?callable $handler)
{
    $GLOBALS['brevoHttpHandler'] = $handler;
}

/**
 * Set a mock handler for testing SMTP mail delivery without network requests.
 *
 * @param callable|null $handler Callback taking ($to, $subject, $body, $config)
 */
function setSmtpHandler(?callable $handler)
{
    $GLOBALS['smtpHandler'] = $handler;
}

/**
 * Helper to build standardized, high-deliverability subject line for OTP verification.
 * Avoids starting with raw numbers (which triggers spam filters' SUBJECT_STARTS_WITH_NUM rule).
 *
 * @param string $purpose Human-readable purpose (e.g. "Password Reset")
 * @param string|int $otp 6-digit OTP code
 * @return string Email subject line
 */
function buildOtpEmailSubject($purpose, $otp)
{
    $appName = "Online Examination System";
    $safePurpose = trim((string)$purpose);
    $safeOtp = trim((string)$otp);
    return "Your {$appName} {$safePurpose} Code: {$safeOtp}";
}

/**
 * Helper to build plain-text alternative (AltBody) for OTP verification emails.
 * Crucial for spam filter scoring (multipart/alternative requirement) and accessibility.
 *
 * @param string $purpose Human-readable purpose
 * @param string|int $otp 6-digit OTP code
 * @param int $expiryMinutes Expiration duration in minutes
 * @return string Plain-text email content
 */
function buildOtpEmailText($purpose, $otp, $expiryMinutes = 10)
{
    $appName = "Online Examination System";
    $safePurpose = trim((string)$purpose);
    $safeOtp = trim((string)$otp);
    $year = date('Y');

    return "{$appName}\n"
         . str_repeat("=", strlen($appName)) . "\n\n"
         . "{$safePurpose}\n\n"
         . "Your One-Time Password (OTP) verification code is:\n\n"
         . "    {$safeOtp}\n\n"
         . "This verification code is valid for {$expiryMinutes} minutes.\n\n"
         . "SECURITY WARNING:\n"
         . "Please do not share this OTP with anyone. Our staff and administrators will never ask for your verification code.\n\n"
         . "If you did not request this verification code, please disregard this email. Your account remains secure and no changes have been made.\n\n"
         . "--------------------------------------------------\n"
         . "This is an automated security message from {$appName}.\n"
         . "(c) {$year} {$appName}. All rights reserved.\n";
}

/**
 * Helper to convert HTML email content into clean, readable plain-text alternative
 *
 * @param string $html HTML email body
 * @return string Plain-text fallback
 */
function convertHtmlToCleanPlainText($html)
{
    $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
    $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<\/(p|div|tr|h1|h2|h3|h4|table)>/i', "\n", $text);
    $text = preg_replace('/<td[^>]*>/i', " ", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+/", " ", $text);
    $text = preg_replace("/\n\s+\n/", "\n\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

/**
 * Helper to build professional, high-deliverability HTML email for OTP verification.
 * Follows email deliverability standards:
 *  - Hidden preheader text to prevent awkward preview snippets (without text-hiding or zero-width tricks).
 *  - Table-based wrapper for 100% rendering compatibility in Microsoft Outlook, Gmail, Apple Mail, and mobile clients.
 *  - High-contrast, letter-spaced monospace digits for quick reading.
 *  - Clear security warnings and unsolicited-request guidance to prevent spam complaints.
 *  - Standard footer with transactional notices.
 *
 * @param string $purpose Human-readable purpose (e.g. "Email Verification" or "Password Reset")
 * @param string|int $otp 6-digit OTP code
 * @param int $expiryMinutes Expiration duration in minutes
 * @return string HTML email content
 */
function buildOtpEmailHtml($purpose, $otp, $expiryMinutes = 10)
{
    $appName = "Online Examination System";
    $safePurpose = htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars((string)$otp, ENT_QUOTES, 'UTF-8');
    $year = date('Y');
    $preheader = "Your {$safePurpose} code is {$safeOtp}. It expires in {$expiryMinutes} minutes.";

    return "<!DOCTYPE html PUBLIC '-//W3C//DTD XHTML 1.0 Transitional//EN' 'http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd'>
<html xmlns='http://www.w3.org/1999/xhtml' lang='en'>
<head>
    <meta http-equiv='Content-Type' content='text/html; charset=UTF-8' />
    <meta name='viewport' content='width=device-width, initial-scale=1.0' />
    <meta name='x-apple-disable-message-reformatting' />
    <meta http-equiv='X-UA-Compatible' content='IE=edge' />
    <title>{$safePurpose}</title>
</head>
<body style='margin: 0; padding: 0; background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;'>
    <!-- Hidden Preheader Preview Text: clean, no font-color matching background or zero-width character tricks -->
    <span style='display: none !important; visibility: hidden; mso-hide: all; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden;'>
        {$preheader}
    </span>

    <!-- Outer Presentation Table -->
    <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color: #f4f6f9; width: 100%; margin: 0; padding: 30px 10px;'>
        <tr>
            <td align='center' valign='top'>
                <!-- Inner Card Container -->
                <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='max-width: 560px; width: 100%; background-color: #ffffff; border-radius: 8px; border: 1px solid #e5e7eb; box-shadow: 0 4px 12px rgba(0,0,0,0.05); overflow: hidden;'>
                    <!-- Brand Header -->
                    <tr>
                        <td align='center' style='background-color: #0d6efd; padding: 26px 20px 22px 20px;'>
                            <h1 style='margin: 0; font-size: 22px; font-weight: 700; color: #ffffff; letter-spacing: -0.2px;'>{$appName}</h1>
                            <p style='margin: 6px 0 0 0; font-size: 14px; color: #e0edff; font-weight: 500;'>{$safePurpose}</p>
                        </td>
                    </tr>
                    <!-- Main Body Content -->
                    <tr>
                        <td style='padding: 34px 30px; text-align: center; color: #1f2937;'>
                            <p style='margin: 0 0 16px 0; font-size: 15px; line-height: 1.5; color: #374151;'>
                                We received a request to complete <strong>{$safePurpose}</strong> for your account. Use the verification code below to proceed:
                            </p>
                            <!-- High-Contrast OTP Code Box -->
                            <div style='margin: 24px auto 20px auto; display: inline-block; background-color: #f0f5ff; border: 2px dashed #0d6efd; border-radius: 8px; padding: 14px 32px;'>
                                <span style='font-family: \"Courier New\", Courier, monospace; font-size: 34px; font-weight: 700; letter-spacing: 8px; color: #0d6efd; display: block;'>{$safeOtp}</span>
                            </div>
                            <p style='margin: 0 0 24px 0; font-size: 14px; color: #4b5563;'>
                                This code is valid for <strong>{$expiryMinutes} minutes</strong>.
                            </p>
                            <!-- Security Warning Box -->
                            <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #f59e0b; border-radius: 6px; margin-bottom: 20px;'>
                                <tr>
                                    <td style='padding: 12px 14px; text-align: left; font-size: 13px; line-height: 1.5; color: #92400e;'>
                                        <strong>Security Warning:</strong> Never share this code with anyone. Our administrators and support team will never ask for your verification code.
                                    </td>
                                </tr>
                            </table>
                            <!-- Unsolicited Request Note -->
                            <p style='margin: 0; font-size: 13px; line-height: 1.5; color: #6b7280; text-align: center;'>
                                If you did not request this verification code, please ignore this email. Your account remains completely secure and no changes have been made.
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td align='center' style='background-color: #f9fafb; padding: 18px 20px; border-top: 1px solid #f3f4f6; font-size: 12px; line-height: 1.5; color: #9ca3af;'>
                            This is an automated security notification regarding your {$appName} account.<br />
                            &copy; {$year} {$appName}. All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";
}

/**
 * Multi-Driver Mail Service Module
 * Handles outbound email delivery via SMTP (PHPMailer), Brevo REST API, native PHP mail(), or local development logger.
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject line
 * @param string $body Email HTML content
 * @param string|null $textBody Optional plain-text alternative for MIME multipart/alternative
 * @return bool True if mail was sent or logged in dev mode, false on failure
 */
function sendMail($to, $subject, $body, $textBody = null)
{
    $to = trim((string)$to);
    $GLOBALS['last_mail_error'] = null;

    if (session_status() === PHP_SESSION_ACTIVE || (session_status() === PHP_SESSION_NONE && !headers_sent())) {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        if (isset($_SESSION)) {
            unset($_SESSION['mail_sent_mode']);
            unset($_SESSION['mail_sent_success']);
        }
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['last_mail_error'] = "Invalid recipient email address.";
        error_log("[MAIL ERROR] Invalid recipient email address: " . substr($to, 0, 50));
        return false;
    }

    // Auto-generate plain-text alternative if not explicitly supplied
    if ($textBody === null || trim((string)$textBody) === '') {
        $textBody = convertHtmlToCleanPlainText($body);
    }

    $driver = strtolower(getenv('MAIL_DRIVER') ?: 'auto');
    $appEnv = strtolower(getenv('APP_ENV') ?: 'development');

    // 1. Explicit SMTP driver
    if ($driver === 'smtp') {
        return sendSmtpMail($to, $subject, $body, $textBody);
    }

    // 2. Explicit Brevo driver
    if ($driver === 'brevo') {
        return sendBrevoMail($to, $subject, $body, $textBody);
    }

    // 3. Auto-detection mode
    if ($driver === 'auto') {
        $smtpHost = getenv('SMTP_HOST');
        $smtpUser = getenv('SMTP_USERNAME') ?: getenv('SMTP_USER');
        if (!empty($smtpHost) && !empty($smtpUser)) {
            return sendSmtpMail($to, $subject, $body, $textBody);
        }

        $brevoKey = getenv('BREVO_API_KEY');
        if (!empty($brevoKey) && trim($brevoKey) !== '') {
            return sendBrevoMail($to, $subject, $body, $textBody);
        }
    }

    // 4. Explicit native PHP mail() driver
    if ($driver === 'mail') {
        return sendNativeMail($to, $subject, $body, $textBody);
    }

    // 5. Explicit log driver or local development fallback when driver === 'auto'
    if ($driver === 'log' || ($driver === 'auto' && in_array($appEnv, ['development', 'local', 'dev'], true))) {
        if (in_array($appEnv, ['development', 'local', 'dev'], true)) {
            return sendDevLogMail($to, $subject, $body);
        } else {
            $GLOBALS['last_mail_error'] = "Log mail driver rejected in non-development environment.";
            error_log("[SECURITY ALERT] Log mail driver requested in non-development environment ($appEnv). Delivery rejected.");
            return false;
        }
    }

    // 6. Production Graceful Failure (No valid mail driver or credentials configured)
    $GLOBALS['last_mail_error'] = "Production mail service unconfigured or credentials missing.";
    error_log("[MAIL ERROR] Production mail service unconfigured or credentials missing.");
    return false;
}

/**
 * Brevo REST API Mail Driver
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject line
 * @param string $body Email HTML content
 * @param string|null $textBody Plain-text alternative
 * @return bool
 */
function sendBrevoMail($to, $subject, $body, $textBody = null)
{
    $apiKey = trim(getenv("BREVO_API_KEY") ?: '');
    if (empty($apiKey)) {
        $GLOBALS['last_mail_error'] = "Brevo API Key missing or empty.";
        error_log("[MAIL ERROR] Brevo API Key missing or empty.");
        return false;
    }

    $senderEmail = trim(getenv('SENDER_EMAIL') ?: (getenv('MAIL_FROM') ?: ''));
    $senderName  = trim(getenv('SENDER_NAME') ?: (getenv('MAIL_FROM_NAME') ?: 'Online Examination System'));

    if (empty($senderEmail)) {
        $smtpUser = trim(getenv('SMTP_USERNAME') ?: (getenv('SMTP_USER') ?: ''));
        if (!empty($smtpUser) && filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
            $senderEmail = $smtpUser;
        }
    }

    if (empty($senderEmail) || $senderEmail === 'noreply@example.com' || strpos($senderEmail, 'example.com') !== false || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['last_mail_error'] = "Invalid or placeholder SENDER_EMAIL configured.";
        error_log("[MAIL ERROR] Invalid or placeholder SENDER_EMAIL configured ('$senderEmail'). Real mail delivery requires a valid verified sender email address.");
        return false;
    }

    if ($textBody === null || trim((string)$textBody) === '') {
        $textBody = convertHtmlToCleanPlainText($body);
    }

    $data = [
        "sender" => [
            "name"  => $senderName,
            "email" => $senderEmail
        ],
        "to" => [
            ["email" => $to]
        ],
        "replyTo" => [
            "name"  => $senderName,
            "email" => $senderEmail
        ],
        "subject" => $subject,
        "htmlContent" => $body,
        "textContent" => $textBody,
        "headers" => [
            "Auto-Submitted"           => "auto-generated",
            "X-Auto-Response-Suppress" => "All"
        ]
    ];

    $payloadJson = json_encode($data);
    $url = "https://api.brevo.com/v3/smtp/email";
    $headers = [
        "api-key: $apiKey",
        "Content-Type: application/json"
    ];

    // Check for testing mock HTTP handler override
    if (isset($GLOBALS['brevoHttpHandler']) && is_callable($GLOBALS['brevoHttpHandler'])) {
        $mockRes = call_user_func($GLOBALS['brevoHttpHandler'], $url, $headers, $payloadJson);
        $httpCode = (int)($mockRes['code'] ?? 500);
        $response = $mockRes['response'] ?? '';
        $error    = $mockRes['error'] ?? null;

        if ($error) {
            $GLOBALS['last_mail_error'] = "Brevo network error: " . $error;
            error_log("[MAIL ERROR] Brevo mock cURL network error: " . $error);
            return false;
        }

        if ($httpCode === 201) {
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                session_start();
            }
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            $_SESSION['mail_sent_mode'] = 'real';
            $_SESSION['mail_sent_success'] = true;
            return true;
        } else {
            if (isset($_SESSION)) {
                unset($_SESSION['mail_sent_mode']);
                unset($_SESSION['mail_sent_success']);
            }
            $GLOBALS['last_mail_error'] = "Brevo API delivery failed with HTTP status code: " . $httpCode;
            error_log("[MAIL ERROR] Brevo API delivery failed with HTTP status code: " . $httpCode);
            return false;
        }
    }

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        if (isset($_SESSION)) {
            unset($_SESSION['mail_sent_mode']);
            unset($_SESSION['mail_sent_success']);
        }
        $GLOBALS['last_mail_error'] = "Brevo cURL network error: " . curl_error($ch);
        error_log("[MAIL ERROR] Brevo cURL network error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 201) {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['mail_sent_mode'] = 'real';
        $_SESSION['mail_sent_success'] = true;
        return true;
    } else {
        if (isset($_SESSION)) {
            unset($_SESSION['mail_sent_mode']);
            unset($_SESSION['mail_sent_success']);
        }
        $sanitizedResponse = substr(strip_tags($response ?? ''), 0, 200);
        $GLOBALS['last_mail_error'] = "Brevo API delivery failed (HTTP " . $httpCode . "): " . $sanitizedResponse;
        error_log("[MAIL ERROR] Brevo API delivery failed with HTTP status code: " . $httpCode . " Summary: " . $sanitizedResponse);
        return false;
    }
}

/**
 * PHPMailer SMTP Mail Driver (Gmail SMTP / Standard Authenticated SMTP)
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject line
 * @param string $body Email HTML content
 * @param string|null $textBody Plain-text alternative
 * @return bool
 */
function sendSmtpMail($to, $subject, $body, $textBody = null)
{
    $host        = trim(getenv('SMTP_HOST') ?: 'smtp.gmail.com');
    $port        = (int)(getenv('SMTP_PORT') ?: 587);
    $encryption  = strtolower(trim(getenv('SMTP_ENCRYPTION') ?: (getenv('SMTP_SECURE') ?: 'tls')));
    $username    = trim(getenv('SMTP_USERNAME') ?: (getenv('SMTP_USER') ?: ''));
    $password    = trim(getenv('SMTP_PASSWORD') ?: (getenv('SMTP_PASS') ?: ''));
    $senderEmail = trim(getenv('SENDER_EMAIL') ?: (getenv('MAIL_FROM') ?: ''));
    $senderName  = trim(getenv('SENDER_NAME') ?: (getenv('MAIL_FROM_NAME') ?: 'Online Examination System'));

    // Strip internal spaces if App Password was pasted with spaces (e.g. "abcd efgh ijkl mnop")
    $password = str_replace(' ', '', $password);

    if (empty($senderEmail)) {
        if (!empty($username) && filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $senderEmail = $username;
        } else {
            $senderEmail = 'mailproject112@gmail.com';
        }
    }

    if (empty($host)) {
        $GLOBALS['last_mail_error'] = "SMTP_HOST is not configured in local .env.";
        error_log("[MAIL ERROR] SMTP_HOST is not configured.");
        return false;
    }

    if (empty($username) || empty($password)) {
        $GLOBALS['last_mail_error'] = "SMTP_PASSWORD is not configured in the local .env.";
        error_log("[MAIL ERROR] SMTP authentication credentials missing.");
        return false;
    }

    if ($senderEmail === 'noreply@example.com' || strpos($senderEmail, 'example.com') !== false || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['last_mail_error'] = "Invalid or placeholder SENDER_EMAIL configured ('$senderEmail').";
        error_log("[MAIL ERROR] Invalid or placeholder SENDER_EMAIL configured ('$senderEmail'). Real mail delivery requires a valid verified sender email address.");
        return false;
    }

    if ($textBody === null || trim((string)$textBody) === '') {
        $textBody = convertHtmlToCleanPlainText($body);
    }

    // Check for testing mock SMTP handler override
    if (isset($GLOBALS['smtpHandler']) && is_callable($GLOBALS['smtpHandler'])) {
        $mockRes = call_user_func($GLOBALS['smtpHandler'], $to, $subject, $body, [
            'host'        => $host,
            'port'        => $port,
            'encryption'  => $encryption,
            'username'    => $username,
            'password'    => $password,
            'senderEmail' => $senderEmail,
            'senderName'  => $senderName,
            'textBody'    => $textBody,
            'headers'     => [
                'Auto-Submitted'           => 'auto-generated',
                'X-Auto-Response-Suppress' => 'All',
                'X-Mailer'                 => 'Online Examination System Mailer',
                'Reply-To'                 => "{$senderName} <{$senderEmail}>"
            ]
        ]);

        if (is_bool($mockRes)) {
            if ($mockRes === true) {
                if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                    session_start();
                }
                if (!isset($_SESSION)) {
                    $_SESSION = [];
                }
                $_SESSION['mail_sent_mode'] = 'real';
                $_SESSION['mail_sent_success'] = true;
                return true;
            }
            if (isset($_SESSION)) {
                unset($_SESSION['mail_sent_mode']);
                unset($_SESSION['mail_sent_success']);
            }
            $GLOBALS['last_mail_error'] = "SMTP mock delivery returned false.";
            return false;
        }

        if (is_array($mockRes)) {
            if (!empty($mockRes['success'])) {
                if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                    session_start();
                }
                if (!isset($_SESSION)) {
                    $_SESSION = [];
                }
                $_SESSION['mail_sent_mode'] = 'real';
                $_SESSION['mail_sent_success'] = true;
                return true;
            }
            if (isset($_SESSION)) {
                unset($_SESSION['mail_sent_mode']);
                unset($_SESSION['mail_sent_success']);
            }
            if (!empty($mockRes['error'])) {
                $GLOBALS['last_mail_error'] = "SMTP mock error: " . $mockRes['error'];
                error_log("[MAIL ERROR] SMTP mock error: " . $mockRes['error']);
            }
            return false;
        }
        if (isset($_SESSION)) {
            unset($_SESSION['mail_sent_mode']);
            unset($_SESSION['mail_sent_success']);
        }
        return false;
    }

    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        if (isset($_SESSION)) {
            unset($_SESSION['mail_sent_mode']);
            unset($_SESSION['mail_sent_success']);
        }
        $GLOBALS['last_mail_error'] = "PHPMailer class unavailable on server.";
        error_log("[MAIL ERROR] PHPMailer class unavailable.");
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;

        if ($encryption === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->Port       = $port;
        $mail->setFrom($senderEmail, $senderName);
        $mail->addReplyTo($senderEmail, $senderName);
        $mail->Sender     = $senderEmail; // Set envelope sender (Return-Path) to match From for SPF alignment

        // Set Hostname for Message-ID to prevent Message-ID: <...@localhost> spam trigger
        $senderParts = explode('@', (string)$senderEmail);
        $mail->Hostname   = !empty($senderParts[1]) ? strtolower(trim($senderParts[1])) : 'gmail.com';

        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject    = $subject;
        $mail->Body       = $body;
        $mail->AltBody    = $textBody;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'quoted-printable';

        // Transactional & anti-spam deliverability headers
        // Crucial: DO NOT add 'Precedence: bulk'! OTPs are 1-to-1 security notices.
        $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
        $mail->XMailer    = 'Online Examination System Mailer';

        // Windows/XAMPP stream context SSL options fallback for local environment compatibility
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ];

        $mail->send();

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['mail_sent_mode'] = 'real';
        $_SESSION['mail_sent_success'] = true;
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        if (isset($_SESSION)) {
            unset($_SESSION['mail_sent_mode']);
            unset($_SESSION['mail_sent_success']);
        }
        $cleanError = str_replace([$password, $username], ['***', '***'], $e->getMessage());
        $cleanError = substr(strip_tags($cleanError), 0, 200);
        $GLOBALS['last_mail_error'] = "Gmail SMTP delivery failed: " . $cleanError;
        error_log("[MAIL ERROR] PHPMailer SMTP delivery failed: " . $cleanError);
        return false;
    } catch (Throwable $e) {
        if (isset($_SESSION)) {
            unset($_SESSION['mail_sent_mode']);
            unset($_SESSION['mail_sent_success']);
        }
        $cleanError = str_replace([$password, $username], ['***', '***'], $e->getMessage());
        $cleanError = substr(strip_tags($cleanError), 0, 200);
        $GLOBALS['last_mail_error'] = "Unexpected SMTP delivery failure: " . $cleanError;
        error_log("[MAIL ERROR] Unexpected SMTP delivery failure: " . $cleanError);
        return false;
    }
}

/**
 * Native PHP mail() Driver
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject line
 * @param string $body Email HTML content
 * @param string|null $textBody Plain-text alternative
 * @return bool
 */
function sendNativeMail($to, $subject, $body, $textBody = null)
{
    $from = getenv('SENDER_EMAIL') ?: "noreply@example.com";
    $name = getenv('SENDER_NAME')  ?: "Online Examination System";

    if ($textBody === null || trim((string)$textBody) === '') {
        $textBody = convertHtmlToCleanPlainText($body);
    }

    $boundary = "----=_NextPart_" . md5(uniqid((string)time(), true));

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "From: {$name} <{$from}>\r\n";
    $headers .= "Reply-To: {$name} <{$from}>\r\n";
    $headers .= "Auto-Submitted: auto-generated\r\n";
    $headers .= "X-Auto-Response-Suppress: All\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $message  = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= $textBody . "\r\n\r\n";
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= $body . "\r\n\r\n";
    $message .= "--{$boundary}--\r\n";

    return @mail($to, $subject, $message, $headers);
}

/**
 * Local Development Logging Driver (Development / Local Environments Only)
 */
function sendDevLogMail($to, $subject, $body)
{
    $appEnv = strtolower(getenv('APP_ENV') ?: 'development');
    if (!in_array($appEnv, ['development', 'local', 'dev'], true)) {
        error_log("[SECURITY ALERT] Development log mail driver executed in non-development environment ($appEnv). Rejected.");
        return false;
    }

    // Extract OTP digits if present
    $otp = null;
    if (preg_match('/(\d{6})/', $body, $matches)) {
        $otp = $matches[1];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['dev_last_otp'] = $otp;
            $_SESSION['mail_sent_mode'] = 'log';
            $_SESSION['mail_sent_success'] = true;
        }
    }

    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    // Deny direct web access to logs directory
    $htaccess = $logDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }

    $indexPhp = $logDir . '/index.php';
    if (!file_exists($indexPhp)) {
        @file_put_contents($indexPhp, "<?php http_response_code(403); die('Access Denied'); ?>");
    }

    $logFile = $logDir . '/mail.log';
    $timestamp = date("Y-m-d H:i:s");
    $cleanBody = strip_tags(str_replace(["\r", "\n"], ' ', $body));
    $logEntry = "[DEVELOPMENT ONLY MAIL LOG] [$timestamp] To: $to | Subject: $subject | OTP: " . ($otp ?? 'N/A') . " | Content: $cleanBody\n";

    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    error_log("[DEVELOPMENT ONLY] OTP email generated for $to (Logged to logs/mail.log)");

    return true;
}

/**
 * Inspect SPF, DKIM, DMARC, MX, and authenticated SMTP capabilities for the configured sender domain.
 *
 * @param string|null $senderEmail Optional override email or domain to inspect
 * @return array Diagnostic deliverability details
 */
function inspectSenderDomainDeliverability($senderEmail = null)
{
    if (empty($senderEmail)) {
        $senderEmail = getenv('SENDER_EMAIL') ?: (getenv('SMTP_USERNAME') ?: 'mailproject112@gmail.com');
    }

    $parts = explode('@', (string)$senderEmail);
    $domain = strtolower(trim(end($parts)));

    $smtpHost = getenv('SMTP_HOST') ?: 'not_set';
    $smtpPort = (int)(getenv('SMTP_PORT') ?: 587);
    $smtpUser = getenv('SMTP_USERNAME') ?: getenv('SMTP_USER') ?: 'not_set';
    $smtpPass = getenv('SMTP_PASSWORD') ?: getenv('SMTP_PASS');

    $report = [
        'sender_email'       => $senderEmail,
        'domain'             => $domain,
        'smtp_authenticated' => (!empty($smtpUser) && !empty($smtpPass) && $smtpUser !== 'not_set'),
        'smtp_host'          => $smtpHost,
        'smtp_port'          => $smtpPort,
        'smtp_secure'        => getenv('SMTP_ENCRYPTION') ?: (getenv('SMTP_SECURE') ?: 'not_set'),
        'smtp_user'          => $smtpUser,
        'has_smtp_password'  => !empty($smtpPass),
        'sender_aligned'     => (strtolower(trim((string)$senderEmail)) === strtolower(trim((string)$smtpUser))),
        'has_mx'             => false,
        'mx_records'         => [],
        'has_spf'            => false,
        'spf_record'         => null,
        'has_dmarc'          => false,
        'dmarc_record'       => null,
        'dmarc_policy'       => null,
        'recommendations'    => []
    ];

    if (!empty($domain)) {
        if (function_exists('dns_get_record')) {
            // MX Lookup
            $mx = @dns_get_record($domain, DNS_MX);
            if (!empty($mx) && is_array($mx)) {
                $report['has_mx'] = true;
                foreach ($mx as $m) {
                    if (!empty($m['target'])) {
                        $report['mx_records'][] = $m['target'];
                    }
                }
            }

            // SPF Lookup (TXT on root domain)
            $txt = @dns_get_record($domain, DNS_TXT);
            if (!empty($txt) && is_array($txt)) {
                foreach ($txt as $t) {
                    $entry = $t['txt'] ?? ($t['entries'][0] ?? '');
                    if (stripos($entry, 'v=spf1') === 0) {
                        $report['has_spf'] = true;
                        $report['spf_record'] = $entry;
                        break;
                    }
                }
            }

            // DMARC Lookup (TXT on _dmarc.domain)
            $dmarc = @dns_get_record('_dmarc.' . $domain, DNS_TXT);
            if (!empty($dmarc) && is_array($dmarc)) {
                foreach ($dmarc as $d) {
                    $entry = $d['txt'] ?? ($d['entries'][0] ?? '');
                    if (stripos($entry, 'v=DMARC1') === 0) {
                        $report['has_dmarc'] = true;
                        $report['dmarc_record'] = $entry;
                        if (preg_match('/p=([a-z]+)/i', $entry, $matches)) {
                            $report['dmarc_policy'] = strtolower($matches[1]);
                        }
                        break;
                    }
                }
            }
        }
    }

    // Deliverability recommendations based on provider & domain:
    if ($domain === 'gmail.com') {
        if ($report['smtp_host'] === 'smtp.gmail.com') {
            if ($report['sender_aligned']) {
                $report['recommendations'][] = "Gmail SMTP is authenticated and aligned ({$senderEmail}). Google's servers sign outgoing messages with Google DKIM and authorized envelope SPF, passing DMARC.";
            } else {
                $report['recommendations'][] = "Sender address ({$senderEmail}) does not match authenticated user ({$smtpUser}). Gmail SMTP may rewrite From address or trigger DMARC alignment warnings.";
            }
        } else {
            $report['recommendations'][] = "Sending as @gmail.com through a non-Google SMTP relay will fail DMARC (p=reject/quarantine) at major mailbox providers.";
        }
    } else {
        // Custom domain recommendations
        if (!$report['has_spf']) {
            $report['recommendations'][] = "Missing SPF record on {$domain}. Add TXT 'v=spf1 include:_spf.google.com ~all' (or your SMTP relay provider) to domain DNS.";
        }
        if (!$report['has_dmarc']) {
            $report['recommendations'][] = "Missing DMARC record on _dmarc.{$domain}. Add TXT 'v=DMARC1; p=quarantine; rua=mailto:admin@{$domain}' to domain DNS.";
        }
    }

    return $report;
}