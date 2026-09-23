<?php
/**
 * Comprehensive E2E Verification Test for Dual DB Drivers (MySQL & PostgreSQL)
 * Tests full flow: Manual Question Add -> Manage Questions, AI Generation -> Staging -> Approve & Publish -> Manage Questions
 */

require_once __DIR__ . '/../config/db.php';

echo "======================================================================\n";
echo "   DUAL DATABASE DRIVER END-TO-END VERIFICATION (MANUAL & AI PUBLISH)  \n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function run_check(string $title, callable $fn) {
    global $passCount, $failCount;
    echo "Testing: {$title} ... ";
    try {
        $res = $fn();
        if ($res === true) {
            echo "[PASSED]\n";
            $passCount++;
        } else {
            echo "[FAILED]: " . (is_string($res) ? $res : "Assertion failed") . "\n";
            $failCount++;
        }
    } catch (Throwable $e) {
        echo "[EXCEPTIONAL FAIL]: " . $e->getMessage() . "\n";
        $failCount++;
    }
}

// 1. Verify DB Connection
run_check("1. Database Connection Active", function() use ($conn) {
    return ($conn !== null && empty($conn->connect_error));
});

// 2. Setup Test Exam
$testExamTitle = "E2E Test Exam " . rand(1000, 9999);
$testExamId = 0;

run_check("2. Insert Test Exam", function() use ($conn, $testExamTitle, &$testExamId) {
    $stmt = $conn->prepare("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES (?, ?, ?, ?)");
    $dur = 30; $tot = 100; $mpq = 2;
    $stmt->bind_param("siii", $testExamTitle, $dur, $tot, $mpq);
    if (!$stmt->execute()) {
        return "Failed to insert exam: " . ($stmt->error ?: $conn->error);
    }
    $testExamId = $stmt->insert_id ?: $conn->insert_id;
    if ($testExamId <= 0) {
        // Fallback fetch
        $res = $conn->query("SELECT id FROM exams WHERE title = '" . $conn->real_escape_string($testExamTitle) . "'");
        if ($res && $row = $res->fetch_assoc()) {
            $testExamId = (int)$row['id'];
        }
    }
    return $testExamId > 0;
});

// 3. Insert Manual Question
$manualQuestionText = "Manual E2E Test Question " . rand(1000, 9999) . ": What is 2 + 2?";
$manualQId = 0;

run_check("3. Add Manual Question to Active Bank", function() use ($conn, $testExamId, $manualQuestionText, &$manualQId) {
    $optA = "3"; $optB = "4"; $optC = "5"; $optD = "6"; $corr = "B";
    $stmt = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssss", $testExamId, $manualQuestionText, $optA, $optB, $optC, $optD, $corr);
    if (!$stmt->execute()) {
        return "Failed to insert manual question: " . ($stmt->error ?: $conn->error);
    }
    $manualQId = $stmt->insert_id ?: $conn->insert_id;
    return true;
});

// 4. Verify Manual Question in Manage Questions Query
run_check("4. Retrieve Manual Question via Manage Questions Query", function() use ($conn, $testExamId, $manualQuestionText) {
    $res = $conn->query("
        SELECT q.*, COALESCE(e.title, 'General Exam') AS exam_title
        FROM questions q
        LEFT JOIN exams e ON q.exam_id = e.id
        WHERE q.exam_id = {$testExamId}
        ORDER BY q.id ASC
    ");
    if (!$res || $res->num_rows === 0) {
        return "Query returned zero rows for exam ID {$testExamId}";
    }
    $found = false;
    while ($row = $res->fetch_assoc()) {
        if ($row['question'] === $manualQuestionText) {
            $found = true;
            break;
        }
    }
    return $found ? true : "Manual question text not found in query results";
});

// 5. Insert AI Question into Staging Table (status='pending')
$reqId = "req_e2e_" . rand(10000, 99999);
$aiQId = 0;
$aiQuestionText = "AI Generated E2E Question " . rand(1000, 9999) . ": What is the speed of light in vacuum?";

run_check("5. Stage AI Question into ai_generated_questions", function() use ($conn, $testExamId, $reqId, $aiQuestionText, &$aiQId) {
    $adminId = 1; $subj = "Physics"; $top = "Relativity"; $diff = "medium"; $qtype = "mcq";
    $optA = "3x10^8 m/s"; $optB = "1500 m/s"; $optC = "3000 km/s"; $optD = "Infinite"; $corr = "A";
    $exp = "Speed of light in vacuum is approx 3x10^8 m/s"; $model = "gpt-4o-mini";
    
    $stmt = $conn->prepare("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question_type, question, option_a, option_b, option_c, option_d, correct_option, explanation, generation_model, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->bind_param("siissssssssssss", $reqId, $adminId, $testExamId, $subj, $top, $diff, $qtype, $aiQuestionText, $optA, $optB, $optC, $optD, $corr, $exp, $model);
    if (!$stmt->execute()) {
        return "Failed to insert AI staging question: " . ($stmt->error ?: $conn->error);
    }
    $aiQId = $stmt->insert_id ?: $conn->insert_id;
    return true;
});

// 6. Execute Approve & Publish Workflow for Staged Question
run_check("6. Approve & Publish Staged AI Question into Active Bank", function() use ($conn, $aiQId, $testExamId) {
    $conn->begin_transaction();
    try {
        $qStmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE id = ? AND status = 'pending' FOR UPDATE");
        $qStmt->bind_param("i", $aiQId);
        $qStmt->execute();
        $gq = $qStmt->get_result()->fetch_assoc();
        if (!$gq) {
            throw new Exception("Staged AI question not found in pending status");
        }

        $eId = (int)$gq['exam_id'];
        $diffToUse = 'medium';
        $subjToUse = $gq['subject'] ?: 'General';
        $topicToUse = $gq['topic'] ?: 'General';
        $expToUse = $gq['explanation'] ?: '';

        $insStmt = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option, subject, topic, difficulty, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insStmt->bind_param("issssssssss",
            $eId, $gq['question'], $gq['option_a'], $gq['option_b'], $gq['option_c'], $gq['option_d'],
            $gq['correct_option'], $subjToUse, $topicToUse, $diffToUse, $expToUse
        );
        if (!$insStmt->execute()) {
            throw new Exception("Failed to insert question: " . ($insStmt->error ?: $conn->error));
        }

        $updStmt = $conn->prepare("UPDATE ai_generated_questions SET status = 'approved', difficulty = ?, reviewed_by = 1, reviewed_at = NOW() WHERE id = ?");
        $updStmt->bind_param("si", $diffToUse, $aiQId);
        $updStmt->execute();

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        return "Publish exception: " . $e->getMessage();
    }
});

// 7. Verify Approved AI Question in Manage Questions Query
run_check("7. Retrieve Published AI Question via Manage Questions Query", function() use ($conn, $testExamId, $aiQuestionText) {
    $res = $conn->query("
        SELECT q.*, COALESCE(e.title, 'General Exam') AS exam_title
        FROM questions q
        LEFT JOIN exams e ON q.exam_id = e.id
        WHERE q.exam_id = {$testExamId}
        ORDER BY q.id ASC
    ");
    if (!$res || $res->num_rows < 2) {
        return "Expected at least 2 questions for exam ID {$testExamId}, found: " . ($res ? $res->num_rows : 0);
    }
    $foundAI = false;
    while ($row = $res->fetch_assoc()) {
        if ($row['question'] === $aiQuestionText) {
            $foundAI = true;
            break;
        }
    }
    return $foundAI ? true : "Published AI question text not found in Manage Questions query results";
});

// Cleanup Test Data
$conn->query("DELETE FROM questions WHERE exam_id = {$testExamId}");
$conn->query("DELETE FROM ai_generated_questions WHERE exam_id = {$testExamId}");
$conn->query("DELETE FROM ai_generation_requests WHERE exam_id = {$testExamId}");
$conn->query("DELETE FROM exams WHERE id = {$testExamId}");

echo "\n======================================================================\n";
echo "SUMMARY: Passed: {$passCount} | Failed: {$failCount}\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
