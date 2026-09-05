<?php
require_once __DIR__ . '/../config/env_loader.php';

/**
 * Multi-Driver Mail Service Module
 * Handles outbound email delivery via Brevo REST API, SMTP, native PHP mail(), or local development logger.
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject line
 * @param string $body Email HTML content
 * @return bool True if mail was sent or logged in dev mode, false on failure
 */
function sendMail($to, $subject, $body)
{
    $driver = strtolower(getenv('MAIL_DRIVER') ?: 'auto');
    $appEnv = strtolower(getenv('APP_ENV') ?: 'development');

    // 1. Explicit Brevo driver or auto-detected Brevo API Key
    if ($driver === 'brevo' || ($driver === 'auto' && getenv('BREVO_API_KEY') !== false && trim(getenv('BREVO_API_KEY')) !== '')) {
        return sendBrevoMail($to, $subject, $body);
    }

    // 2. Explicit SMTP driver or auto-detected SMTP Host & User
    if ($driver === 'smtp' || ($driver === 'auto' && getenv('SMTP_HOST') !== false && trim(getenv('SMTP_HOST')) !== '' && getenv('SMTP_USER') !== false && trim(getenv('SMTP_USER')) !== '')) {
        return sendSmtpMail($to, $subject, $body);
    }

    // 3. Explicit native PHP mail() driver
    if ($driver === 'mail') {
        return sendNativeMail($to, $subject, $body);
    }

    // 4. Explicit log driver or local development fallback (APP_ENV=development/local)
    if ($driver === 'log' || ($driver === 'auto' && in_array($appEnv, ['development', 'local', 'dev'], true))) {
        if (in_array($appEnv, ['development', 'local', 'dev'], true)) {
            return sendDevLogMail($to, $subject, $body);
        } else {
            error_log("[SECURITY ALERT] Log mail driver requested in non-development environment ($appEnv). Delivery rejected.");
            return false;
        }
    }

    // 5. Production Graceful Failure (No valid mail driver or credentials configured)
    error_log("[MAIL ERROR] Production mail service unconfigured or credentials missing.");
    return false;
}

/**
 * Brevo REST API Mail Driver
 */
function sendBrevoMail($to, $subject, $body)
{
    $apiKey = getenv("BREVO_API_KEY");
    if (empty($apiKey)) {
        error_log("[MAIL ERROR] Brevo API Key missing.");
        return false;
    }

    $senderEmail = getenv('SENDER_EMAIL') ?: "noreply@example.com";
    $senderName  = getenv('SENDER_NAME')  ?: "Online Examination System";

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

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "https://api.brevo.com/v3/smtp/email");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "api-key: $apiKey",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("[MAIL ERROR] Brevo cURL network error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 201) {
        return true;
    } else {
        error_log("[MAIL ERROR] Brevo API delivery failed with HTTP status code: " . $httpCode);
        return false;
    }
}

/**
 * Socket SMTP Mail Driver
 */
function sendSmtpMail($to, $subject, $body)
{
    $host   = getenv('SMTP_HOST');
    $port   = (int)(getenv('SMTP_PORT') ?: 587);
    $user   = getenv('SMTP_USER');
    $pass   = getenv('SMTP_PASS');
    $secure = strtolower(getenv('SMTP_SECURE') ?: 'tls');
    $from   = getenv('SENDER_EMAIL') ?: 'noreply@example.com';
    $name   = getenv('SENDER_NAME')  ?: 'Online Examination System';

    if (empty($host)) {
        error_log("[MAIL ERROR] SMTP_HOST is not configured.");
        return false;
    }

    $remoteHost = ($secure === 'ssl' ? 'ssl://' : '') . $host;
    $socket = @fsockopen($remoteHost, $port, $errno, $errstr, 15);

    if (!$socket) {
        error_log("[MAIL ERROR] SMTP connection failed to $host:$port ($errstr)");
        return false;
    }

    $readResponse = function($socket, $expectedCode) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        $code = (int)substr($response, 0, 3);
        return ($code === $expectedCode);
    };

    if (!$readResponse($socket, 220)) {
        fclose($socket);
        error_log("[MAIL ERROR] SMTP banner verification failed.");
        return false;
    }

    fwrite($socket, "EHLO " . gethostname() . "\r\n");
    if (!$readResponse($socket, 250)) {
        fclose($socket);
        error_log("[MAIL ERROR] SMTP EHLO failed.");
        return false;
    }

    if ($secure === 'tls') {
        fwrite($socket, "STARTTLS\r\n");
        if (!$readResponse($socket, 220)) {
            fclose($socket);
            error_log("[MAIL ERROR] SMTP STARTTLS command failed.");
            return false;
        }
        $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            fclose($socket);
            error_log("[MAIL ERROR] SMTP TLS encryption negotiation failed.");
            return false;
        }
        fwrite($socket, "EHLO " . gethostname() . "\r\n");
        if (!$readResponse($socket, 250)) {
            fclose($socket);
            error_log("[MAIL ERROR] SMTP EHLO post-TLS failed.");
            return false;
        }
    }

    if (!empty($user) && !empty($pass)) {
        fwrite($socket, "AUTH LOGIN\r\n");
        if (!$readResponse($socket, 334)) {
            fclose($socket);
            error_log("[MAIL ERROR] SMTP AUTH LOGIN command failed.");
            return false;
        }
        fwrite($socket, base64_encode($user) . "\r\n");
        if (!$readResponse($socket, 334)) {
            fclose($socket);
            error_log("[MAIL ERROR] SMTP AUTH username rejection.");
            return false;
        }
        fwrite($socket, base64_encode($pass) . "\r\n");
        if (!$readResponse($socket, 235)) {
            fclose($socket);
            error_log("[MAIL ERROR] SMTP AUTH password rejection.");
            return false;
        }
    }

    fwrite($socket, "MAIL FROM: <$from>\r\n");
    if (!$readResponse($socket, 250)) {
        fclose($socket);
        error_log("[MAIL ERROR] SMTP MAIL FROM failed.");
        return false;
    }

    fwrite($socket, "RCPT TO: <$to>\r\n");
    if (!$readResponse($socket, 250)) {
        fclose($socket);
        error_log("[MAIL ERROR] SMTP RCPT TO failed.");
        return false;
    }

    fwrite($socket, "DATA\r\n");
    if (!$readResponse($socket, 354)) {
        fclose($socket);
        error_log("[MAIL ERROR] SMTP DATA command failed.");
        return false;
    }

    $headers  = "From: $name <$from>\r\n";
    $headers .= "To: <$to>\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";

    fwrite($socket, $headers . $body . "\r\n.\r\n");
    if (!$readResponse($socket, 250)) {
        fclose($socket);
        error_log("[MAIL ERROR] SMTP message transmission failed.");
        return false;
    }

    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return true;
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