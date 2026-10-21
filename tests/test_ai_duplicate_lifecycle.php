<?php
/**
 * Test Suite: AI Question Generation Duplicate Detection Lifecycle & Deletion Handling
 * 
 * Verifies:
 * 1. Generating questions for an exam stages them into ai_generated_questions (pending).
 * 2. Generating identical questions while pending stages are active correctly detects duplicates.
 * 3. Approving pending questions moves them to questions table and marks ai_generated_questions as approved.
 * 4. Generating identical questions while active questions exist in questions table correctly detects duplicates.
 * 5. Deleting questions from questions table (normal deletion workflow) allows re-generating questions.
 * 6. Historical approved/rejected records in ai_generated_questions no longer falsely block generation of deleted questions.
 * 7. Duplicate prevention remains exam-scoped.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/text_helpers.php';
require_once __DIR__ . '/../config/ai_client.php';

echo "====================================================================\n";
echo "   AI Question Generation Duplicate Lifecycle & Deletion Test Suite  \n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $name, string $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $name\n";
        $passed++;
    } else {
        echo "  [FAIL] $name" . ($details ? ": $details" : "") . "\n";
        $failed++;
    }
}

// Setup a clean test exam
$conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Java Duplicate Lifecycle Test', 30, 100, 5)");
$testExamId = (int)$conn->insert_id;
assert_test($testExamId > 0, "Created temporary test exam (ID: {$testExamId})");

try {
    // -------------------------------------------------------------
    // TEST 1: Initial state - No questions exist
    // -------------------------------------------------------------
    echo "\n--- TEST 1: Retrieve Existing Questions for Fresh Exam ---\n";
    
    // Simulate query in ai_question_generator.php
    $stmtExQ = $conn->prepare("SELECT question FROM questions WHERE exam_id = ?");
    $stmtExQ->bind_param("i", $testExamId);
    $stmtExQ->execute();
    $existing = [];
    $res = $stmtExQ->get_result();
    while ($r = $res->fetch_assoc()) {
        $existing[] = normalize_question_text($r['question']);
    }
    $stmtExQ->close();

    $stmtExAI = $conn->prepare("SELECT question FROM ai_generated_questions WHERE exam_id = ? AND status = 'pending'");
    $stmtExAI->bind_param("i", $testExamId);
    $stmtExAI->execute();
    $resAI = $stmtExAI->get_result();
    while ($r = $resAI->fetch_assoc()) {
        $existing[] = normalize_question_text($r['question']);
    }
    $stmtExAI->close();

    assert_test(count($existing) === 0, "Fresh exam has 0 existing deduplication records");

    // -------------------------------------------------------------
    // TEST 2: Generate 10 Java questions from AI service
    // -------------------------------------------------------------
    echo "\n--- TEST 2: Call AI Service for 10 Java Questions ---\n";
    $aiClient = new AiClient();
    $aiRes = $aiClient->generateQuestions('Java', 'Loops', 'easy', 10, 'Test context', 'mcq');
    assert_test(!empty($aiRes['success']), "AI service successfully returned questions");
    $questions = $aiRes['data']['questions'] ?? [];
    assert_test(count($questions) === 10, "Received exactly 10 Java questions");

    // Stage into ai_generated_questions as pending
    $reqId1 = "req_life_" . uniqid();
    $stmtIns = $conn->prepare("INSERT INTO ai_generated_questions (
        request_id, admin_id, exam_id, subject, topic, difficulty, question_type,
        question, option_a, option_b, option_c, option_d, correct_option, explanation, status
    ) VALUES (?, 1, ?, 'Java', 'Loops', 'easy', 'mcq', ?, ?, ?, ?, ?, ?, 'Test', 'pending')");

    $stagedIds = [];
    foreach ($questions as $q) {
        $qClean = clean_question_title($q['question']);
        $optA = $q['options']['A'] ?? 'A';
        $optB = $q['options']['B'] ?? 'B';
        $optC = $q['options']['C'] ?? 'C';
        $optD = $q['options']['D'] ?? 'D';
        $corr = $q['correct_answer'] ?? 'A';
        $stmtIns->bind_param("sissssss", $reqId1, $testExamId, $qClean, $optA, $optB, $optC, $optD, $corr);
        $stmtIns->execute();
        $stagedIds[] = (int)$conn->insert_id;
    }
    $stmtIns->close();

    assert_test(count($stagedIds) === 10, "Successfully staged 10 Java questions with status='pending'");

    // -------------------------------------------------------------
    // TEST 3: Duplicate detection while questions are pending review
    // -------------------------------------------------------------
    echo "\n--- TEST 3: Deduplication Detection on Pending Staged Questions ---\n";
    $existingWhilePending = [];
    $stmtExQ = $conn->prepare("SELECT question FROM questions WHERE exam_id = ?");
    $stmtExQ->bind_param("i", $testExamId);
    $stmtExQ->execute();
    $res = $stmtExQ->get_result();
    while ($r = $res->fetch_assoc()) {
        $existingWhilePending[] = normalize_question_text($r['question']);
    }
    $stmtExQ->close();

    $stmtExAI = $conn->prepare("SELECT question FROM ai_generated_questions WHERE exam_id = ? AND status = 'pending'");
    $stmtExAI->bind_param("i", $testExamId);
    $stmtExAI->execute();
    $resAI = $stmtExAI->get_result();
    while ($r = $resAI->fetch_assoc()) {
        $existingWhilePending[] = normalize_question_text($r['question']);
    }
    $stmtExAI->close();

    assert_test(count($existingWhilePending) === 10, "Pending questions are loaded into deduplication pool (10 items)");

    // Test that the identical 10 questions are recognized as duplicates
    $dupsFound = 0;
    foreach ($questions as $q) {
        $qClean = clean_question_title($q['question']);
        if (is_question_duplicate($qClean, $existingWhilePending)) {
            $dupsFound++;
        }
    }
    assert_test($dupsFound === 10, "All 10 identical questions are correctly blocked while pending in queue");

    // -------------------------------------------------------------
    // TEST 4: Approve questions into active questions table
    // -------------------------------------------------------------
    echo "\n--- TEST 4: Approve Questions into Active Bank (Normal Workflow) ---\n";
    $insPub = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option, subject, topic, difficulty, explanation, question_type) VALUES (?, ?, ?, ?, ?, ?, ?, 'Java', 'Loops', 'easy', 'Test', 'mcq')");
    $updStg = $conn->prepare("UPDATE ai_generated_questions SET status = 'approved', reviewed_by = 1, reviewed_at = NOW() WHERE id = ?");

    $publishedIds = [];
    foreach ($stagedIds as $sId) {
        $row = $conn->query("SELECT * FROM ai_generated_questions WHERE id = {$sId}")->fetch_assoc();
        $insPub->bind_param("issssss", $testExamId, $row['question'], $row['option_a'], $row['option_b'], $row['option_c'], $row['option_d'], $row['correct_option']);
        $insPub->execute();
        $publishedIds[] = (int)$conn->insert_id;

        $updStg->bind_param("i", $sId);
        $updStg->execute();
    }
    $insPub->close();
    $updStg->close();

    $activeCount = (int)$conn->query("SELECT COUNT(*) as c FROM questions WHERE exam_id = {$testExamId}")->fetch_assoc()['c'];
    $approvedAiCount = (int)$conn->query("SELECT COUNT(*) as c FROM ai_generated_questions WHERE exam_id = {$testExamId} AND status = 'approved'")->fetch_assoc()['c'];
    $pendingAiCount = (int)$conn->query("SELECT COUNT(*) as c FROM ai_generated_questions WHERE exam_id = {$testExamId} AND status = 'pending'")->fetch_assoc()['c'];

    assert_test($activeCount === 10, "questions table contains 10 published questions");
    assert_test($approvedAiCount === 10, "ai_generated_questions contains 10 approved audit records");
    assert_test($pendingAiCount === 0, "ai_generated_questions contains 0 pending records");

    // -------------------------------------------------------------
    // TEST 5: Duplicate detection while questions are active in exam
    // -------------------------------------------------------------
    echo "\n--- TEST 5: Deduplication Detection on Active Published Questions ---\n";
    $existingActive = [];
    $stmtExQ = $conn->prepare("SELECT question FROM questions WHERE exam_id = ?");
    $stmtExQ->bind_param("i", $testExamId);
    $stmtExQ->execute();
    $res = $stmtExQ->get_result();
    while ($r = $res->fetch_assoc()) {
        $existingActive[] = normalize_question_text($r['question']);
    }
    $stmtExQ->close();

    $stmtExAI = $conn->prepare("SELECT question FROM ai_generated_questions WHERE exam_id = ? AND status = 'pending'");
    $stmtExAI->bind_param("i", $testExamId);
    $stmtExAI->execute();
    $resAI = $stmtExAI->get_result();
    while ($r = $resAI->fetch_assoc()) {
        $existingActive[] = normalize_question_text($r['question']);
    }
    $stmtExAI->close();

    assert_test(count($existingActive) === 10, "Active questions from questions table are loaded into deduplication pool (10 items)");
    $activeDups = 0;
    foreach ($questions as $q) {
        $qClean = clean_question_title($q['question']);
        if (is_question_duplicate($qClean, $existingActive)) {
            $activeDups++;
        }
    }
    assert_test($activeDups === 10, "Existing questions in active bank correctly block duplicate generation");

    // -------------------------------------------------------------
    // TEST 6: Delete questions from active exam (Normal Deletion Workflow)
    // -------------------------------------------------------------
    echo "\n--- TEST 6: Delete Questions via Normal Workflow (questions.php DELETE) ---\n";
    // This simulates the admin clicking Delete on questions in questions.php:
    // DELETE FROM questions WHERE id = ?
    $delStmt = $conn->prepare("DELETE FROM questions WHERE id = ?");
    foreach ($publishedIds as $pId) {
        $delStmt->bind_param("i", $pId);
        $delStmt->execute();
    }
    $delStmt->close();

    $activeCountAfterDel = (int)$conn->query("SELECT COUNT(*) as c FROM questions WHERE exam_id = {$testExamId}")->fetch_assoc()['c'];
    assert_test($activeCountAfterDel === 0, "questions table has 0 questions after normal deletion");

    // Note: ai_generated_questions still contains the historical approved records:
    $aiAuditCount = (int)$conn->query("SELECT COUNT(*) as c FROM ai_generated_questions WHERE exam_id = {$testExamId} AND status = 'approved'")->fetch_assoc()['c'];
    assert_test($aiAuditCount === 10, "ai_generated_questions still preserves historical audit records (10 approved)");

    // -------------------------------------------------------------
    // TEST 7: Re-generate questions after deletion - MUST SUCCEED (The Bug Fix!)
    // -------------------------------------------------------------
    echo "\n--- TEST 7: Re-generate Questions After Deletion (Verifying Bug Fix) ---\n";
    $existingAfterDel = [];
    $stmtExQ = $conn->prepare("SELECT question FROM questions WHERE exam_id = ?");
    $stmtExQ->bind_param("i", $testExamId);
    $stmtExQ->execute();
    $res = $stmtExQ->get_result();
    while ($r = $res->fetch_assoc()) {
        $existingAfterDel[] = normalize_question_text($r['question']);
    }
    $stmtExQ->close();

    // With the fix: only status = 'pending' is retrieved from ai_generated_questions
    $stmtExAI = $conn->prepare("SELECT question FROM ai_generated_questions WHERE exam_id = ? AND status = 'pending'");
    $stmtExAI->bind_param("i", $testExamId);
    $stmtExAI->execute();
    $resAI = $stmtExAI->get_result();
    while ($r = $resAI->fetch_assoc()) {
        $existingAfterDel[] = normalize_question_text($r['question']);
    }
    $stmtExAI->close();

    assert_test(count($existingAfterDel) === 0, "Deduplication pool is 0 after questions deleted from exam (approved history does not block)");

    // Simulate generation loop from ai_question_generator.php
    $newStagedCount = 0;
    $newSkippedCount = 0;
    $runningPool = $existingAfterDel;

    $reqId2 = "req_reGen_" . uniqid();
    $stmtIns2 = $conn->prepare("INSERT INTO ai_generated_questions (
        request_id, admin_id, exam_id, subject, topic, difficulty, question_type,
        question, option_a, option_b, option_c, option_d, correct_option, explanation, status
    ) VALUES (?, 1, ?, 'Java', 'Loops', 'easy', 'mcq', ?, ?, ?, ?, ?, ?, 'Test', 'pending')");

    $newStagedIds = [];
    foreach ($questions as $q) {
        $qClean = clean_question_title($q['question']);
        if (is_question_duplicate($qClean, $runningPool)) {
            $newSkippedCount++;
            continue;
        }
        $optA = $q['options']['A'] ?? 'A';
        $optB = $q['options']['B'] ?? 'B';
        $optC = $q['options']['C'] ?? 'C';
        $optD = $q['options']['D'] ?? 'D';
        $corr = $q['correct_answer'] ?? 'A';
        $stmtIns2->bind_param("sissssss", $reqId2, $testExamId, $qClean, $optA, $optB, $optC, $optD, $corr);
        $stmtIns2->execute();
        $newStagedIds[] = (int)$conn->insert_id;
        $newStagedCount++;
        $runningPool[] = normalize_question_text($qClean);
    }
    $stmtIns2->close();

    assert_test($newStagedCount === 10, "Successfully re-generated and staged all 10 Java questions (was previously blocked!)");
    assert_test($newSkippedCount === 0, "0 questions skipped as false duplicates");

    // -------------------------------------------------------------
    // TEST 8: Rejected questions do not falsely block generation
    // -------------------------------------------------------------
    echo "\n--- TEST 8: Rejected Records Do Not Block Generation ---\n";
    // Mark one of the newly staged questions as rejected
    $rejId = $newStagedIds[0];
    $conn->query("UPDATE ai_generated_questions SET status = 'rejected', rejection_reason = 'Too easy' WHERE id = {$rejId}");
    
    // Check pool
    $stmtExAI = $conn->prepare("SELECT question FROM ai_generated_questions WHERE exam_id = ? AND status = 'pending'");
    $stmtExAI->bind_param("i", $testExamId);
    $stmtExAI->execute();
    $pendingPool = [];
    $resAI = $stmtExAI->get_result();
    while ($r = $resAI->fetch_assoc()) {
        $pendingPool[] = normalize_question_text($r['question']);
    }
    $stmtExAI->close();

    $rejQuestionText = $conn->query("SELECT question FROM ai_generated_questions WHERE id = {$rejId}")->fetch_assoc()['question'];
    assert_test(!in_array(normalize_question_text($rejQuestionText), $pendingPool, true), "Rejected question is excluded from pending deduplication pool");

} finally {
    // Clean up all temporary test records
    $conn->query("DELETE FROM questions WHERE exam_id = {$testExamId}");
    $conn->query("DELETE FROM ai_generated_questions WHERE exam_id = {$testExamId}");
    $conn->query("DELETE FROM ai_generation_requests WHERE exam_id = {$testExamId}");
    $conn->query("DELETE FROM exams WHERE id = {$testExamId}");
}

echo "\n====================================================================\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n";

exit($failed > 0 ? 1 : 0);
