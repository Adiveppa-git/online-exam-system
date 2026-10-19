<?php
/**
 * Automated Verification Suite for Security Hardening Fixes
 * 1. Admin CSRF & Destructive GET Conversion (admin/exams.php, admin/questions.php, admin/reassign_exam.php)
 * 2. Student CSRF Enforcement & Workflow Preservation (student/save_answer.php, student/submit_exam.php, student/exam_summary.php)
 * 3. Session Fixation Defense & session_regenerate_id (auth/login.php)
 * 4. User Enumeration Defense (generic login failure messages)
 * 5. Docker Compose Baseline Schema Mount
 */

require_once __DIR__ . '/../config/db.php';

echo "====================================================\n";
echo "   Security Hardening & CSRF Verification Suite     \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

function reportResult($testName, $isSuccess, $details = "") {
    global $passed, $failed;
    if ($isSuccess) {
        echo "[PASS] {$testName}" . ($details ? " ({$details})" : "") . "\n";
        $passed++;
    } else {
        echo "[FAIL] {$testName}" . ($details ? " - {$details}" : "") . "\n";
        $failed++;
    }
}

/**
 * Executes a PHP script in an isolated sub-process so headers, exits, and die()
 * do not terminate or contaminate the main test suite runner.
 */
function runScriptIsolated($scriptRelativePath, $session = [], $post = [], $get = [], $headers = []) {
    $scriptFullPath = realpath(__DIR__ . '/../' . $scriptRelativePath);
    if (!$scriptFullPath || !file_exists($scriptFullPath)) {
        return ['stdout' => '', 'stderr' => "File not found: {$scriptRelativePath}", 'exit' => -1];
    }

    $payload = [
        'script' => $scriptFullPath,
        'session' => $session,
        'post' => $post,
        'get' => $get,
        'headers' => $headers
    ];
    $payloadJson = base64_encode(json_encode($payload));

    $bootstrapCode = '<?php
    $data = json_decode(base64_decode("' . $payloadJson . '"), true);
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = $data["session"] ?? [];
    $_POST = $data["post"] ?? [];
    $_GET = $data["get"] ?? [];
    $_SERVER["REQUEST_METHOD"] = !empty($_POST) ? "POST" : "GET";
    foreach (($data["headers"] ?? []) as $k => $v) {
        $_SERVER[$k] = $v;
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
        return ['stdout' => '', 'stderr' => 'Failed to proc_open php', 'exit' => -1];
    }

    fwrite($pipes[0], $bootstrapCode);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($proc);

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exit' => $exitCode
    ];
}

// -----------------------------------------------------------------
// Test 1: admin/exams.php blocks destructive GET delete
// -----------------------------------------------------------------
$testExamTitle = "CSRF_Test_Exam_" . uniqid();
$stmtEx = $conn->prepare("INSERT INTO exams (title, duration, marks_per_question, total_marks) VALUES (?, 10, 1, 0)");
$stmtEx->bind_param("s", $testExamTitle);
$stmtEx->execute();
$testExamId = $conn->insert_id;
$stmtEx->close();

$csrfAdmin = bin2hex(random_bytes(32));

$res1 = runScriptIsolated('admin/exams.php', ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfAdmin], [], ['delete' => $testExamId]);
$chkExam1 = $conn->query("SELECT id FROM exams WHERE id = {$testExamId}")->fetch_assoc();
$getDeleteBlocked = ($chkExam1 !== null && strpos($res1['stdout'], "Destructive actions must use POST") !== false);
reportResult("1. admin/exams.php blocks destructive GET delete", $getDeleteBlocked, "Exam preserved in DB and warning displayed");

// -----------------------------------------------------------------
// Test 2: admin/exams.php blocks POST delete with invalid CSRF token
// -----------------------------------------------------------------
$res2 = runScriptIsolated(
    'admin/exams.php',
    ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfAdmin],
    ['delete_exam' => '1', 'exam_id' => $testExamId, 'csrf_token' => 'invalid_csrf_token_123'],
    []
);
$chkExam2 = $conn->query("SELECT id FROM exams WHERE id = {$testExamId}")->fetch_assoc();
$postBadCsrfBlocked = ($chkExam2 !== null && strpos($res2['stdout'], "Invalid CSRF security token") !== false);
reportResult("2. admin/exams.php blocks POST delete with invalid CSRF token", $postBadCsrfBlocked, "Exam preserved in DB");

// -----------------------------------------------------------------
// Test 3: admin/exams.php allows POST delete with valid CSRF token
// -----------------------------------------------------------------
$res3 = runScriptIsolated(
    'admin/exams.php',
    ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfAdmin],
    ['delete_exam' => '1', 'exam_id' => $testExamId, 'csrf_token' => $csrfAdmin],
    []
);
$chkExam3 = $conn->query("SELECT id FROM exams WHERE id = {$testExamId}")->fetch_assoc();
$postValidCsrfSuccess = ($chkExam3 === null);
reportResult("3. admin/exams.php allows POST delete with valid CSRF token", $postValidCsrfSuccess, "Exam deleted securely");

// -----------------------------------------------------------------
// Test 4: admin/questions.php blocks destructive GET delete
// -----------------------------------------------------------------
$stmtEx2 = $conn->prepare("INSERT INTO exams (title, duration, marks_per_question, total_marks) VALUES ('Temp Exam Q', 10, 1, 0)");
$stmtEx2->execute();
$tempExId = $conn->insert_id;
$stmtEx2->close();

$stmtQ = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES (?, 'Q text', 'A', 'B', 'C', 'D', 'A')");
$stmtQ->bind_param("i", $tempExId);
$stmtQ->execute();
$testQId = $conn->insert_id;
$stmtQ->close();

$res4 = runScriptIsolated(
    'admin/questions.php',
    ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfAdmin],
    [],
    ['delete' => $testQId, 'mode' => 'manage']
);
$chkQExists = $conn->query("SELECT id FROM questions WHERE id = {$testQId}")->fetch_assoc();
reportResult("4. admin/questions.php blocks destructive GET delete", ($chkQExists !== null), "Question preserved in DB");

// -----------------------------------------------------------------
// Test 5: admin/questions.php blocks POST delete with invalid CSRF token
// -----------------------------------------------------------------
$res5 = runScriptIsolated(
    'admin/questions.php',
    ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfAdmin],
    ['delete_question' => '1', 'id' => $testQId, 'csrf_token' => 'fake_csrf_token'],
    ['mode' => 'manage']
);
$chkQStillExists = $conn->query("SELECT id FROM questions WHERE id = {$testQId}")->fetch_assoc();
reportResult("5. admin/questions.php blocks POST delete with invalid CSRF token", ($chkQStillExists !== null), "Question preserved in DB");

// -----------------------------------------------------------------
// Test 6: admin/questions.php allows POST delete with valid CSRF token
// -----------------------------------------------------------------
$res6 = runScriptIsolated(
    'admin/questions.php',
    ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfAdmin],
    ['delete_question' => '1', 'id' => $testQId, 'csrf_token' => $csrfAdmin],
    ['mode' => 'manage']
);
$chkQDeleted = $conn->query("SELECT id FROM questions WHERE id = {$testQId}")->fetch_assoc();
$postValidQCsrfSuccess = ($chkQDeleted === null);
reportResult("6. admin/questions.php allows POST delete with valid CSRF token", $postValidQCsrfSuccess, "Question deleted securely");

// Clean temp exam
$conn->query("DELETE FROM exams WHERE id = {$tempExId}");

// -----------------------------------------------------------------
// Test 7: admin/reassign_exam.php enforces POST and CSRF validation
// -----------------------------------------------------------------
$stmtExRe = $conn->prepare("INSERT INTO exams (title, duration, marks_per_question) VALUES ('Reassign Test Exam', 10, 1)");
$stmtExRe->execute();
$reExId = $conn->insert_id;
$stmtExRe->close();

$resReGet = runScriptIsolated(
    'admin/reassign_exam.php',
    ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfAdmin],
    [],
    ['exam_id' => $reExId]
);
$reGetBlocked = (strpos($resReGet['stdout'], "Destructive action requires POST") !== false);

$resReBadCsrf = runScriptIsolated(
    'admin/reassign_exam.php',
    ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfAdmin],
    ['exam_id' => $reExId, 'csrf_token' => 'invalid_csrf'],
    []
);
$reCsrfBlocked = (strpos($resReBadCsrf['stdout'], "Invalid CSRF") !== false);

$resReGood = runScriptIsolated(
    'admin/reassign_exam.php',
    ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfAdmin],
    ['exam_id' => $reExId, 'csrf_token' => $csrfAdmin],
    []
);
$reGoodSuccess = (strpos($resReGood['stdout'], "Invalid CSRF") === false && strpos($resReGood['stdout'], "requires POST") === false);

$conn->query("DELETE FROM exams WHERE id = {$reExId}");
reportResult("7. admin/reassign_exam.php enforces POST and CSRF validation", ($reGetBlocked && $reCsrfBlocked && $reGoodSuccess), "GET and bad CSRF rejected, valid POST accepted");

// -----------------------------------------------------------------
// Student Test Fixtures (with valid student user for foreign keys)
// -----------------------------------------------------------------
$stmtU = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES ('Security Test Student', 'sectest_student@example.com', 'dummyhash', 'student')");
$stmtU->execute();
$testStudentId = $conn->insert_id;
$stmtU->close();

$stmtEx3 = $conn->prepare("INSERT INTO exams (title, duration, marks_per_question) VALUES ('Student Security Test Exam', 10, 1)");
$stmtEx3->execute();
$stExamId = $conn->insert_id;
$stmtEx3->close();

$stmtQ3 = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES (?, 'Q student test', 'A', 'B', 'C', 'D', 'B')");
$stmtQ3->bind_param("i", $stExamId);
$stmtQ3->execute();
$stQId = $conn->insert_id;
$stmtQ3->close();

$studentCsrf = bin2hex(random_bytes(32));

// -----------------------------------------------------------------
// Test 8: student/save_answer.php rejects requests without CSRF token
// -----------------------------------------------------------------
$res8 = runScriptIsolated(
    'student/save_answer.php',
    ['role' => 'student', 'user_id' => $testStudentId, 'csrf_token' => $studentCsrf],
    ['exam_id' => $stExamId, 'question_id' => $stQId, 'answer' => 'B']
);
$json8 = json_decode($res8['stdout'], true);
$noCsrfBlocked = ($json8 && $json8['status'] === 'error' && strpos($json8['msg'], 'CSRF') !== false);
reportResult("8. student/save_answer.php rejects requests without CSRF token", $noCsrfBlocked, "Error: " . ($json8['msg'] ?? 'none'));

// -----------------------------------------------------------------
// Test 9: student/save_answer.php rejects requests with invalid CSRF token
// -----------------------------------------------------------------
$res9 = runScriptIsolated(
    'student/save_answer.php',
    ['role' => 'student', 'user_id' => $testStudentId, 'csrf_token' => $studentCsrf],
    ['exam_id' => $stExamId, 'question_id' => $stQId, 'answer' => 'B', 'csrf_token' => 'invalid_csrf']
);
$json9 = json_decode($res9['stdout'], true);
$badCsrfBlocked = ($json9 && $json9['status'] === 'error' && strpos($json9['msg'], 'CSRF') !== false);
reportResult("9. student/save_answer.php rejects requests with invalid CSRF token", $badCsrfBlocked);

// -----------------------------------------------------------------
// Test 10: student/save_answer.php saves answer with valid CSRF token
// -----------------------------------------------------------------
$res10 = runScriptIsolated(
    'student/save_answer.php',
    ['role' => 'student', 'user_id' => $testStudentId, 'csrf_token' => $studentCsrf],
    ['exam_id' => $stExamId, 'question_id' => $stQId, 'answer' => 'B', 'csrf_token' => $studentCsrf]
);
$json10 = json_decode($res10['stdout'], true);
$chkSaved = $conn->query("SELECT answer FROM student_answers WHERE student_id = {$testStudentId} AND exam_id = {$stExamId} AND question_id = {$stQId}")->fetch_assoc();
$goodCsrfSaved = ($json10 && $json10['status'] === 'success' && $chkSaved && $chkSaved['answer'] === 'B');
reportResult("10. student/save_answer.php saves answer with valid CSRF token", $goodCsrfSaved, "Answer persisted in DB");

// -----------------------------------------------------------------
// Test 11: student/submit_exam.php rejects submissions with bad CSRF token
// -----------------------------------------------------------------
$res11 = runScriptIsolated(
    'student/submit_exam.php',
    ['role' => 'student', 'user_id' => $testStudentId, 'csrf_token' => $studentCsrf],
    ['exam_id' => $stExamId, 'csrf_token' => 'bad_csrf_token']
);
$chkNoResult = $conn->query("SELECT id FROM results WHERE user_id = {$testStudentId} AND exam_id = {$stExamId}")->fetch_assoc();
$submitBadBlocked = ($chkNoResult === null && strpos($res11['stdout'], "CSRF") !== false);
reportResult("11. student/submit_exam.php rejects submissions with bad CSRF token", $submitBadBlocked, "No result row created");

// -----------------------------------------------------------------
// Test 12: student/submit_exam.php succeeds with valid CSRF token
// -----------------------------------------------------------------
$res12 = runScriptIsolated(
    'student/submit_exam.php',
    ['role' => 'student', 'user_id' => $testStudentId, 'csrf_token' => $studentCsrf],
    ['exam_id' => $stExamId, 'csrf_token' => $studentCsrf]
);
$chkGoodResult = $conn->query("SELECT id, score FROM results WHERE user_id = {$testStudentId} AND exam_id = {$stExamId}")->fetch_assoc();
$submitGoodSuccess = ($chkGoodResult !== null && (int)$chkGoodResult['score'] === 1);
reportResult("12. student/submit_exam.php completes exam with valid CSRF token", $submitGoodSuccess, "Result score computed: " . ($chkGoodResult['score'] ?? 'none'));

// Clean fixtures
$conn->query("DELETE FROM results WHERE user_id = {$testStudentId} AND exam_id = {$stExamId}");
$conn->query("DELETE FROM student_answers WHERE student_id = {$testStudentId}");
$conn->query("DELETE FROM questions WHERE exam_id = {$stExamId}");
$conn->query("DELETE FROM exams WHERE id = {$stExamId}");
$conn->query("DELETE FROM users WHERE id = {$testStudentId}");

// -----------------------------------------------------------------
// Test 13: auth/login.php user enumeration prevention
// -----------------------------------------------------------------
$loginCode = file_get_contents(__DIR__ . '/../auth/login.php');
$hasGenericFailMsg = (
    strpos($loginCode, '$error = "Invalid email or password";') !== false &&
    strpos($loginCode, '$error = "Email not registered";') === false &&
    strpos($loginCode, '$error = "Invalid password";') === false
);
reportResult("13. auth/login.php unifies failure messages to prevent user enumeration", $hasGenericFailMsg, "Generic message used across all failure paths");

// -----------------------------------------------------------------
// Test 14: auth/login.php session fixation defense
// -----------------------------------------------------------------
$hasSessionRegen = (strpos($loginCode, 'session_regenerate_id(true);') !== false);
reportResult("14. auth/login.php calls session_regenerate_id(true) upon authentication", $hasSessionRegen, "Session fixation defense active");

// -----------------------------------------------------------------
// Test 15: docker-compose.yml fresh database initialization
// -----------------------------------------------------------------
$dockerCompose = file_get_contents(__DIR__ . '/../docker-compose.yml');
$hasBaselineMount = (strpos($dockerCompose, '000_online_exam_system.sql') !== false && strpos($dockerCompose, '/docker-entrypoint-initdb.d') !== false);
reportResult("15. docker-compose.yml mounts baseline schema before migrations", $hasBaselineMount, "000_online_exam_system.sql mounted to entrypoint");

// -----------------------------------------------------------------
// Test 16: auth/verify_reset_otp.php database inclusion & session auth
// -----------------------------------------------------------------
$res16 = runScriptIsolated('auth/verify_reset_otp.php', [], [], []);
$noFatalOnAuth = (strpos($res16['stdout'], "Fatal error") === false && strpos($res16['stderr'], "Fatal error") === false);
reportResult("16. auth/verify_reset_otp.php loads cleanly without fatal errors", $noFatalOnAuth, "No undefined \$conn fatal error");

// -----------------------------------------------------------------
// Test 17: auth/verify_reset_otp.php invalid OTP verification via DB
// -----------------------------------------------------------------
$testResetEmail = "otp_reset_user_" . uniqid() . "@example.com";
$initialPass = password_hash("OldPassword123!", PASSWORD_DEFAULT);
$validOtp = "654321";
$validExpiry = date("Y-m-d H:i:s", time() + 600);

$stmtUser = $conn->prepare("INSERT INTO users (name, email, password, role, reset_otp, otp_expiry) VALUES ('Reset User', ?, ?, 'student', ?, ?)");
$stmtUser->bind_param("ssss", $testResetEmail, $initialPass, $validOtp, $validExpiry);
$stmtUser->execute();
$resetUserId = $conn->insert_id;
$stmtUser->close();

$attemptKey = 'otp_attempts_' . md5($testResetEmail);
$sessionData = [
    'reset_email' => $testResetEmail,
    $attemptKey => 0
];

// Submit invalid OTP
$res17 = runScriptIsolated(
    'auth/verify_reset_otp.php',
    $sessionData,
    ['verify' => '1', 'otp' => '000000', 'password' => 'NewPassword123!'],
    []
);
$invalidOtpHandled = (
    strpos($res17['stdout'], "Invalid verification code.") !== false &&
    strpos($res17['stdout'], "Fatal error") === false &&
    strpos($res17['stderr'], "Fatal error") === false
);
reportResult("17. auth/verify_reset_otp.php rejects invalid OTP using DB lookup", $invalidOtpHandled, "Query executed without \$conn error");

// -----------------------------------------------------------------
// Test 18: auth/verify_reset_otp.php valid OTP password reset
// -----------------------------------------------------------------
$res18 = runScriptIsolated(
    'auth/verify_reset_otp.php',
    $sessionData,
    ['verify' => '1', 'otp' => $validOtp, 'password' => 'Secret123!'],
    []
);

$chkUserAfter = $conn->query("SELECT password, reset_otp, otp_expiry FROM users WHERE id = {$resetUserId}")->fetch_assoc();
$resetSuccessful = (
    $chkUserAfter !== null &&
    password_verify('Secret123!', $chkUserAfter['password']) &&
    $chkUserAfter['reset_otp'] === null &&
    $chkUserAfter['otp_expiry'] === null
);
reportResult("18. auth/verify_reset_otp.php successfully resets password on valid OTP", $resetSuccessful, "New password verified in DB, OTP cleared");

// Cleanup user fixture
$conn->query("DELETE FROM users WHERE id = {$resetUserId}");

// -----------------------------------------------------------------
// Test 19: auth/verify_reset_otp.php safe database unavailability handling
// -----------------------------------------------------------------
$verifyCode = file_get_contents(__DIR__ . '/../auth/verify_reset_otp.php');
$hasSafeDbCheck = (
    strpos($verifyCode, 'require_once __DIR__ . "/../config/db.php";') !== false &&
    strpos($verifyCode, '!isset($conn)') !== false &&
    strpos($verifyCode, 'Database service is temporarily unavailable') !== false
);
reportResult("19. auth/verify_reset_otp.php includes reliable db path and safe fallback", $hasSafeDbCheck, "__DIR__ db.php included with fallback");

// -----------------------------------------------------------------
// Test 20: Project-wide absence of confirmation dialogs and popups
// -----------------------------------------------------------------
$filesToCheck = [
    __DIR__ . '/../admin/exams.php',
    __DIR__ . '/../admin/questions.php',
    __DIR__ . '/../admin/review_ai_questions.php',
    __DIR__ . '/../admin/sidebar.php',
    __DIR__ . '/../student/sidebar.php',
    __DIR__ . '/../student/attempt_exam.php',
    __DIR__ . '/../assets/js/main.js'
];

$foundConfirmDialog = false;
$foundDetails = [];

foreach ($filesToCheck as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    if (preg_match('/(confirm\s*\(|confirmReject|onsubmit\s*=\s*[\'"][^\'"]*confirm)/i', $c, $m)) {
        $foundConfirmDialog = true;
        $foundDetails[] = basename($f) . ": " . $m[0];
    }
}

reportResult(
    "20. Absence of confirmation popups across Admin, Student, and core workflows",
    !$foundConfirmDialog,
    !$foundConfirmDialog ? "Zero confirmation popups present" : "Found in " . implode(', ', $foundDetails)
);

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

exit($failed > 0 ? 1 : 0);
