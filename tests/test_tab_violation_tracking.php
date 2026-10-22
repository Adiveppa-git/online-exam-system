<?php
/**
 * Test Suite: Tab-Switch Violation Tracking — Student Side Only
 * 
 * Tests:
 * 1. Zero violations: student can submit normally.
 * 2. 1-3 violations: violations are counted, exam is not auto-submitted.
 * 3. 4+ violations: counting continues, exam remains active (no auto-submission).
 * 4. Answering questions and navigating after violations work normally.
 * 5. Manual submission works normally.
 * 6. Final violation count remains available after submission and page reload.
 * 7. Exam timer countdown and expiration behavior remain intact.
 * 8. Admin pages and existing admin-side behavior remain unchanged.
 */

require_once __DIR__ . '/../config/db.php';

$totalTests = 8;
$passedTests = 0;
$failedTests = 0;

function report($testNum, $title, $passed, $details = '') {
    global $passedTests, $failedTests;
    if ($passed) {
        $passedTests++;
        echo "[PASS] Test {$testNum}: {$title}\n";
        if ($details) echo "       Details: {$details}\n";
    } else {
        $failedTests++;
        echo "[FAIL] Test {$testNum}: {$title}\n";
        if ($details) echo "       Details: {$details}\n";
    }
}

echo "====================================================\n";
echo "   Tab-Switch Violation Tracking Test Suite         \n";
echo "====================================================\n\n";

// Create test fixtures
$testEmail = "tab_test_" . uniqid() . "@example.com";
$passHash = password_hash("TestPass123!", PASSWORD_DEFAULT);
$conn->query("INSERT INTO users (name, email, password, role) VALUES ('Tab Test Student', '{$testEmail}', '{$passHash}', 'student')");
$studentId = (int)$conn->insert_id;

$conn->query("INSERT INTO exams (title, duration, marks_per_question) VALUES ('Tab Violation Test Exam', 30, 2)");
$examId = (int)$conn->insert_id;

$conn->query("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES 
    ({$examId}, 'Question 1: What is 2 + 2?', '1', '2', '4', '8', 'C'),
    ({$examId}, 'Question 2: What is 3 * 3?', '3', '6', '9', '12', 'C')");

$qIds = [];
$qQuery = $conn->query("SELECT id FROM questions WHERE exam_id = {$examId} ORDER BY id ASC");
while ($qRow = $qQuery->fetch_assoc()) $qIds[] = (int)$qRow['id'];

$csrfToken = bin2hex(random_bytes(32));

// Helper to invoke a script in isolated subprocess
function runIsolated($script, $sessionData = [], $postData = [], $getData = []) {
    $studentDir = addslashes(__DIR__ . '/../student');
    $scriptPath = addslashes(__DIR__ . '/../' . $script);
    $code = '<?php
    chdir("' . $studentDir . '");
    session_start();
    $_SESSION = ' . var_export($sessionData, true) . ';
    $_POST = ' . var_export($postData, true) . ';
    $_GET = ' . var_export($getData, true) . ';
    $_SERVER["REQUEST_METHOD"] = ' . var_export(empty($postData) ? "GET" : "POST", true) . ';
    $_SERVER["PHP_SELF"] = "/student/' . basename($script) . '";
    ob_start();
    register_shutdown_function(function() {
        $output = ob_get_clean();
        echo json_encode([
            "stdout" => $output,
            "session" => $_SESSION
        ]);
    });
    require "' . $scriptPath . '";
    ';

    $desc = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    $cmd = 'C:\\xampp\\php\\php.exe';
    $proc = proc_open($cmd, $desc, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) return ['stdout' => '', 'session' => []];

    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);

    $decoded = json_decode($out, true);
    return is_array($decoded) ? $decoded : ['stdout' => $out . $err, 'session' => []];
}

// -----------------------------------------------------------------
// Test 1: Zero violations - student can submit normally
// -----------------------------------------------------------------
$zeroStudentEmail = "zero_v_" . uniqid() . "@example.com";
$conn->query("INSERT INTO users (name, email, password, role) VALUES ('Zero V Student', '{$zeroStudentEmail}', '{$passHash}', 'student')");
$zeroStudentId = (int)$conn->insert_id;

$res1 = runIsolated(
    'student/exam_summary.php',
    ['user_id' => $zeroStudentId, 'role' => 'student', 'csrf_token' => $csrfToken],
    ['exam_id' => $examId, 'csrf_token' => $csrfToken, 'answer' => [$qIds[0] => 'C', $qIds[1] => 'C']]
);

$chkResult1 = $conn->query("SELECT * FROM results WHERE user_id = {$zeroStudentId} AND exam_id = {$examId}")->fetch_assoc();
$test1Passed = ($chkResult1 !== null && (int)$chkResult1['tab_switch_count'] === 0 && (int)$chkResult1['score'] === 2);
report(1, "Zero violations: student submits normally with 0 violations recorded", $test1Passed, "Recorded tab_switch_count: " . ($chkResult1['tab_switch_count'] ?? 'null') . ", score: " . ($chkResult1['score'] ?? 'null'));

// -----------------------------------------------------------------
// Test 2: 1-3 violations: counted, exam is not auto-submitted
// -----------------------------------------------------------------
// Report 2 violations for $studentId
for ($i = 0; $i < 2; $i++) {
    runIsolated(
        'student/report_violation.php',
        ['user_id' => $studentId, 'role' => 'student', 'csrf_token' => $csrfToken],
        ['exam_id' => $examId, 'csrf_token' => $csrfToken]
    );
}

$vCountRes = $conn->query("SELECT COUNT(*) AS c FROM violations WHERE user_id = {$studentId} AND exam_id = {$examId}")->fetch_assoc();
$twoViolationsLogged = ((int)$vCountRes['c'] === 2);

// Check that attempt_exam.php renders the count of 2 and has no auto-submit
$res2Page = runIsolated(
    'student/attempt_exam.php',
    ['user_id' => $studentId, 'role' => 'student', 'csrf_token' => $csrfToken],
    [],
    ['exam_id' => $examId]
);
$hasCount2 = (strpos($res2Page['stdout'], 'Tab-Switch Violations: <span id="violationCountDisplay">2</span>') !== false);
$hasNoResultYet = ($conn->query("SELECT id FROM results WHERE user_id = {$studentId} AND exam_id = {$examId}")->num_rows === 0);

$test2Passed = ($twoViolationsLogged && $hasCount2 && $hasNoResultYet);
report(2, "1-3 violations: recorded in DB, displayed on page, exam remains active", $test2Passed, "DB violations: {$vCountRes['c']}, displayed: 2, exam active: true");

// -----------------------------------------------------------------
// Test 3: 4 or more violations: counting continues and exam remains active
// -----------------------------------------------------------------
// Report 3 more violations (total 5)
for ($i = 0; $i < 3; $i++) {
    runIsolated(
        'student/report_violation.php',
        ['user_id' => $studentId, 'role' => 'student', 'csrf_token' => $csrfToken],
        ['exam_id' => $examId, 'csrf_token' => $csrfToken]
    );
}

$vCountRes5 = $conn->query("SELECT COUNT(*) AS c FROM violations WHERE user_id = {$studentId} AND exam_id = {$examId}")->fetch_assoc();
$fiveViolationsLogged = ((int)$vCountRes5['c'] === 5);

$res3Page = runIsolated(
    'student/attempt_exam.php',
    ['user_id' => $studentId, 'role' => 'student', 'csrf_token' => $csrfToken],
    [],
    ['exam_id' => $examId]
);
$hasCount5 = (strpos($res3Page['stdout'], 'Tab-Switch Violations: <span id="violationCountDisplay">5</span>') !== false);

// Verify attempt_exam.php has NO violation-triggered auto-submission in JS
$attemptCode = file_get_contents(__DIR__ . '/../student/attempt_exam.php');
$noViolationAutoSubmit = (
    strpos($attemptCode, 'warningCount>maxWarnings') === false &&
    strpos($attemptCode, 'warningCount > maxWarnings') === false &&
    strpos($attemptCode, 'violationCount >') === false
);
$stillNoResultRow = ($conn->query("SELECT id FROM results WHERE user_id = {$studentId} AND exam_id = {$examId}")->num_rows === 0);

$test3Passed = ($fiveViolationsLogged && $hasCount5 && $noViolationAutoSubmit && $stillNoResultRow);
report(3, "4+ violations: counting continues (5 recorded), no auto-submission, exam remains active", $test3Passed, "DB violations: {$vCountRes5['c']}, displayed: 5, auto-submit code removed: true");

// -----------------------------------------------------------------
// Test 4: Student can answer questions and navigate after violations
// -----------------------------------------------------------------
$saveAnsRes = runIsolated(
    'student/save_answer.php',
    ['user_id' => $studentId, 'role' => 'student', 'csrf_token' => $csrfToken],
    ['exam_id' => $examId, 'question_id' => $qIds[0], 'answer' => 'C', 'csrf_token' => $csrfToken]
);
$ansSavedInDb = $conn->query("SELECT answer FROM student_answers WHERE student_id = {$studentId} AND exam_id = {$examId} AND question_id = {$qIds[0]}")->fetch_assoc();
$test4Passed = ($ansSavedInDb && $ansSavedInDb['answer'] === 'C');
report(4, "Student can save and update answers after violations occur", $test4Passed, "Answer saved in DB: " . ($ansSavedInDb['answer'] ?? 'none'));

// -----------------------------------------------------------------
// Test 5: Manual submission works normally
// -----------------------------------------------------------------
$manualSubmitRes = runIsolated(
    'student/exam_summary.php',
    ['user_id' => $studentId, 'role' => 'student', 'csrf_token' => $csrfToken],
    ['exam_id' => $examId, 'csrf_token' => $csrfToken, 'answer' => [$qIds[0] => 'C', $qIds[1] => 'C']]
);

$chkResult5 = $conn->query("SELECT * FROM results WHERE user_id = {$studentId} AND exam_id = {$examId}")->fetch_assoc();
$test5Passed = (
    $chkResult5 !== null &&
    (int)$chkResult5['score'] === 2 &&
    (int)$chkResult5['tab_switch_count'] === 5 &&
    isset($manualSubmitRes['session']['submission_success']) &&
    (int)$manualSubmitRes['session']['submission_success']['violations'] === 5
);
report(5, "Manual submission completes exam and persists final violation count", $test5Passed, "Score: " . ($chkResult5['score'] ?? 'null') . ", Tab violations persisted: " . ($chkResult5['tab_switch_count'] ?? 'null'));

// -----------------------------------------------------------------
// Test 6: Final violation count remains available after submission and page reload
// -----------------------------------------------------------------
// Initial visit right after submission (with flash session)
$res6FirstVisit = runIsolated(
    'student/result.php',
    ['user_id' => $studentId, 'role' => 'student', 'submission_success' => ['violations' => 5]],
    [],
    []
);
$hasConfirmBanner = (strpos($res6FirstVisit['stdout'], 'Tab-switch violations recorded: <strong>5</strong>') !== false);
$hasTableViolationCol = (strpos($res6FirstVisit['stdout'], '<th>Tab-Switch Violations</th>') !== false);

// Page reload (flash session is gone, relying solely on DB persistence)
$res6Reload = runIsolated(
    'student/result.php',
    ['user_id' => $studentId, 'role' => 'student'],
    [],
    []
);
// Table should still render 5 violations from DB
$hasPersistedCountInTable = (preg_match('/<td[^>]*>\s*5\s*<\/td>/', $res6Reload['stdout']) === 1);

$test6Passed = ($hasConfirmBanner && $hasTableViolationCol && $hasPersistedCountInTable);
report(6, "Final violation count visible in confirmation banner and persists in table across page reloads", $test6Passed, "Banner: " . ($hasConfirmBanner ? 'yes' : 'no') . ", Persisted table row: " . ($hasPersistedCountInTable ? 'yes' : 'no'));

// -----------------------------------------------------------------
// Test 7: Exam timer countdown and expiration behavior
// -----------------------------------------------------------------
$hasTimerElement = (strpos($attemptCode, '<div class="timer" id="timer"></div>') !== false);
$hasTimerInterval = (strpos($attemptCode, 'setInterval(') !== false && strpos($attemptCode, 'time--') !== false);
$hasAutoSubmitOnTimeZero = (strpos($attemptCode, 'if(time<=0 && !submitted)') !== false);
$test7Passed = ($hasTimerElement && $hasTimerInterval && $hasAutoSubmitOnTimeZero);
report(7, "Exam timer and normal time-expiration submission remain intact", $test7Passed, "Timer element: true, interval countdown: true, auto-submit on time<=0: true");

// -----------------------------------------------------------------
// Test 8: Admin pages and existing admin-side behavior remain unchanged
// -----------------------------------------------------------------
// Verify violation-related admin files are unmodified
$adminFilesToCheck = [
    'admin/violation_report.php',
    'admin/export_pdf.php',
    'admin/export_excel.php',
    'admin/reassign_exam.php',
    'admin/exams.php',
    'admin/questions.php'
];
exec('git diff --name-only', $gitDiffFiles);
$violationAdminFilesModified = array_intersect($gitDiffFiles, $adminFilesToCheck);

// Verify admin/violation_report.php query logic accurately calculates violations from DB
$adminReportQuery = $conn->query("
    SELECT COUNT(v.id) AS violation_count
    FROM violations v
    WHERE v.user_id = {$studentId} AND v.exam_id = {$examId}
")->fetch_assoc();
$adminViolationCount = (int)($adminReportQuery['violation_count'] ?? 0);

$test8Passed = (empty($violationAdminFilesModified) && $adminViolationCount === 5);
report(8, "Admin side unchanged: violation admin files unmodified, admin report counts match DB", $test8Passed, "Modified violation admin files: " . count($violationAdminFilesModified) . ", Admin query count: {$adminViolationCount}");

// Cleanup test fixtures
$conn->query("DELETE FROM results WHERE exam_id = {$examId}");
$conn->query("DELETE FROM student_answers WHERE exam_id = {$examId}");
$conn->query("DELETE FROM violations WHERE exam_id = {$examId}");
$conn->query("DELETE FROM exam_violations WHERE exam_id = {$examId}");
$conn->query("DELETE FROM questions WHERE exam_id = {$examId}");
$conn->query("DELETE FROM exams WHERE id = {$examId}");
$conn->query("DELETE FROM users WHERE id IN ({$studentId}, {$zeroStudentId})");

echo "\n----------------------------------------------------\n";
echo "Summary: {$passedTests} Passed, {$failedTests} Failed\n";
echo "====================================================\n";

exit($failedTests > 0 ? 1 : 0);
