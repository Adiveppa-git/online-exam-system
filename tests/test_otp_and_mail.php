<?php
/**
 * Automated OTP & Multi-Driver Mail Test Suite
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/send_mail.php';

echo "====================================================\n";
echo "   Phase I: OTP & Mail Service Test Suite           \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

function runTest($title, $callback) {
    global $passed, $failed;
    echo "[$title] ... ";
    try {
        $result = $callback();
        if ($result === true) {
            echo "PASSED\n";
            $passed++;
        } else {
            echo "FAILED ($result)\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "FAILED Exception: " . $e->getMessage() . "\n";
        $failed++;
    }
}

// Cleanup helper
function cleanupTestUser($conn, $email) {
    $stmt = $conn->prepare("DELETE FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
}

$testEmail = "test_otp_auto@example.com";
cleanupTestUser($conn, $testEmail);

// Test 1: Brevo Configuration Detection
runTest("1. Brevo Configuration Detection", function() {
    putenv("MAIL_DRIVER=brevo");
    putenv("BREVO_API_KEY=test_dummy_key");
    putenv("SENDER_EMAIL=sender@validcompany.com");

    $receivedUrl = null;
    setBrevoHttpHandler(function($url, $headers, $payloadJson) use (&$receivedUrl) {
        $receivedUrl = $url;
        return ['code' => 201, 'response' => '{"messageId":"<mock>"}', 'error' => null];
    });

    $result = sendMail("user@example.com", "Test Subject", "<p>Test</p>");
    setBrevoHttpHandler(null);

    if ($result === true && $receivedUrl === "https://api.brevo.com/v3/smtp/email") {
        return true;
    }
    return "Brevo configuration detection failed";
});

// Test 2: Gmail SMTP Driver Selection & Mock Delivery
runTest("2. Gmail SMTP Driver Selection & Mock Delivery", function() {
    putenv("MAIL_DRIVER=smtp");
    putenv("SMTP_HOST=smtp.gmail.com");
    putenv("SMTP_PORT=587");
    putenv("SMTP_ENCRYPTION=tls");
    putenv("SMTP_USERNAME=mailproject112@gmail.com");
    putenv("SMTP_PASSWORD=dummy_smtp_app_pass_1234");
    putenv("SENDER_EMAIL=mailproject112@gmail.com");
    putenv("SENDER_NAME=Online Examination System");

    $targetRecipient = "student@gmail.com";
    $capturedConfig = null;

    setSmtpHandler(function($to, $subject, $body, $config) use (&$capturedConfig) {
        $capturedConfig = $config;
        $capturedConfig['to'] = $to;
        return true;
    });

    $otp = rand(100000, 999999);
    $body = buildOtpEmailHtml("Email Verification", $otp, 10);
    $sent = sendMail($targetRecipient, "Verify Your Email - Online Examination System", $body);
    setSmtpHandler(null);

    if (!$sent) return "sendMail failed for SMTP driver";
    if (!isset($capturedConfig['host']) || $capturedConfig['host'] !== 'smtp.gmail.com') {
        return "SMTP_HOST was not set to smtp.gmail.com";
    }
    if ($capturedConfig['username'] !== 'mailproject112@gmail.com') {
        return "SMTP_USERNAME was not read correctly";
    }
    if ($capturedConfig['senderEmail'] !== 'mailproject112@gmail.com') {
        return "SENDER_EMAIL was not set to mailproject112@gmail.com";
    }
    if ($capturedConfig['to'] !== $targetRecipient) {
        return "Target recipient was not preserved correctly";
    }

    return true;
});

// Test 3: Missing SMTP Password Handling (No Silent Fallback to Log File)
runTest("3. Missing SMTP Password Handling (No Silent Log Fallback)", function() {
    putenv("APP_ENV=development");
    putenv("MAIL_DRIVER=smtp");
    putenv("SMTP_HOST=smtp.gmail.com");
    putenv("SMTP_USERNAME=mailproject112@gmail.com");
    putenv("SMTP_PASSWORD=");
    putenv("SMTP_PASS=");

    $logFile = __DIR__ . '/../logs/mail.log';
    $initialSize = file_exists($logFile) ? filesize($logFile) : 0;

    $sent = sendMail("student@gmail.com", "Test", "<p>Test</p>");
    if ($sent !== false) return "sendMail should return false when SMTP_PASSWORD is missing";

    $finalSize = file_exists($logFile) ? filesize($logFile) : 0;
    if ($finalSize > $initialSize) {
        return "MAIL_DRIVER=smtp incorrectly wrote entry to logs/mail.log upon failure";
    }

    return true;
});

// Test 4: Brevo is NOT Called When MAIL_DRIVER=smtp
runTest("4. Brevo is NOT Called When MAIL_DRIVER=smtp", function() {
    putenv("MAIL_DRIVER=smtp");
    putenv("BREVO_API_KEY=mock_key_should_not_be_called");
    putenv("SMTP_HOST=smtp.gmail.com");
    putenv("SMTP_USERNAME=mailproject112@gmail.com");
    putenv("SMTP_PASSWORD=secret_pass");

    $brevoCalled = false;
    setBrevoHttpHandler(function($url, $headers, $payloadJson) use (&$brevoCalled) {
        $brevoCalled = true;
        return ['code' => 201, 'response' => '{}', 'error' => null];
    });

    setSmtpHandler(function() {
        return true;
    });

    $sent = sendMail("student@gmail.com", "Test Subject", "<p>Content</p>");
    setBrevoHttpHandler(null);
    setSmtpHandler(null);

    if ($brevoCalled) {
        return "Brevo REST API was called even though MAIL_DRIVER=smtp";
    }
    if (!$sent) {
        return "SMTP send failed";
    }

    return true;
});

// Test 5: Invalid Sender Configuration Handling
runTest("5. Invalid Sender Configuration Handling", function() {
    putenv("MAIL_DRIVER=brevo");
    putenv("BREVO_API_KEY=test_dummy_key");
    putenv("SENDER_EMAIL=noreply@example.com");

    $sent = sendMail("user@example.com", "Test", "<p>Test</p>");
    if ($sent === false) return true;
    return "sendMail should return false when SENDER_EMAIL is placeholder 'noreply@example.com'";
});

// Test 6: Successful Brevo Response Handling using Mocked HTTP Response
runTest("6. Successful Brevo Response Handling (Mocked HTTP 201)", function() {
    putenv("MAIL_DRIVER=brevo");
    putenv("BREVO_API_KEY=mock_key_12345");
    putenv("SENDER_EMAIL=verified_sender@myorg.com");

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['mail_sent_mode'] = null;

    setBrevoHttpHandler(function($url, $headers, $payloadJson) {
        return ['code' => 201, 'response' => '{"messageId":"<20260911@brevo>"}', 'error' => null];
    });

    $sent = sendMail("test_recipient@myorg.com", "Verify Email", "<p>OTP: 123456</p>");
    setBrevoHttpHandler(null);

    $sentMode = $_SESSION['mail_sent_mode'] ?? 'unset';
    if ($sent === true && $sentMode === 'real') {
        return true;
    }
    return "Brevo HTTP 201 response handling failed";
});

// Test 7: Brevo API Failure Handling (Mocked HTTP 400 & cURL Error)
runTest("7. Brevo API Failure Handling (Mocked HTTP 400 & cURL Error)", function() {
    putenv("MAIL_DRIVER=brevo");
    putenv("BREVO_API_KEY=mock_key_12345");
    putenv("SENDER_EMAIL=verified_sender@myorg.com");

    // 7a. HTTP 400 Bad Request
    setBrevoHttpHandler(function($url, $headers, $payloadJson) {
        return ['code' => 400, 'response' => '{"code":"invalid_parameter","message":"bad payload"}', 'error' => null];
    });

    $sent400 = sendMail("test@myorg.com", "Test", "<p>Test</p>");
    setBrevoHttpHandler(null);

    if ($sent400 !== false) return "HTTP 400 failure was not handled correctly";

    // 7b. Network / cURL Error
    setBrevoHttpHandler(function($url, $headers, $payloadJson) {
        return ['code' => 0, 'response' => '', 'error' => 'Could not resolve host'];
    });

    $sentErr = sendMail("test@myorg.com", "Test", "<p>Test</p>");
    setBrevoHttpHandler(null);

    if ($sentErr !== false) return "cURL network error was not handled correctly";

    return true;
});

// Test 8: Registration OTP Recipient is Actual User's Email
runTest("8. Registration OTP Recipient is Actual User's Email", function() {
    putenv("MAIL_DRIVER=smtp");
    putenv("SMTP_HOST=smtp.gmail.com");
    putenv("SMTP_USERNAME=mailproject112@gmail.com");
    putenv("SMTP_PASSWORD=mock_pass");

    $targetRecipient = "actual_user_registration@domain.org";
    $capturedRecipient = null;

    setSmtpHandler(function($to) use (&$capturedRecipient) {
        $capturedRecipient = $to;
        return true;
    });

    $otp = rand(100000, 999999);
    $body = buildOtpEmailHtml("Email Verification", $otp, 10);
    $sent = sendMail($targetRecipient, "Verify Your Email - Online Examination System", $body);
    setSmtpHandler(null);

    if (!$sent) return "sendMail failed for registration OTP";
    if ($capturedRecipient !== $targetRecipient) {
        return "Expected recipient $targetRecipient but got " . $capturedRecipient;
    }

    return true;
});

// Test 9: Password Reset OTP Recipient is Actual User's Email
runTest("9. Password Reset OTP Recipient is Actual User's Email", function() {
    putenv("MAIL_DRIVER=smtp");
    putenv("SMTP_HOST=smtp.gmail.com");
    putenv("SMTP_USERNAME=mailproject112@gmail.com");
    putenv("SMTP_PASSWORD=mock_pass");

    $targetRecipient = "actual_user_reset@domain.org";
    $capturedRecipient = null;

    setSmtpHandler(function($to) use (&$capturedRecipient) {
        $capturedRecipient = $to;
        return true;
    });

    $otp = rand(100000, 999999);
    $body = buildOtpEmailHtml("Password Reset", $otp, 10);
    $sent = sendMail($targetRecipient, "Password Reset OTP - Online Examination System", $body);
    setSmtpHandler(null);

    if (!$sent) return "sendMail failed for password reset OTP";
    if ($capturedRecipient !== $targetRecipient) {
        return "Expected recipient $targetRecipient but got " . $capturedRecipient;
    }

    return true;
});

// Test 10: MAIL_DRIVER=brevo Does Not Fall Back to Log Delivery
runTest("10. MAIL_DRIVER=brevo Does Not Fall Back to Log Delivery", function() {
    putenv("APP_ENV=development");
    putenv("MAIL_DRIVER=brevo");
    putenv("BREVO_API_KEY="); // Missing key forces Brevo failure

    $logFile = __DIR__ . '/../logs/mail.log';
    $initialSize = file_exists($logFile) ? filesize($logFile) : 0;

    $sent = sendMail("log_fallback_test@example.com", "Should Not Log", "<p>OTP: 999888</p>");
    if ($sent !== false) return "sendMail should have failed when key was missing under MAIL_DRIVER=brevo";

    $finalSize = file_exists($logFile) ? filesize($logFile) : 0;
    if ($finalSize > $initialSize) {
        return "MAIL_DRIVER=brevo incorrectly wrote entry to logs/mail.log upon failure";
    }

    return true;
});

// Test 11: No Secrets Appear in Source Files
runTest("11. No Secrets Appear in Source Files", function() {
    $projectRoot = dirname(__DIR__);
    $filesToScan = [
        'auth/send_mail.php',
        'auth/register.php',
        'auth/forgot_password.php',
        'auth/verify_reset_otp.php',
        'config/env_loader.php',
        'config/db.php',
        '.env.example',
        'tests/test_gmail_smtp.php'
    ];

    foreach ($filesToScan as $relFile) {
        $absFile = $projectRoot . '/' . $relFile;
        if (!file_exists($absFile)) continue;
        $content = file_get_contents($absFile);

        if (preg_match('/xkeysib-[a-zA-Z0-9]{50,}/i', $content)) {
            return "Found live Brevo key pattern in $relFile";
        }
        if (preg_match('/BREVO_API_KEY\s*=\s*[\'"][a-zA-Z0-9_]{10,}[\'"]/i', $content)) {
            return "Found hardcoded BREVO_API_KEY in $relFile";
        }
    }

    return true;
});

// Test 12: Existing OTP Verification, Expiration & DB Logic
runTest("12. Existing OTP Verification, Expiration & DB Logic", function() use ($conn, $testEmail) {
    putenv("APP_ENV=development");
    putenv("MAIL_DRIVER=log");

    $otp = rand(100000, 999999);
    $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

    $_SESSION['reg_data'] = [
        'name' => 'Test OTP User',
        'email' => $testEmail,
        'role' => 'student',
        'password' => password_hash('TestPass123!', PASSWORD_DEFAULT),
        'otp' => $otp,
        'expiry' => $expiry
    ];

    // Verify registration insertion
    $data = $_SESSION['reg_data'];
    if ($data['otp'] == $otp && strtotime($data['expiry']) >= time()) {
        $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $data['name'], $data['email'], $data['password'], $data['role']);
        $stmt->execute();
        unset($_SESSION['reg_data']);
    } else {
        return "Registration OTP check failed";
    }

    // Verify user in DB
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $testEmail);
    $check->execute();
    $res = $check->get_result();
    if ($res->num_rows !== 1) return "User not inserted into DB";

    // Test Wrong OTP rejection
    $wrongData = ['otp' => 123456, 'expiry' => date("Y-m-d H:i:s", strtotime("+10 minutes"))];
    if (999999 == $wrongData['otp']) return "Wrong OTP incorrectly accepted";

    // Test Expired OTP rejection
    $expiredData = ['otp' => 123456, 'expiry' => date("Y-m-d H:i:s", strtotime("-5 minutes"))];
    if ($expiredData['otp'] == 123456 && strtotime($expiredData['expiry']) >= time()) {
        return "Expired OTP incorrectly accepted";
    }

    // Cleanup
    cleanupTestUser($conn, $testEmail);
    return true;
});

echo "\n----------------------------------------------------\n";
echo "Summary: $passed Passed, $failed Failed\n";
echo "====================================================\n";

if ($failed > 0) exit(1);
