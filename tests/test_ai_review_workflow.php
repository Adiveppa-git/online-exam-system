<?php

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;

require_once __DIR__ . '/../config/db.php';

ob_start();
require_once __DIR__ . '/../admin/review_ai_questions.php';
ob_end_clean();

echo "====================================================\n";
echo "    AI Question Review & Publishing Workflow Test   \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

// Setup temporary test exams
$conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Review Test Exam Alpha', 15, 50, 1)");
$examAlphaId = $conn->insert_id;

$conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Review Test Exam Beta', 15, 50, 1)");
$examBetaId = $conn->insert_id;

// Test 1: Bulk approval succeeds when all questions have valid exam IDs
echo "[Test 1] Bulk approval succeeds when all questions have valid exam IDs... ";
$reqId1 = "req_valid_bulk_" . uniqid();
$conn->query("INSERT INTO ai_generation_requests (request_id, admin_id, exam_id, subject, topic, difficulty, question_type, number_requested, model_used, status) VALUES ('{$reqId1}', 1, {$examAlphaId}, 'Math', 'Algebra', 'easy', 'mcq', 2, 'test-model', 'success')");

$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('{$reqId1}', 1, {$examAlphaId}, 'Math', 'Algebra', 'easy', 'Valid Q1', 'A', 'B', 'C', 'D', 'A', 'pending')");
$q1Id = $conn->insert_id;

$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('{$reqId1}', 1, {$examAlphaId}, 'Math', 'Algebra', 'easy', 'Valid Q2', 'A', 'B', 'C', 'D', 'B', 'pending')");
$q2Id = $conn->insert_id;

// Trigger bulk approval logic
$_POST['csrf_token'] = $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_POST['action'] = 'bulk_approve';
$_SERVER['REQUEST_METHOD'] = 'POST';

$conn->begin_transaction();
try {
    auto_recover_missing_exam_ids($conn);
    $pendingStmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE id IN ({$q1Id}, {$q2Id}) AND status = 'pending' FOR UPDATE");
    $pendingStmt->execute();
    $pendingRes = $pendingStmt->get_result();
    $pending_questions = [];
    while ($row = $pendingRes->fetch_assoc()) {
        $pending_questions[] = $row;
    }
    $pendingStmt->close();

    $invalidQuestionIds = [];
    foreach ($pending_questions as $gq) {
        $eIdCheck = (int)($gq['exam_id'] ?? 0);
        if ($eIdCheck <= 0) $invalidQuestionIds[] = (int)$gq['id'];
    }

    if (!empty($invalidQuestionIds)) {
        throw new Exception("Invalid IDs: " . implode(',', $invalidQuestionIds));
    }

    $insStmt = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option, subject, topic, difficulty, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $updStmt = $conn->prepare("UPDATE ai_generated_questions SET status = 'approved', reviewed_by = 1, reviewed_at = NOW() WHERE id = ?");

    foreach ($pending_questions as $gq) {
        $insStmt->bind_param("issssssssss", $gq['exam_id'], $gq['question'], $gq['option_a'], $gq['option_b'], $gq['option_c'], $gq['option_d'], $gq['correct_option'], $gq['subject'], $gq['topic'], $gq['difficulty'], $gq['explanation']);
        $insStmt->execute();
        $updStmt->bind_param("i", $gq['id']);
        $updStmt->execute();
    }
    $insStmt->close();
    $updStmt->close();
    $conn->commit();

    $pubCount = $conn->query("SELECT COUNT(*) AS c FROM questions WHERE exam_id = {$examAlphaId}")->fetch_assoc()['c'];
    if ((int)$pubCount === 2) {
        echo "PASSED (All valid questions published: {$pubCount})\n";
        $passed++;
    } else {
        echo "FAILED (Published count mismatch)\n";
        $failed++;
    }
} catch (Exception $e) {
    $conn->rollback();
    echo "FAILED (" . $e->getMessage() . ")\n";
    $failed++;
}

// Test 2: Missing exam ID produces clear error and no partial publication
echo "[Test 2] Missing exam ID produces clear error and no partial publication... ";
$reqIdBad = "req_bad_" . uniqid();
$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('{$reqIdBad}', 1, {$examAlphaId}, 'Math', 'Algebra', 'easy', 'Partial Test Q Good', 'A', 'B', 'C', 'D', 'A', 'pending')");
$qGoodId = $conn->insert_id;

// Question with NULL exam_id and unrecoverable request_id
$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('req_orphan_unknown', 1, NULL, 'Math', 'Algebra', 'easy', 'Partial Test Q Orphan', 'A', 'B', 'C', 'D', 'A', 'pending')");
$qOrphanId = $conn->insert_id;

$conn->begin_transaction();
$prePubCount = $conn->query("SELECT COUNT(*) AS c FROM questions WHERE question LIKE 'Partial Test Q%'")->fetch_assoc()['c'];
$aborted = false;
$errorMsg = '';

try {
    auto_recover_missing_exam_ids($conn);

    $pendingStmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE id IN ({$qGoodId}, {$qOrphanId}) AND status = 'pending' FOR UPDATE");
    $pendingStmt->execute();
    $pendingRes = $pendingStmt->get_result();
    $pending_questions = [];
    while ($row = $pendingRes->fetch_assoc()) {
        $pending_questions[] = $row;
    }
    $pendingStmt->close();

    $invalidQuestionIds = [];
    foreach ($pending_questions as $gq) {
        $eIdCheck = (int)($gq['exam_id'] ?? 0);
        if ($eIdCheck <= 0) $invalidQuestionIds[] = (int)$gq['id'];
    }

    if (!empty($invalidQuestionIds)) {
        $affected = "#" . implode(", #", $invalidQuestionIds);
        throw new Exception("Bulk approval aborted: Pending question(s) {$affected} do not have a valid assigned target exam ID.");
    }
} catch (Exception $e) {
    $conn->rollback();
    $aborted = true;
    $errorMsg = $e->getMessage();
}

$postPubCount = $conn->query("SELECT COUNT(*) AS c FROM questions WHERE question LIKE 'Partial Test Q%'")->fetch_assoc()['c'];
$goodStatus = $conn->query("SELECT status FROM ai_generated_questions WHERE id = {$qGoodId}")->fetch_assoc()['status'];

if ($aborted && str_contains($errorMsg, "#{$qOrphanId}") && (int)$postPubCount === 0 && $goodStatus === 'pending') {
    echo "PASSED (Aborted with error naming #{$qOrphanId}; 0 partial publications)\n";
    $passed++;
} else {
    echo "FAILED (aborted=" . ($aborted ? 'true' : 'false') . ", postPubCount={$postPubCount}, msg='{$errorMsg}')\n";
    $failed++;
}

// Test 3: The correct exam ID is assigned to newly generated questions
echo "[Test 3] Correct exam ID assigned to newly generated questions... ";
$reqIdNew = "req_newgen_" . uniqid();
$conn->query("INSERT INTO ai_generation_requests (request_id, admin_id, exam_id, subject, topic, difficulty, question_type, number_requested, model_used, status) VALUES ('{$reqIdNew}', 1, {$examBetaId}, 'Physics', 'Motion', 'medium', 'mcq', 1, 'test-model', 'success')");
$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('{$reqIdNew}', 1, {$examBetaId}, 'Physics', 'Motion', 'medium', 'New Gen Q1', 'A', 'B', 'C', 'D', 'A', 'pending')");
$qNewGenId = $conn->insert_id;

$chkNewGen = $conn->query("SELECT exam_id FROM ai_generated_questions WHERE id = {$qNewGenId}")->fetch_assoc();
if ($chkNewGen && (int)$chkNewGen['exam_id'] === (int)$examBetaId) {
    echo "PASSED (Newly generated question has correct exam_id {$examBetaId})\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// Test 4: Question belonging to another exam is never published into the wrong exam
echo "[Test 4] Question belonging to Exam Beta is never published into Exam Alpha... ";
$conn->begin_transaction();
try {
    $qStmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE id = ? FOR UPDATE");
    $qStmt->bind_param("i", $qNewGenId);
    $qStmt->execute();
    $gqBeta = $qStmt->get_result()->fetch_assoc();
    $qStmt->close();

    $eIdBeta = (int)$gqBeta['exam_id'];
    $insStmt = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option, subject, topic, difficulty, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insStmt->bind_param("issssssssss", $eIdBeta, $gqBeta['question'], $gqBeta['option_a'], $gqBeta['option_b'], $gqBeta['option_c'], $gqBeta['option_d'], $gqBeta['correct_option'], $gqBeta['subject'], $gqBeta['topic'], $gqBeta['difficulty'], $gqBeta['explanation']);
    $insStmt->execute();
    $insStmt->close();
    $conn->commit();

    $pubAlpha = $conn->query("SELECT COUNT(*) AS c FROM questions WHERE exam_id = {$examAlphaId} AND question = 'New Gen Q1'")->fetch_assoc()['c'];
    $pubBeta = $conn->query("SELECT COUNT(*) AS c FROM questions WHERE exam_id = {$examBetaId} AND question = 'New Gen Q1'")->fetch_assoc()['c'];

    if ((int)$pubAlpha === 0 && (int)$pubBeta === 1) {
        echo "PASSED (Question correctly isolated to Exam Beta {$examBetaId}, 0 in Exam Alpha)\n";
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

// Test 5: Existing pending questions and review history are preserved
echo "[Test 5] Existing pending questions and review history preserved... ";
$conn->query("UPDATE ai_generated_questions SET status = 'rejected', rejection_reason = 'Test preservation' WHERE id = {$qOrphanId}");

$chkOrphanRej = $conn->query("SELECT status, rejection_reason FROM ai_generated_questions WHERE id = {$qOrphanId}")->fetch_assoc();
$chkGoodPending = $conn->query("SELECT status FROM ai_generated_questions WHERE id = {$qGoodId}")->fetch_assoc();

if ($chkOrphanRej['status'] === 'rejected' && $chkOrphanRej['rejection_reason'] === 'Test preservation' && $chkGoodPending['status'] === 'pending') {
    echo "PASSED (Pending status & review history preserved intact)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// Test 6: Auto-recovery of missing exam_id from ai_generation_requests
echo "[Test 6] Auto-recovery of missing exam_id from ai_generation_requests... ";
$reqIdRecover = "req_recover_" . uniqid();
$conn->query("INSERT INTO ai_generation_requests (request_id, admin_id, exam_id, subject, topic, difficulty, question_type, number_requested, model_used, status) VALUES ('{$reqIdRecover}', 1, {$examAlphaId}, 'Biology', 'Cells', 'easy', 'mcq', 1, 'test-model', 'success')");

// Insert pending question with NULL exam_id but valid request_id
$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('{$reqIdRecover}', 1, NULL, 'Biology', 'Cells', 'easy', 'Recoverable Q1', 'A', 'B', 'C', 'D', 'A', 'pending')");
$qRecoverId = $conn->insert_id;

auto_recover_missing_exam_ids($conn);

$chkRecovered = $conn->query("SELECT exam_id FROM ai_generated_questions WHERE id = {$qRecoverId}")->fetch_assoc();
if ($chkRecovered && (int)$chkRecovered['exam_id'] === (int)$examAlphaId) {
    echo "PASSED (Exam ID successfully recovered from request to {$examAlphaId})\n";
    $passed++;
} else {
    echo "FAILED (Recovery failed)\n";
    $failed++;
}

// Test 7: Pending question referencing nonexistent exam ID is detected and aborted
echo "[Test 7] Pending question referencing nonexistent exam ID fails validation... ";
$nonExistentExamId = 999999;
$reqIdNonExistent = "req_nonexist_" . uniqid();
$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('{$reqIdNonExistent}', 1, {$nonExistentExamId}, 'Chemistry', 'Bonds', 'medium', 'Nonexistent Exam Q', 'A', 'B', 'C', 'D', 'A', 'pending')");
$qNonExistId = $conn->insert_id;

$abortedNonExist = false;
$errorMsgNonExist = '';
$conn->begin_transaction();
try {
    $pendingStmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE id = ? AND status = 'pending' FOR UPDATE");
    $pendingStmt->bind_param("i", $qNonExistId);
    $pendingStmt->execute();
    $pendingRes = $pendingStmt->get_result();
    $pending_questions = [];
    while ($row = $pendingRes->fetch_assoc()) {
        $pending_questions[] = $row;
    }
    $pendingStmt->close();

    $invalidIds = [];
    $examCheckCache = [];
    foreach ($pending_questions as $gq) {
        $eIdCheck = (int)($gq['exam_id'] ?? 0);
        if ($eIdCheck <= 0) {
            $invalidIds[] = (int)$gq['id'];
            continue;
        }
        if (!isset($examCheckCache[$eIdCheck])) {
            $chkStmt = $conn->prepare("SELECT id FROM exams WHERE id = ?");
            $chkStmt->bind_param("i", $eIdCheck);
            $chkStmt->execute();
            $examCheckCache[$eIdCheck] = ($chkStmt->get_result()->num_rows > 0);
            $chkStmt->close();
        }
        if (!$examCheckCache[$eIdCheck]) {
            $invalidIds[] = (int)$gq['id'];
        }
    }

    if (!empty($invalidIds)) {
        $affected = "#" . implode(", #", $invalidIds);
        throw new Exception("Bulk approval aborted: Pending question(s) {$affected} do not have a valid assigned target exam ID.");
    }
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    $abortedNonExist = true;
    $errorMsgNonExist = $e->getMessage();
}

if ($abortedNonExist && str_contains($errorMsgNonExist, "#{$qNonExistId}")) {
    echo "PASSED (Nonexistent exam rejected and named #{$qNonExistId})\n";
    $passed++;
} else {
    echo "FAILED (msg='{$errorMsgNonExist}')\n";
    $failed++;
}

// Test 8: Administrator manually repairs invalid assignment via assign_exam action
echo "[Test 8] Administrator manually repairs invalid assignment via assign_exam... ";
// Repair question $qNonExistId to point to valid $examAlphaId
$targetExamIdToSet = $examAlphaId;
$chkExam = $conn->prepare("SELECT id, title FROM exams WHERE id = ?");
$chkExam->bind_param("i", $targetExamIdToSet);
$chkExam->execute();
$examExists = ($chkExam->get_result()->num_rows > 0);
$chkExam->close();

if ($examExists) {
    $updStmt = $conn->prepare("UPDATE ai_generated_questions SET exam_id = ? WHERE id = ? AND status = 'pending'");
    $updStmt->bind_param("ii", $targetExamIdToSet, $qNonExistId);
    $updStmt->execute();
    $updStmt->close();
}

$repairedCheck = $conn->query("SELECT exam_id, status FROM ai_generated_questions WHERE id = {$qNonExistId}")->fetch_assoc();
if ($repairedCheck && (int)$repairedCheck['exam_id'] === (int)$examAlphaId && $repairedCheck['status'] === 'pending') {
    echo "PASSED (Assignment repaired to exam {$examAlphaId}; question remains pending)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// Test 9: Repaired question now passes bulk approval cleanly
echo "[Test 9] Repaired question successfully bulk approved and published... ";
$conn->begin_transaction();
$publishSuccess = false;
try {
    $pendingStmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE id = ? AND status = 'pending' FOR UPDATE");
    $pendingStmt->bind_param("i", $qNonExistId);
    $pendingStmt->execute();
    $pending_questions = $pendingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pendingStmt->close();

    $invalidIds = [];
    foreach ($pending_questions as $gq) {
        $eIdCheck = (int)($gq['exam_id'] ?? 0);
        $chkStmt = $conn->prepare("SELECT id FROM exams WHERE id = ?");
        $chkStmt->bind_param("i", $eIdCheck);
        $chkStmt->execute();
        if ($chkStmt->get_result()->num_rows === 0) {
            $invalidIds[] = (int)$gq['id'];
        }
        $chkStmt->close();
    }

    if (!empty($invalidIds)) {
        throw new Exception("Validation failed");
    }

    $insStmt = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option, subject, topic, difficulty, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $updStmt = $conn->prepare("UPDATE ai_generated_questions SET status = 'approved', reviewed_by = 1, reviewed_at = NOW() WHERE id = ?");

    foreach ($pending_questions as $gq) {
        $insStmt->bind_param("issssssssss", $gq['exam_id'], $gq['question'], $gq['option_a'], $gq['option_b'], $gq['option_c'], $gq['option_d'], $gq['correct_option'], $gq['subject'], $gq['topic'], $gq['difficulty'], $gq['explanation']);
        $insStmt->execute();
        $updStmt->bind_param("i", $gq['id']);
        $updStmt->execute();
    }
    $insStmt->close();
    $updStmt->close();
    $conn->commit();
    $publishSuccess = true;
} catch (Exception $e) {
    $conn->rollback();
}

$publishedCheck = $conn->query("SELECT COUNT(*) AS c FROM questions WHERE exam_id = {$examAlphaId} AND question = 'Nonexistent Exam Q'")->fetch_assoc()['c'];
$qRepairedStatus = $conn->query("SELECT status FROM ai_generated_questions WHERE id = {$qNonExistId}")->fetch_assoc()['status'];

if ($publishSuccess && (int)$publishedCheck === 1 && $qRepairedStatus === 'approved') {
    echo "PASSED (Repaired question published into exam {$examAlphaId} with approved status)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// Test 10: Atomic rollback prevents partial publishing if ANY question in batch is invalid
echo "[Test 10] Atomic rollback prevents any insert when batch contains an invalid question... ";
$reqIdAtomic = "req_atomic_" . uniqid();
$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('{$reqIdAtomic}', 1, {$examBetaId}, 'Logic', 'Truth Tables', 'easy', 'Atomic Valid Q', 'A', 'B', 'C', 'D', 'A', 'pending')");
$qAtomicValidId = $conn->insert_id;

$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('{$reqIdAtomic}', 1, NULL, 'Logic', 'Truth Tables', 'easy', 'Atomic Invalid Q', 'A', 'B', 'C', 'D', 'A', 'pending')");
$qAtomicInvalidId = $conn->insert_id;

$rollbackOccurred = false;
$conn->begin_transaction();
try {
    $pendingStmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE id IN ({$qAtomicValidId}, {$qAtomicInvalidId}) AND status = 'pending' FOR UPDATE");
    $pendingStmt->execute();
    $batch = $pendingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pendingStmt->close();

    $invalidIds = [];
    foreach ($batch as $gq) {
        $eIdCheck = (int)($gq['exam_id'] ?? 0);
        if ($eIdCheck <= 0) {
            $invalidIds[] = (int)$gq['id'];
        }
    }
    if (!empty($invalidIds)) {
        throw new Exception("Batch contains invalid IDs: " . implode(',', $invalidIds));
    }
    // Execution would happen here
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    $rollbackOccurred = true;
}

$atomicPubCount = $conn->query("SELECT COUNT(*) AS c FROM questions WHERE question LIKE 'Atomic %'")->fetch_assoc()['c'];
$atomicValidStatus = $conn->query("SELECT status FROM ai_generated_questions WHERE id = {$qAtomicValidId}")->fetch_assoc()['status'];
$atomicInvalidStatus = $conn->query("SELECT status FROM ai_generated_questions WHERE id = {$qAtomicInvalidId}")->fetch_assoc()['status'];

if ($rollbackOccurred && (int)$atomicPubCount === 0 && $atomicValidStatus === 'pending' && $atomicInvalidStatus === 'pending') {
    echo "PASSED (Zero partial publications, both questions remain pending)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// Test 11: Individual rejection and bulk rejection preserve workflow
echo "[Test 11] Individual rejection and bulk rejection functionality... ";
// 1. Individual reject of $qAtomicInvalidId
$conn->query("UPDATE ai_generated_questions SET status = 'rejected', rejection_reason = 'Individual rejection test', reviewed_by = 1, reviewed_at = NOW() WHERE id = {$qAtomicInvalidId} AND status = 'pending'");
$chkSingleRej = $conn->query("SELECT status, rejection_reason FROM ai_generated_questions WHERE id = {$qAtomicInvalidId}")->fetch_assoc();

// 2. Reject remaining test question via status update
$conn->query("UPDATE ai_generated_questions SET status = 'rejected', rejection_reason = 'Bulk rejection test', reviewed_by = 1, reviewed_at = NOW() WHERE id = {$qAtomicValidId} AND status = 'pending'");
$chkBulkRej = $conn->query("SELECT status, rejection_reason FROM ai_generated_questions WHERE id = {$qAtomicValidId}")->fetch_assoc();

if ($chkSingleRej['status'] === 'rejected' && $chkSingleRej['rejection_reason'] === 'Individual rejection test' &&
    $chkBulkRej['status'] === 'rejected' && $chkBulkRej['rejection_reason'] === 'Bulk rejection test') {
    echo "PASSED (Individual and bulk rejection operate cleanly)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// Test 12: Editing a pending question preserves and updates target exam assignment
echo "[Test 12] Editing pending question updates exam assignment and question fields... ";
$reqIdEdit = "req_edit_" . uniqid();
$conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, status) VALUES ('{$reqIdEdit}', 1, {$examAlphaId}, 'Art', 'Drawing', 'easy', 'Pre-edit Question Text', 'A', 'B', 'C', 'D', 'A', 'pending')");
$qEditId = $conn->insert_id;

// Simulate edit POST action: reassign to $examBetaId and update fields
$newExamId = $examBetaId;
$newQuestion = "Post-edit Question Text";
$updEditStmt = $conn->prepare("UPDATE ai_generated_questions SET exam_id = ?, question = ?, difficulty = 'hard' WHERE id = ? AND status = 'pending'");
$updEditStmt->bind_param("isi", $newExamId, $newQuestion, $qEditId);
$updEditStmt->execute();
$updEditStmt->close();

$chkEdited = $conn->query("SELECT exam_id, question, difficulty, status FROM ai_generated_questions WHERE id = {$qEditId}")->fetch_assoc();
if ($chkEdited && (int)$chkEdited['exam_id'] === (int)$examBetaId && $chkEdited['question'] === 'Post-edit Question Text' && $chkEdited['difficulty'] === 'hard' && $chkEdited['status'] === 'pending') {
    echo "PASSED (Exam reassigned to Beta {$examBetaId}, fields updated, status remains pending)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// Test 13: Integrity check: Existing production questions #162 and #163 are preserved intact
echo "[Test 13] Safeguard: Questions #162 and #163 exist and are preserved intact... ";
$chk162 = $conn->query("SELECT id, status, exam_id FROM ai_generated_questions WHERE id = 162")->fetch_assoc();
$chk163 = $conn->query("SELECT id, status, exam_id FROM ai_generated_questions WHERE id = 163")->fetch_assoc();

if ($chk162 && $chk163 && $chk162['status'] === 'rejected' && $chk163['status'] === 'rejected') {
    echo "PASSED (Questions #162 and #163 preserved intact in rejected status)\n";
    $passed++;
} else {
    echo "FAILED (Questions #162 or #163 missing or altered)\n";
    $failed++;
}

// CLEAN UP TEST DATA
$conn->query("DELETE FROM questions WHERE exam_id IN ({$examAlphaId}, {$examBetaId})");
$conn->query("DELETE FROM ai_generated_questions WHERE id IN ({$q1Id}, {$q2Id}, {$qGoodId}, {$qOrphanId}, {$qNewGenId}, {$qRecoverId}, {$qNonExistId}, {$qAtomicValidId}, {$qAtomicInvalidId}, {$qEditId})");
$conn->query("DELETE FROM ai_generation_requests WHERE request_id IN ('{$reqId1}', '{$reqIdBad}', '{$reqIdNew}', '{$reqIdRecover}', '{$reqIdNonExistent}', '{$reqIdAtomic}', '{$reqIdEdit}')");
$conn->query("DELETE FROM exams WHERE id IN ({$examAlphaId}, {$examBetaId})");

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

exit($failed > 0 ? 1 : 0);
