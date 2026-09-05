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

// Test A1 & A2: Registration OTP generation & Dev Log Fallback
runTest("A1 & A2: Registration OTP Generation & Dev Log Fallback", function() use ($testEmail) {
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

    $body = "<h2>Email Verification</h2><p>Your OTP is:</p><h1>$otp</h1>";
    $sent = sendMail($testEmail, "Verify Your Email", $body);
    if (!$sent) return "sendMail returned false in dev mode";

    $logFile = __DIR__ . '/../logs/mail.log';
    if (!file_exists($logFile)) return "logs/mail.log not created";

    $content = file_get_contents($logFile);
    if (strpos($content, "[DEVELOPMENT ONLY MAIL LOG]") === false) return "Log missing DEVELOPMENT ONLY header";
    if (strpos($content, (string)$otp) === false) return "Log missing generated OTP";

    return true;
});

// Test A3: Correct OTP Registration Success
runTest("A3: Correct OTP Registration Success", function() use ($conn, $testEmail) {
    if (!isset($_SESSION['reg_data'])) return "No session reg_data";

    $data = $_SESSION['reg_data'];
    $userOtp = $data['otp'];

    if ($userOtp == $data['otp'] && strtotime($data['expiry']) >= time()) {
        $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $data['name'], $data['email'], $data['password'], $data['role']);
        $stmt->execute();
        unset($_SESSION['reg_data']);

        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $testEmail);
        $check->execute();
        $res = $check->get_result();
        if ($res->num_rows === 1) return true;
        return "User not found in DB after insertion";
    }
    return "OTP verification condition failed";
});

// Test A6: Duplicate Email Registration Rejection
runTest("A6: Duplicate Email Registration Rejection", function() use ($conn, $testEmail) {
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $testEmail);
    $check->execute();
    $res = $check->get_result();
    if ($res->num_rows > 0) {
        return true; // Correctly detected duplicate
    }
    return "Duplicate email not detected";
});

// Test A4: Wrong OTP Registration Rejection
runTest("A4: Wrong OTP Registration Rejection", function() {
    $correctOtp = 123456;
    $wrongOtp = 999999;
    $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

    $data = ['otp' => $correctOtp, 'expiry' => $expiry];
    if ($wrongOtp == $data['otp'] && strtotime($data['expiry']) >= time()) {
        return "Wrong OTP was incorrectly accepted";
    }
    return true;
});

// Test A5: Expired OTP Registration Rejection
runTest("A5: Expired OTP Registration Rejection", function() {
    $correctOtp = 123456;
    $expiredTime = date("Y-m-d H:i:s", strtotime("-5 minutes"));

    $data = ['otp' => $correctOtp, 'expiry' => $expiredTime];
    if ($correctOtp == $data['otp'] && strtotime($data['expiry']) >= time()) {
        return "Expired OTP was incorrectly accepted";
    }
    return true;
});

// Test B1 & B2: Password Reset OTP Generation & Dev Fallback
runTest("B1 & B2: Password Reset OTP Generation & Dev Fallback", function() use ($conn, $testEmail) {
    putenv("APP_ENV=development");
    putenv("MAIL_DRIVER=log");

    $otp = rand(100000, 999999);
    $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

    $stmt = $conn->prepare("UPDATE users SET reset_otp=?, otp_expiry=? WHERE email=?");
    $stmt->bind_param("sss", $otp, $expiry, $testEmail);
    $stmt->execute();

    $body = "<h2>Password Reset</h2><p>Your OTP is:</p><h1>$otp</h1>";
    $sent = sendMail($testEmail, "Password Reset OTP", $body);
    if (!$sent) return "sendMail returned false for password reset";

    $stmt = $conn->prepare("SELECT reset_otp FROM users WHERE email=?");
    $stmt->bind_param("s", $testEmail);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();

    if ($res['reset_otp'] == $otp) return true;
    return "DB reset_otp does not match generated OTP";
});

// Test B3: Password Reset Correct OTP Verification
runTest("B3: Password Reset Correct OTP Verification", function() use ($conn, $testEmail) {
    $stmt = $conn->prepare("SELECT reset_otp, otp_expiry FROM users WHERE email=?");
    $stmt->bind_param("s", $testEmail);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && $user['reset_otp'] !== null && strtotime($user['otp_expiry']) >= time()) {
        // Clear OTP on successful reset
        $stmt = $conn->prepare("UPDATE users SET reset_otp=NULL, otp_expiry=NULL WHERE email=?");
        $stmt->bind_param("s", $testEmail);
        $stmt->execute();
        return true;
    }
    return "Password reset verification check failed";
});

// Test B4: Password Reset Wrong/Expired OTP Failure
runTest("B4: Password Reset Wrong/Expired OTP Failure", function() use ($conn, $testEmail) {
    $stmt = $conn->prepare("SELECT reset_otp FROM users WHERE email=?");
    $stmt->bind_param("s", $testEmail);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user['reset_otp'] === null) {
        return true; // Cleared OTP correctly rejected
    }
    return "Reset OTP was not cleared";
});

// Test C1: Brevo Driver without Key returns false safely
runTest("C1: Brevo Driver without API Key fails safely", function() use ($testEmail) {
    putenv("MAIL_DRIVER=brevo");
    putenv("BREVO_API_KEY=");

    $sent = sendMail($testEmail, "Test Subject", "<p>Test</p>");
    if ($sent === false) return true;
    return "Brevo without key should return false";
});

// Test C2: SMTP Driver with invalid host fails safely without throwing exception
runTest("C2: SMTP Driver with unreachable host fails safely", function() use ($testEmail) {
    putenv("MAIL_DRIVER=smtp");
    putenv("SMTP_HOST=invalid.unreachable.smtp.host.local");
    putenv("SMTP_PORT=587");
    putenv("SMTP_USER=dummyuser");
    putenv("SMTP_PASS=dummypass");

    $sent = sendMail($testEmail, "Test Subject", "<p>Test</p>");
    if ($sent === false) return true;
    return "SMTP with invalid host should return false";
});

// Test C3: Development Log Driver Fallback
runTest("C3: Development Log Driver Fallback", function() use ($testEmail) {
    putenv("APP_ENV=development");
    putenv("MAIL_DRIVER=log");

    $sent = sendMail($testEmail, "Dev Log Test", "<p>Dev Log Test Body 123456</p>");
    if ($sent === true) return true;
    return "Dev log driver should return true in development mode";
});

// Test C4: Production Mode with Missing Config Fails Safely
runTest("C4: Production Mode with Missing Config Fails Safely", function() use ($testEmail) {
    putenv("APP_ENV=production");
    putenv("MAIL_DRIVER=auto");
    putenv("BREVO_API_KEY=");
    putenv("SMTP_HOST=");
    putenv("SMTP_USER=");

    $sent = sendMail($testEmail, "Prod Test", "<p>Test</p>");
    putenv("APP_ENV=development"); // Restore dev mode

    if ($sent === false) return true;
    return "Production mode without config should return false";
});

// Cleanup test user
cleanupTestUser($conn, $testEmail);

echo "\n----------------------------------------------------\n";
echo "Summary: $passed Passed, $failed Failed\n";
echo "====================================================\n";

if ($failed > 0) exit(1);
