<?php
/**
 * Automated Verification Suite:
 * Student and Admin OTP Email Delivery & Password Reset Workflow
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/send_mail.php';
require_once __DIR__ . '/../auth/account_helper.php';

echo "====================================================\n";
echo " Student & Admin OTP Email Delivery Test Suite     \n";
echo "====================================================\n\n";

$totalPassed = 0;
$totalFailed = 0;

function assertCondition(string $name, bool $condition, string $details = '') {
    global $totalPassed, $totalFailed;
    if ($condition) {
        $totalPassed++;
        echo "[PASS] {$name}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        $totalFailed++;
        echo "[FAIL] {$name}" . ($details ? " - {$details}" : "") . "\n";
    }
}

/**
 * Runs a PHP script isolated with custom session and post data,
 * with optional mock SMTP capturing.
 */
function runScriptIsolated($scriptRelativePath, $session = [], $post = [], $mockMail = true, $mailSuccess = true) {
    $scriptFullPath = realpath(__DIR__ . '/../' . $scriptRelativePath);
    if (!$scriptFullPath || !file_exists($scriptFullPath)) {
        return ['stdout' => '', 'stderr' => "File not found: {$scriptRelativePath}", 'exit' => -1, 'mail' => null];
    }

    $captureFile = tempnam(sys_get_temp_dir(), 'mail_capture_');
    file_put_contents($captureFile, '');

    $payload = [
        'script' => $scriptFullPath,
        'session' => $session,
        'post' => $post,
        'captureFile' => $captureFile,
        'mockMail' => $mockMail,
        'mailSuccess' => $mailSuccess,
        'projectRoot' => realpath(__DIR__ . '/..')
    ];
    $payloadJson = base64_encode(json_encode($payload));

    $bootstrapCode = '<?php
    $data = json_decode(base64_decode("' . $payloadJson . '"), true);
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = $data["session"] ?? [];
    $_POST = $data["post"] ?? [];
    $_SERVER["REQUEST_METHOD"] = !empty($_POST) ? "POST" : "GET";

    if (!empty($data["mockMail"])) {
        require_once $data["projectRoot"] . "/auth/send_mail.php";
        $cap = $data["captureFile"];
        $successVal = !empty($data["mailSuccess"]);
        setSmtpHandler(function($to, $subject, $body, $config) use ($cap, $successVal) {
            file_put_contents($cap, json_encode([
                "to" => $to,
                "subject" => $subject,
                "body" => $body
            ]));
            return $successVal;
        });
    }

    ob_start();
    include $data["script"];
    $out = ob_get_clean();
    echo $out;
    ';

    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];

    $proc = proc_open('C:\\xampp\\php\\php.exe', $descriptors, $pipes);
    if (!is_resource($proc)) {
        @unlink($captureFile);
        return ['stdout' => '', 'stderr' => 'Failed to proc_open php', 'exit' => -1, 'mail' => null];
    }

    fwrite($pipes[0], $bootstrapCode);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($proc);

    $capturedMail = null;
    if (file_exists($captureFile)) {
        $cContent = trim(file_get_contents($captureFile));
        if (!empty($cContent)) {
            $capturedMail = json_decode($cContent, true);
        }
        @unlink($captureFile);
    }

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exit' => $exitCode,
        'mail' => $capturedMail
    ];
}

// Cleanup helper
function cleanupUserByEmail($conn, $email) {
    $stmt = $conn->prepare("DELETE FROM users WHERE email = ?");
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
    }
}

// Fixture emails
$studentEmail = "test_student_delivery_" . uniqid() . "@example.com";
$adminEmail   = "test_admin_delivery_" . uniqid() . "@example.com";
$unknownEmail = "unknown_user_" . uniqid() . "@example.com";

cleanupUserByEmail($conn, $studentEmail);
cleanupUserByEmail($conn, $adminEmail);

// Create Test Student
$initialStudentPass = password_hash("StudentInitial123!", PASSWORD_DEFAULT);
$stmtSt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES ('Test Delivery Student', ?, ?, 'student')");
$stmtSt->bind_param("ss", $studentEmail, $initialStudentPass);
$stmtSt->execute();
$studentUserId = $conn->insert_id;
$stmtSt->close();

// Create Test Admin
$initialAdminPass = password_hash("AdminInitial123!", PASSWORD_DEFAULT);
$stmtAd = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES ('Test Delivery Admin', ?, ?, 'admin')");
$stmtAd->bind_param("ss", $adminEmail, $initialAdminPass);
$stmtAd->execute();
$adminUserId = $conn->insert_id;
$stmtAd->close();

echo "--- 1. Schema & Account Lookup Tests ---\n";

// 1. Account Lookup for Student
$stAccount = findAccountForPasswordReset($conn, $studentEmail);
assertCondition(
    "1. Student account identified with role 'student'",
    $stAccount !== null && $stAccount['role'] === 'student' && $stAccount['table'] === 'users' && $stAccount['email'] === $studentEmail,
    "Resolved table={$stAccount['table']}, role={$stAccount['role']}"
);

// 2. Account Lookup for Admin
$adAccount = findAccountForPasswordReset($conn, $adminEmail);
assertCondition(
    "2. Admin account identified with role 'admin'",
    $adAccount !== null && $adAccount['role'] === 'admin' && $adAccount['table'] === 'users' && $adAccount['email'] === $adminEmail,
    "Resolved table={$adAccount['table']}, role={$adAccount['role']}"
);

// 3. Normalization (Case & Whitespace)
$lookupWhitespace = findAccountForPasswordReset($conn, "  " . strtoupper($studentEmail) . "  ");
assertCondition(
    "3. Account lookup normalizes case and surrounding whitespace",
    $lookupWhitespace !== null && $lookupWhitespace['email'] === $studentEmail
);

echo "\n--- 2. Student Forgot-Password & Recipient Targeting ---\n";

// 4. Student OTP generation & exact recipient delivery
$resStForgot = runScriptIsolated('auth/forgot_password.php', [], [
    'send_otp' => '1',
    'email' => $studentEmail
], true);

$capturedStudentTo = $resStForgot['mail']['to'] ?? null;

assertCondition(
    "4. Student OTP recipient is student's registered email",
    $capturedStudentTo === $studentEmail,
    "Target recipient: {$capturedStudentTo}"
);

assertCondition(
    "5. Student OTP is NEVER delivered to admin email",
    $capturedStudentTo !== 'mailproject112@gmail.com' && $capturedStudentTo !== $adminEmail,
    "Confirmed recipient is strictly student email"
);

// Check OTP in database for student
$chkStDb = $conn->query("SELECT reset_otp, otp_expiry FROM users WHERE id = {$studentUserId}")->fetch_assoc();
$studentOtp = $chkStDb['reset_otp'] ?? '';
$studentOtpExpiry = $chkStDb['otp_expiry'] ?? '';

assertCondition(
    "6. Student secure 6-digit OTP saved in database",
    !empty($studentOtp) && strlen($studentOtp) === 6 && ctype_digit($studentOtp) && strtotime($studentOtpExpiry) > time(),
    "OTP: {$studentOtp}, Expiry: {$studentOtpExpiry}"
);

echo "\n--- 3. Admin Forgot-Password & Recipient Targeting ---\n";

// 7. Admin OTP generation & exact recipient delivery
$resAdForgot = runScriptIsolated('auth/forgot_password.php', [], [
    'send_otp' => '1',
    'email' => $adminEmail
], true);

$capturedAdminTo = $resAdForgot['mail']['to'] ?? null;

assertCondition(
    "7. Admin OTP recipient is admin's registered email",
    $capturedAdminTo === $adminEmail,
    "Target recipient: {$capturedAdminTo}"
);

assertCondition(
    "8. Admin OTP is NEVER delivered to student email",
    $capturedAdminTo !== $studentEmail,
    "Confirmed recipient is strictly admin email"
);

// Check OTP in database for admin
$chkAdDb = $conn->query("SELECT reset_otp, otp_expiry FROM users WHERE id = {$adminUserId}")->fetch_assoc();
$adminOtp = $chkAdDb['reset_otp'] ?? '';
$adminOtpExpiry = $chkAdDb['otp_expiry'] ?? '';

assertCondition(
    "9. Admin secure 6-digit OTP saved in database",
    !empty($adminOtp) && strlen($adminOtp) === 6 && ctype_digit($adminOtp) && strtotime($adminOtpExpiry) > time(),
    "OTP: {$adminOtp}, Expiry: {$adminOtpExpiry}"
);

echo "\n--- 4. Student Password Reset & Single-Use Verification ---\n";

// 10. Student verifies OTP and resets password (Password must be 8 to 15 characters)
$newStudentPass = "NewStudent789!";
$resStVerify = runScriptIsolated('auth/verify_reset_otp.php', [
    'reset_email' => $studentEmail,
    'reset_account' => $stAccount,
    'otp_attempts_' . md5($studentEmail) => 0
], [
    'verify' => '1',
    'otp' => $studentOtp,
    'password' => $newStudentPass
]);

$chkStAfter = $conn->query("SELECT password, reset_otp, otp_expiry FROM users WHERE id = {$studentUserId}")->fetch_assoc();
$stResetSuccess = (
    $chkStAfter !== null &&
    password_verify($newStudentPass, $chkStAfter['password']) &&
    $chkStAfter['reset_otp'] === null &&
    $chkStAfter['otp_expiry'] === null
);

assertCondition(
    "10. Student password successfully reset with OTP",
    $stResetSuccess,
    "New password verified with password_hash, reset_otp cleared to NULL"
);

// 11. Replay attack: Old OTP is single-use and fails
$resStReplay = runScriptIsolated('auth/verify_reset_otp.php', [
    'reset_email' => $studentEmail,
    'reset_account' => $stAccount,
    'otp_attempts_' . md5($studentEmail) => 0
], [
    'verify' => '1',
    'otp' => $studentOtp,
    'password' => "AnotherPass12!"
]);

assertCondition(
    "11. Single-use OTP: Replay attack rejected after password reset",
    strpos($resStReplay['stdout'], "Verification code has expired") !== false
);

echo "\n--- 5. Admin Password Reset & Single-Use Verification ---\n";

// 12. Admin verifies OTP and resets password (8 to 15 characters)
$newAdminPass = "NewAdminSec456!";
$resAdVerify = runScriptIsolated('auth/verify_reset_otp.php', [
    'reset_email' => $adminEmail,
    'reset_account' => $adAccount,
    'otp_attempts_' . md5($adminEmail) => 0
], [
    'verify' => '1',
    'otp' => $adminOtp,
    'password' => $newAdminPass
]);

$chkAdAfter = $conn->query("SELECT password, reset_otp, otp_expiry FROM users WHERE id = {$adminUserId}")->fetch_assoc();
$adResetSuccess = (
    $chkAdAfter !== null &&
    password_verify($newAdminPass, $chkAdAfter['password']) &&
    $chkAdAfter['reset_otp'] === null &&
    $chkAdAfter['otp_expiry'] === null
);

assertCondition(
    "12. Admin password successfully reset with OTP",
    $adResetSuccess,
    "New password verified with password_hash, reset_otp cleared to NULL"
);

echo "\n--- 6. Security Controls & Error Defense ---\n";

// 13. Unknown Email Address: Safe Response without Email Dispatch
$resUnknown = runScriptIsolated('auth/forgot_password.php', [], [
    'send_otp' => '1',
    'email' => $unknownEmail
], true);

assertCondition(
    "13. Unknown email does NOT trigger any mail dispatch",
    empty($resUnknown['mail']),
    "Zero outgoing emails dispatched"
);

assertCondition(
    "14. Safe generic response returned for unknown email (User Enumeration Defense)",
    strpos($resUnknown['stdout'], "If that email address is registered") !== false
);

// 15. Expired OTP Rejection
$expiredOtp = "123456";
$pastExpiry = date("Y-m-d H:i:s", time() - 300);
$conn->query("UPDATE users SET reset_otp = '{$expiredOtp}', otp_expiry = '{$pastExpiry}' WHERE id = {$studentUserId}");

$resExpired = runScriptIsolated('auth/verify_reset_otp.php', [
    'reset_email' => $studentEmail,
    'reset_account' => $stAccount,
    'otp_attempts_' . md5($studentEmail) => 0
], [
    'verify' => '1',
    'otp' => $expiredOtp,
    'password' => "ValidPass123!"
]);

assertCondition(
    "15. Expired OTP correctly rejected",
    strpos($resExpired['stdout'], "Verification code has expired") !== false
);

// 16. CSRF Protection Check
$sessionWithCsrf = [
    'csrf_token' => 'known_valid_csrf_token_123',
    'reset_email' => $studentEmail,
    'reset_account' => $stAccount
];

$resCsrfBad = runScriptIsolated('auth/verify_reset_otp.php', $sessionWithCsrf, [
    'verify' => '1',
    'csrf_token' => 'invalid_csrf_token_999',
    'otp' => '123456',
    'password' => 'ValidPass123!'
]);

assertCondition(
    "16. CSRF protection rejects mismatched token",
    strpos($resCsrfBad['stdout'], "Invalid CSRF security token") !== false
);

// 17. Incorrect OTP Rejection
$testIncorrectOtp = "888999";
$validFutureExpiry = date("Y-m-d H:i:s", time() + 600);
$conn->query("UPDATE users SET reset_otp = '{$testIncorrectOtp}', otp_expiry = '{$validFutureExpiry}' WHERE id = {$studentUserId}");

$resWrongOtp = runScriptIsolated('auth/verify_reset_otp.php', [
    'reset_email' => $studentEmail,
    'reset_account' => $stAccount,
    'otp_attempts_' . md5($studentEmail) => 1
], [
    'verify' => '1',
    'otp' => '000000', // incorrect
    'password' => 'NewValidPass12!'
]);

assertCondition(
    "17. Incorrect OTP rejected with informative notice",
    strpos($resWrongOtp['stdout'], "Invalid verification code") !== false
);

// 18. Excessive Verification Attempts (5-attempt Lockout & DB Invalidation)
$resLockout = runScriptIsolated('auth/verify_reset_otp.php', [
    'reset_email' => $studentEmail,
    'reset_account' => $stAccount,
    'otp_attempts_' . md5($studentEmail) => 4 // 5th attempt
], [
    'verify' => '1',
    'otp' => '000000',
    'password' => 'NewValidPass12!'
]);

$chkLockedDb = $conn->query("SELECT reset_otp, otp_expiry FROM users WHERE id = {$studentUserId}")->fetch_assoc();
assertCondition(
    "18. Excessive attempts lockout invalidates OTP from database",
    strpos($resLockout['stdout'], "Too many failed verification attempts") !== false &&
    $chkLockedDb['reset_otp'] === null &&
    $chkLockedDb['otp_expiry'] === null,
    "OTP cleared in DB on 5th failed attempt"
);

// 19. SMTP Failure Handling (Never display 'OTP sent successfully' on mail failure)
$resSmtpFail = runScriptIsolated('auth/forgot_password.php', [], [
    'send_otp' => '1',
    'email' => $studentEmail
], true, false); // mockMail=true, mailSuccess=false

assertCondition(
    "19. SMTP failure reports error safely and NEVER displays 'OTP sent successfully'",
    strpos($resSmtpFail['stdout'], "Email service is temporarily unavailable") !== false &&
    strpos($resSmtpFail['stdout'], "OTP sent successfully") === false,
    "Delivery failure gracefully communicated to user"
);

// 20. Role and Account Isolation
$adminCurrentPass = $conn->query("SELECT password FROM users WHERE id = {$adminUserId}")->fetch_assoc()['password'];
assertCondition(
    "20. Role & Account Isolation: Student reset has zero side-effects on Admin account",
    password_verify($newAdminPass, $adminCurrentPass),
    "Admin password remains intact after student operations"
);

// 21. 'Request new OTP' on verify_reset_otp.php dispatches actual email to registered account
$resReqNew = runScriptIsolated('auth/verify_reset_otp.php', [
    'reset_email' => $studentEmail,
    'reset_account' => $stAccount,
    'otp_attempts_' . md5(strtolower($studentEmail)) => 3,
    'last_otp_time_' . md5(strtolower($studentEmail)) => time() - 100 // past cooldown
], [
    'resend_otp' => '1'
], true, true);

$capturedResendTo = $resReqNew['mail']['to'] ?? null;
$chkResendDb = $conn->query("SELECT reset_otp, otp_expiry FROM users WHERE id = {$studentUserId}")->fetch_assoc();
assertCondition(
    "21. 'Request new OTP' action sends fresh OTP directly to student's registered email",
    $capturedResendTo === $studentEmail && !empty($chkResendDb['reset_otp']) && strlen($chkResendDb['reset_otp']) === 6,
    "New OTP delivered to: {$capturedResendTo}"
);

// 22. 'Request new OTP' respects cooldown rate limit
$resReqCooldown = runScriptIsolated('auth/verify_reset_otp.php', [
    'reset_email' => $studentEmail,
    'reset_account' => $stAccount,
    'last_otp_time_' . md5(strtolower($studentEmail)) => time() - 10 // only 10s ago
], [
    'resend_otp' => '1'
], true, true);

assertCondition(
    "22. 'Request new OTP' action enforces cooldown rate limit",
    strpos($resReqCooldown['stdout'], "Please wait") !== false,
    "Cooldown message displayed to prevent email spam"
);

// 23. Ambiguity / Duplicate Email Detection
$duplicateTestEmail = "duplicate_detect_" . uniqid() . "@example.com";
$conn->query("CREATE TABLE IF NOT EXISTS tbl_admin (id INT AUTO_INCREMENT PRIMARY KEY, admin_email VARCHAR(100), password VARCHAR(255), reset_otp VARCHAR(6), otp_expiry DATETIME)");
$conn->query("INSERT INTO tbl_admin (admin_email, password) VALUES ('{$duplicateTestEmail}', 'passAdmin')");

$conn->query("INSERT INTO users (name, email, password, role) VALUES ('Dup Student 1', '{$duplicateTestEmail}', 'pass1', 'student')");
$dupId1 = $conn->insert_id;

getDatabaseTables($conn, true); // force refresh tables cache
getTableColumns($conn, 'tbl_admin', true);

$ambigAccount = findAccountForPasswordReset($conn, $duplicateTestEmail);
assertCondition(
    "23. Duplicate emails across account types detected as ambiguous and safely halted",
    $ambigAccount !== null && !empty($ambigAccount['ambiguous']),
    "Ambiguity flag prevents resetting the wrong account"
);
$conn->query("DELETE FROM users WHERE id = {$dupId1}");
$conn->query("DROP TABLE IF EXISTS tbl_admin");
getDatabaseTables($conn, true);

// 24. Verify CSS & Markup Standards on auth/verify_reset_otp.php
$verifyHtml = file_get_contents(__DIR__ . '/../auth/verify_reset_otp.php');
$hasAccessibleFocus = strpos($verifyHtml, 'outline: none !important') !== false && strpos($verifyHtml, 'box-shadow:') !== false;
$hasUnifiedInputHeight = strpos($verifyHtml, 'height: 46px') !== false;
$hasFullWidthButton = strpos($verifyHtml, 'width: 100%') !== false;
$hasAlignedEye = strpos($verifyHtml, 'transform: translateY(-50%)') !== false && strpos($verifyHtml, 'toggle-eye') !== false;
$hasResponsiveBox = strpos($verifyHtml, '@media (max-width: 480px)') !== false;

// Task 2 footer layout & hover styling checks:
$hasFlexFooter = strpos($verifyHtml, 'display: flex') !== false && strpos($verifyHtml, 'justify-content: space-between') !== false;
$hasLeftAlignedResend = strpos($verifyHtml, '.nav-left') !== false && strpos($verifyHtml, 'justify-content: flex-start') !== false;
$hasRightAlignedLogin = strpos($verifyHtml, '.nav-right') !== false && strpos($verifyHtml, 'justify-content: flex-end') !== false;
$hasCenteredBullet = strpos($verifyHtml, '&bull;') !== false && strpos($verifyHtml, '.divider') !== false;
$hasClickableFooterLinks = strpos($verifyHtml, 'id="btnResendOtp"') !== false && strpos($verifyHtml, 'href="login.php"') !== false;
$hasTransparentHover = strpos($verifyHtml, 'background: transparent !important') !== false;
$hasHoverTextColor = strpos($verifyHtml, 'color: #0a58ca !important') !== false;
$hasActiveStyle = strpos($verifyHtml, '.link-button:active') !== false;

assertCondition(
    "24. auth/verify_reset_otp.php CSS satisfies accessibility, alignment, responsive footer, transparent hover, and markup standards",
    $hasAccessibleFocus && $hasUnifiedInputHeight && $hasFullWidthButton && $hasAlignedEye && $hasResponsiveBox &&
    $hasFlexFooter && $hasLeftAlignedResend && $hasRightAlignedLogin && $hasCenteredBullet && $hasClickableFooterLinks &&
    $hasTransparentHover && $hasHoverTextColor && $hasActiveStyle,
    "Footer layout, transparent hover without blue background box, accessible focus, 46px inputs, aligned eye icon verified"
);

// Clean up fixtures
cleanupUserByEmail($conn, $studentEmail);
cleanupUserByEmail($conn, $adminEmail);

echo "\n====================================================\n";
echo "Summary: {$totalPassed} Passed, {$totalFailed} Failed\n";
echo "====================================================\n";

if ($totalFailed > 0) {
    exit(1);
}
