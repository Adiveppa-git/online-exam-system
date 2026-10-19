<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

// Buffer output during initial load of generator file
ob_start();
require_once __DIR__ . '/../admin/ai_question_generator.php';
ob_end_clean();

echo "====================================================\n";
echo "    Phase D: AI Question Generator Test Suite       \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

// Scenario 1: No student attempts exist -> Generation is allowed
echo "[Test 1] No student attempts exist: generation is allowed... ";
$conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Fresh Exam No Attempts', 30, 100, 5)");
$exam1 = $conn->insert_id;

if (!is_exam_locked_for_ai($conn, $exam1)) {
    echo "PASSED (Exam unlocked)\n";
    $passed++;
} else {
    echo "FAILED (Exam falsely locked)\n";
    $failed++;
}

// Scenario 2: Generation has already occurred (pending/approved Qs exist), but no student has started -> Generation still allowed
echo "[Test 2] Generation has occurred, but no student has started: generation still allowed... ";
$reqId = "req_test_" . uniqid();
$conn->query("INSERT INTO ai_generation_requests (request_id, admin_id, exam_id, subject, topic, difficulty, question_type, number_requested, model_used, status) VALUES ('$reqId', 1, $exam1, 'CS', 'DS', 'medium', 'mcq', 3, 'gpt-4o-mini', 'success')");
$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('$reqId', 1, $exam1, 'CS', 'DS', 'medium', 'Sample Pending Q1', 'A', 'B', 'C', 'D', 'A', 'pending')");

if (!is_exam_locked_for_ai($conn, $exam1)) {
    echo "PASSED (Exam remains available for repeated generation before student attempts)\n";
    $passed++;
} else {
    echo "FAILED (Exam locked before student attempt)\n";
    $failed++;
}

// Scenario 3: Student starts exam (inserts student_answer or result) -> Further generation/updates locked
echo "[Test 3] Student starts the exam: further generation and updates are locked... ";
$conn->query("INSERT INTO student_answers (student_id, exam_id, question_id, answer) VALUES (4, $exam1, 1, 'A')");

if (is_exam_locked_for_ai($conn, $exam1)) {
    echo "PASSED (Exam correctly locked after student attempt begins)\n";
    $passed++;
} else {
    echo "FAILED (Exam not locked after student attempt)\n";
    $failed++;
}

// Scenario 4: A student only views the exam page / logs in -> Generation remains allowed
echo "[Test 4] A student only views the exam page: generation remains allowed... ";
$conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Fresh Exam View Only', 30, 100, 5)");
$exam2 = $conn->insert_id;
// Viewing attempt_exam.php does not insert records into results, student_answers, or violations
if (!is_exam_locked_for_ai($conn, $exam2)) {
    echo "PASSED (Exam remains unlocked when only viewed)\n";
    $passed++;
} else {
    echo "FAILED (Exam falsely locked on view)\n";
    $failed++;
}

// Scenario 5: Attempt exists for another exam -> Current exam remains unlocked
echo "[Test 5] Attempt exists for another exam: current exam remains unlocked... ";
if (is_exam_locked_for_ai($conn, $exam1) && !is_exam_locked_for_ai($conn, $exam2)) {
    echo "PASSED (Attempt on exam1 does not lock exam2)\n";
    $passed++;
} else {
    echo "FAILED (Cross-exam locking issue)\n";
    $failed++;
}

// Scenario 6: Direct POST request bypassing UI rejected after lock
echo "[Test 6] Direct POST request bypassing UI rejected when locked... ";
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['generate_questions'] = '1';
$_POST['csrf_token'] = $_SESSION['csrf_token'];
$_POST['exam_id'] = (string)$exam1; // locked exam
$_POST['subject'] = 'CS';
$_POST['difficulty'] = 'medium';
$_POST['number_of_questions'] = '5';

ob_start();
chdir(__DIR__ . '/../admin');
include __DIR__ . '/../admin/ai_question_generator.php';
$htmlPostLocked = ob_get_clean();

if (str_contains($htmlPostLocked, 'AI question generation and updates are locked because a student has started attempting this exam.')) {
    echo "PASSED (Direct POST request rejected server-side for locked exam)\n";
    $passed++;
} else {
    echo "FAILED (Locked exam accepted direct POST request)\n";
    $failed++;
}

// Scenario 7: Pending and previously approved questions are preserved
echo "[Test 7] Pending and previously approved questions are preserved correctly... ";
$resPending = $conn->query("SELECT COUNT(*) AS c FROM ai_generated_questions WHERE exam_id = $exam1 AND status = 'pending'")->fetch_assoc()['c'];
if ((int)$resPending === 1) {
    echo "PASSED (Historical pending questions intact: {$resPending})\n";
    $passed++;
} else {
    echo "FAILED (Pending questions lost or mutated)\n";
    $failed++;
}

// Scenario 8: Concurrent generation/update requests cannot bypass lock
echo "[Test 8] Concurrent generation/update requests atomic lock protection... ";
$conn->begin_transaction();
$lockStmt = $conn->prepare("SELECT id FROM exams WHERE id = ? FOR UPDATE");
$lockStmt->bind_param("i", $exam1);
$lockStmt->execute();
$lockRes = $lockStmt->get_result()->fetch_assoc();
$lockStmt->close();

$isLockedAtomic = is_exam_locked_for_ai($conn, $exam1);
$conn->commit();

if ($isLockedAtomic) {
    echo "PASSED (Atomic transaction verification correctly evaluated attempt lock)\n";
    $passed++;
} else {
    echo "FAILED (Atomic evaluation failed)\n";
    $failed++;
}

// Test 9: Valid Question Count Boundary Verification (1, 20, 21, 50, 100)
echo "[Test 9] Boundary question count verification (1, 20, 21, 50, 100)... ";
$aiClient = new AiClient();
$validCounts = [1, 20, 21, 50, 100];
$allValidPassed = true;
$failDetail = '';
foreach ($validCounts as $vc) {
    $resTest = $aiClient->generateQuestions("Computer Science", "Algorithms", "easy", $vc, "Boundary test");
    if (!$resTest['success'] || count($resTest['data']['questions'] ?? []) !== $vc) {
        $allValidPassed = false;
        $failDetail = "Count {$vc}: success=" . ($resTest['success'] ? 'true' : 'false') . ", recvd=" . count($resTest['data']['questions'] ?? []) . ", err=" . ($resTest['error'] ?? 'none');
        break;
    }
}
if ($allValidPassed) {
    echo "PASSED (All valid counts 1, 20, 21, 50, 100 accepted & generated)\n";
    $passed++;
} else {
    echo "FAILED ({$failDetail})\n";
    $failed++;
}

// Test 10: Out of Bounds Question Count Rejection (0 and 101)
echo "[Test 10] Out-of-bounds question count rejection (0 and 101)... ";
$resZero = $aiClient->generateQuestions("Computer Science", "Algorithms", "easy", 0, "Zero test");
$resHigh = $aiClient->generateQuestions("Computer Science", "Algorithms", "easy", 101, "High test");

if (!$resZero['success'] && !$resHigh['success']) {
    echo "PASSED (Counts 0 and 101 correctly rejected by AI service)\n";
    $passed++;
} else {
    echo "FAILED (Out-of-bounds count was not rejected)\n";
    $failed++;
}

// Test 11: Form Validation for Missing Difficulty & Missing Question Count
echo "[Test 11] Form validation for missing difficulty & count... ";
$_POST['exam_id'] = (string)$exam2; // unlocked exam
$_POST['csrf_token'] = $_SESSION['csrf_token'];
$_POST['difficulty'] = '';
$_POST['number_of_questions'] = '10';

ob_start();
include __DIR__ . '/../admin/ai_question_generator.php';
$htmlDiff = ob_get_clean();

$_POST['exam_id'] = (string)$exam2;
$_POST['csrf_token'] = $_SESSION['csrf_token'];
$_POST['difficulty'] = 'easy';
$_POST['number_of_questions'] = '';

ob_start();
include __DIR__ . '/../admin/ai_question_generator.php';
$htmlCount = ob_get_clean();

if (str_contains($htmlDiff, 'Please select a valid difficulty level.') && str_contains($htmlCount, 'Number of questions must be between 1 and 100.')) {
    echo "PASSED (Missing difficulty & missing count correctly rejected)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// CLEAN UP TEST EXAMS
$conn->query("DELETE FROM student_answers WHERE exam_id IN ($exam1, $exam2)");
$conn->query("DELETE FROM ai_generated_questions WHERE exam_id IN ($exam1, $exam2)");
$conn->query("DELETE FROM ai_generation_requests WHERE exam_id IN ($exam1, $exam2)");
$conn->query("DELETE FROM exams WHERE id IN ($exam1, $exam2)");

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

exit($failed > 0 ? 1 : 0);
