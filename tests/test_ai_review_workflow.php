<?php

require_once __DIR__ . '/../config/db.php';

echo "====================================================\n";
echo "    AI Question Review & Publishing Workflow Test   \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

// Setup temporary test exam
$conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question, ai_generated) VALUES ('Review Test Exam 999', 15, 50, 1, 0)");
$testExamId = $conn->insert_id ?: $conn->query("SELECT id FROM exams WHERE title = 'Review Test Exam 999'")->fetch_assoc()['id'];

// Test 1: Pending question has exam_id
echo "[Test 1] Pending question created with exam_id... ";
$reqId = "req_test_" . uniqid();
$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('{$reqId}', 1, {$testExamId}, 'Math', 'Algebra', 'easy', 'Solve 2x=4', '1', '2', '3', '4', 'B', 'pending')");
$gqId1 = $conn->insert_id ?: $conn->query("SELECT id FROM ai_generated_questions WHERE request_id = '{$reqId}' AND question = 'Solve 2x=4'")->fetch_assoc()['id'];

$chkQ1 = $conn->query("SELECT * FROM ai_generated_questions WHERE id = {$gqId1}")->fetch_assoc();
if ($chkQ1 && (int)$chkQ1['exam_id'] === (int)$testExamId && $chkQ1['status'] === 'pending') {
    echo "PASSED (exam_id = {$testExamId}, status = pending)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// Test 2: Approve uses existing exam_id and updates difficulty
echo "[Test 2] Single approval uses existing exam_id & updates difficulty... ";
$newDifficulty = 'hard';

// Simulate approval logic
$conn->begin_transaction();
try {
    $qStmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE id = ? AND status = 'pending' FOR UPDATE");
    $qStmt->bind_param("i", $gqId1);
    $qStmt->execute();
    $gq = $qStmt->get_result()->fetch_assoc();

    if (!$gq) throw new Exception("Question not found");

    $eId = (int)$gq['exam_id'];
    $insStmt = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option, subject, topic, difficulty, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insStmt->bind_param("issssssssss",
        $eId, $gq['question'], $gq['option_a'], $gq['option_b'], $gq['option_c'], $gq['option_d'],
        $gq['correct_option'], $gq['subject'], $gq['topic'], $newDifficulty, $gq['explanation']
    );
    $insStmt->execute();
    $pubQId = $insStmt->insert_id ?: $conn->query("SELECT id FROM questions WHERE exam_id = {$eId} AND question = 'Solve 2x=4'")->fetch_assoc()['id'];

    $updStmt = $conn->prepare("UPDATE ai_generated_questions SET status = 'approved', difficulty = ?, reviewed_by = 1, reviewed_at = NOW() WHERE id = ?");
    $updStmt->bind_param("si", $newDifficulty, $gqId1);
    $updStmt->execute();

    $conn->commit();

    // Verify published question
    $pubQ = $conn->query("SELECT * FROM questions WHERE id = {$pubQId}")->fetch_assoc();
    $gqApp = $conn->query("SELECT * FROM ai_generated_questions WHERE id = {$gqId1}")->fetch_assoc();

    if ($pubQ && (int)$pubQ['exam_id'] === (int)$testExamId && $pubQ['difficulty'] === 'hard' && $gqApp['status'] === 'approved' && $gqApp['difficulty'] === 'hard') {
        echo "PASSED (Published with correct exam_id {$testExamId} and updated difficulty 'hard')\n";
        $passed++;
    } else {
        echo "FAILED\n";
        $failed++;
    }
} catch (Exception $e) {
    $conn->rollback();
    echo "FAILED (" . $e->getMessage() . ")\n";
    $failed++;
}

// Test 3: Bulk approval preserves individual question exam_id
echo "[Test 3] Bulk approval uses existing exam_id for each pending question... ";
$reqIdBulk = "req_bulk_" . uniqid();
$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('{$reqIdBulk}', 1, {$testExamId}, 'Science', 'Physics', 'medium', 'Bulk Test Q1', 'A', 'B', 'C', 'D', 'A', 'pending')");
$gqIdBulk = $conn->insert_id ?: $conn->query("SELECT id FROM ai_generated_questions WHERE request_id = '{$reqIdBulk}'")->fetch_assoc()['id'];

$conn->begin_transaction();
try {
    $pendingStmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE id = ? AND status = 'pending' FOR UPDATE");
    $pendingStmt->bind_param("i", $gqIdBulk);
    $pendingStmt->execute();
    $pendingRes = $pendingStmt->get_result();
    $pending_questions = [];
    while ($row = $pendingRes->fetch_assoc()) {
        $pending_questions[] = $row;
    }

    $insStmt = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option, subject, topic, difficulty, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $updStmt = $conn->prepare("UPDATE ai_generated_questions SET status = 'approved', reviewed_by = 1, reviewed_at = NOW() WHERE id = ?");

    foreach ($pending_questions as $gq) {
        $eId = (int)$gq['exam_id'];
        $insStmt->bind_param("issssssssss",
            $eId, $gq['question'], $gq['option_a'], $gq['option_b'], $gq['option_c'], $gq['option_d'],
            $gq['correct_option'], $gq['subject'], $gq['topic'], $gq['difficulty'], $gq['explanation']
        );
        $insStmt->execute();
        $updStmt->bind_param("i", $gq['id']);
        $updStmt->execute();
    }
    $conn->commit();

    $pubBulk = $conn->query("SELECT * FROM questions WHERE question = 'Bulk Test Q1'")->fetch_assoc();
    if ($pubBulk && (int)$pubBulk['exam_id'] === (int)$testExamId) {
        echo "PASSED (Bulk approved to correct exam_id {$testExamId})\n";
        $passed++;
    } else {
        echo "FAILED\n";
        $failed++;
    }
} catch (Exception $e) {
    $conn->rollback();
    echo "FAILED (" . $e->getMessage() . ")\n";
    $failed++;
}

// Test 4: Rejecting a question sets status to rejected and does NOT publish
echo "[Test 4] Rejection sets status = rejected and does NOT add to active questions... ";
$reqIdRej = "req_rej_" . uniqid();
$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('{$reqIdRej}', 1, {$testExamId}, 'History', 'World War', 'medium', 'Reject Test Q1', 'A', 'B', 'C', 'D', 'A', 'pending')");
$gqIdRej = $conn->insert_id ?: $conn->query("SELECT id FROM ai_generated_questions WHERE request_id = '{$reqIdRej}'")->fetch_assoc()['id'];

$updRej = $conn->prepare("UPDATE ai_generated_questions SET status = 'rejected', rejection_reason = 'Low quality', reviewed_by = 1, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
$updRej->bind_param("i", $gqIdRej);
$updRej->execute();

$chkRejQ = $conn->query("SELECT * FROM ai_generated_questions WHERE id = {$gqIdRej}")->fetch_assoc();
$chkActiveQ = $conn->query("SELECT * FROM questions WHERE question = 'Reject Test Q1'")->fetch_assoc();

if ($chkRejQ && $chkRejQ['status'] === 'rejected' && $chkActiveQ === null) {
    echo "PASSED (Status set to rejected; question not published to active bank)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// CLEAN UP TEST DATA
$conn->query("DELETE FROM questions WHERE exam_id = {$testExamId}");
$conn->query("DELETE FROM ai_generated_questions WHERE exam_id = {$testExamId}");
$conn->query("DELETE FROM exams WHERE id = {$testExamId}");

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

exit($failed > 0 ? 1 : 0);
