<?php
require_once __DIR__ . '/../config/env_loader.php';

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
 * Helper to build professional HTML email for OTP verification
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

    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>{$safePurpose}</title>
    </head>
    <body style='font-family: Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px;'>
        <div style='max-width: 550px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border: 1px solid #e0e0e0;'>
            <div style='background: #0d6efd; color: #ffffff; padding: 20px; text-align: center;'>
                <h1 style='margin: 0; font-size: 22px; font-weight: bold;'>{$appName}</h1>
                <p style='margin: 5px 0 0; font-size: 14px; opacity: 0.9;'>{$safePurpose}</p>
            </div>
            <div style='padding: 30px; text-align: center; color: #333333;'>
                <p style='font-size: 15px; margin-bottom: 20px;'>Your One-Time Password (OTP) for <strong>{$safePurpose}</strong> is:</p>
                <div style='background: #f0f4ff; border: 2px dashed #0d6efd; display: inline-block; padding: 15px 35px; border-radius: 8px; margin-bottom: 20px;'>
                    <span style='font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #0d6efd;'>{$safeOtp}</span>
                </div>
                <p style='font-size: 14px; color: #666666; margin-bottom: 25px;'>This OTP is valid for <strong>{$expiryMinutes} minutes</strong>.</p>
                <div style='background: #fff3cd; color: #856404; padding: 12px 15px; border-radius: 6px; font-size: 13px; text-align: left; border: 1px solid #ffeeba;'>
                    <strong>Security Warning:</strong> Please do not share this OTP with anyone. Our team will never ask for your verification code.
                </div>
            </div>
            <div style='background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #888888; border-top: 1px solid #eeeeee;'>
                &copy; " . date('Y') . " {$appName}. All rights reserved.
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Multi-Driver Mail Service Module
 * Handles outbound email delivery via SMTP (PHPMailer), Brevo REST API, native PHP mail(), or local development logger.
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject line
 * @param string $body Email HTML content
 * @return bool True if mail was sent or logged in dev mode, false on failure
 */
function sendMail($to, $subject, $body)
{
    $GLOBALS['last_mail_error'] = null;
    $driver = strtolower(getenv('MAIL_DRIVER') ?: 'auto');
    $appEnv = strtolower(getenv('APP_ENV') ?: 'development');

    // 1. Explicit SMTP driver
    if ($driver === 'smtp') {
        return sendSmtpMail($to, $subject, $body);
    }

    // 2. Explicit Brevo driver
    if ($driver === 'brevo') {
        return sendBrevoMail($to, $subject, $body);
    }

    // 3. Auto-detection mode
    if ($driver === 'auto') {
        $smtpHost = getenv('SMTP_HOST');
        $smtpUser = getenv('SMTP_USERNAME') ?: getenv('SMTP_USER');
        if (!empty($smtpHost) && !empty($smtpUser)) {
            return sendSmtpMail($to, $subject, $body);
        }

        $brevoKey = getenv('BREVO_API_KEY');
        if (!empty($brevoKey) && trim($brevoKey) !== '') {
            return sendBrevoMail($to, $subject, $body);
        }
    }

    // 4. Explicit native PHP mail() driver
    if ($driver === 'mail') {
        return sendNativeMail($to, $subject, $body);
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
 */
function sendBrevoMail($to, $subject, $body)
{
    $apiKey = trim(getenv("BREVO_API_KEY") ?: '');
    if (empty($apiKey)) {
        $GLOBALS['last_mail_error'] = "Brevo API Key missing or empty.";
        error_log("[MAIL ERROR] Brevo API Key missing or empty.");
        return false;
    }

    $senderEmail = trim(getenv('SENDER_EMAIL') ?: (getenv('MAIL_FROM') ?: ''));
    $senderName  = trim(getenv('SENDER_NAME') ?: (getenv('MAIL_FROM_NAME') ?: 'Online Examination System'));

    if (empty($senderEmail) || $senderEmail === 'noreply@example.com' || strpos($senderEmail, 'example.com') !== false || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['last_mail_error'] = "Invalid or placeholder SENDER_EMAIL configured.";
        error_log("[MAIL ERROR] Invalid or placeholder SENDER_EMAIL configured ('$senderEmail'). Real mail delivery requires a valid verified sender email address.");
        return false;
    }

    $data = [
        "sender" => [
            "name"  => $senderName,
            "email" => $senderEmail
        ],
        "to" => [
            ["email" => $to]
        ],
        "subject" => $subject,
        "htmlContent" => $body
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
            return true;
        } else {
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
        return true;
    } else {
        $sanitizedResponse = substr(strip_tags($response ?? ''), 0, 200);
        $GLOBALS['last_mail_error'] = "Brevo API delivery failed (HTTP " . $httpCode . "): " . $sanitizedResponse;
        error_log("[MAIL ERROR] Brevo API delivery failed with HTTP status code: " . $httpCode . " Summary: " . $sanitizedResponse);
        return false;
    }
}

/**
 * PHPMailer SMTP Mail Driver (Gmail SMTP / Standard SMTP)
 */
function sendSmtpMail($to, $subject, $body)
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
        $senderEmail = 'mailproject112@gmail.com';
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

    // Check for testing mock SMTP handler override
    if (isset($GLOBALS['smtpHandler']) && is_callable($GLOBALS['smtpHandler'])) {
        $mockRes = call_user_func($GLOBALS['smtpHandler'], $to, $subject, $body, [
            'host'        => $host,
            'port'        => $port,
            'encryption'  => $encryption,
            'username'    => $username,
            'password'    => $password,
            'senderEmail' => $senderEmail,
            'senderName'  => $senderName
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
                return true;
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
                return true;
            }
            if (!empty($mockRes['error'])) {
                $GLOBALS['last_mail_error'] = "SMTP mock error: " . $mockRes['error'];
                error_log("[MAIL ERROR] SMTP mock error: " . $mockRes['error']);
            }
            return false;
        }
        return false;
    }

    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
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
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject    = $subject;
        $mail->Body       = $body;
        $mail->CharSet    = 'UTF-8';

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
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $cleanError = str_replace([$password, $username], ['***', '***'], $e->getMessage());
        $cleanError = substr(strip_tags($cleanError), 0, 200);
        $GLOBALS['last_mail_error'] = "Gmail SMTP delivery failed: " . $cleanError;
        error_log("[MAIL ERROR] PHPMailer SMTP delivery failed: " . $cleanError);
        return false;
    } catch (Throwable $e) {
        $cleanError = str_replace([$password, $username], ['***', '***'], $e->getMessage());
        $cleanError = substr(strip_tags($cleanError), 0, 200);
        $GLOBALS['last_mail_error'] = "Unexpected SMTP delivery failure: " . $cleanError;
        error_log("[MAIL ERROR] Unexpected SMTP delivery failure: " . $cleanError);
        return false;
    }
}

/**
 * Native PHP mail() Driver
 */
function sendNativeMail($to, $subject, $body)
{
    $from = getenv('SENDER_EMAIL') ?: "noreply@example.com";
    $name = getenv('SENDER_NAME')  ?: "Online Examination System";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $name <$from>\r\n";

    return @mail($to, $subject, $body, $headers);
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
    error_log("[DEVELOPMENT ONLY] OTP generated for $to: " . ($otp ?? 'N/A') . " (Logged to logs/mail.log)");

    return true;
}