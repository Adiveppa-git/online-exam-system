<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

echo "====================================================\n";
echo "    Phase D: AI Question Generator Test Suite       \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

// Test 1: FastAPI Question Generation Endpoint (via AiClient)
echo "[Test 1] Testing AiClient::generateQuestions API Request... ";
$aiClient = new AiClient();
$resp = $aiClient->generateQuestions("Computer Science", "Data Structures", "medium", 3, "Focus on stacks and queues");

if ($resp['success'] && !empty($resp['data']['questions'])) {
    $qList = $resp['data']['questions'];
    if (count($qList) === 3 && isset($qList[0]['options']['A']) && isset($qList[0]['correct_answer'])) {
        echo "PASSED (Generated 3 structured MCQs)\n";
        $passed++;
    } else {
        echo "FAILED (Unexpected JSON structure)\n";
        $failed++;
    }
} else {
    echo "NOTE: AI Service port 8001 offline or error: " . ($resp['error'] ?? 'unknown') . "\n";
    $failed++;
}

// Test 2: Database Migration Check (ai_generated_questions table)
echo "[Test 2] Testing Database Migration Table (ai_generated_questions)... ";
$checkTable = $conn->query("SHOW TABLES LIKE 'ai_generated_questions'");
if ($checkTable && $checkTable->num_rows > 0) {
    echo "PASSED (ai_generated_questions table exists)\n";
    $passed++;
} else {
    echo "FAILED (Table ai_generated_questions not found)\n";
    $failed++;
}

// Test 3: Staging AI Question Insertion & Status Lifecycle
echo "[Test 3] Testing AI Question Staging & Approval Workflow... ";
$testReqId = "test_req_" . uniqid();
$adminId = 1;

// Insert test pending question
$stmtIns = $conn->prepare("INSERT INTO ai_generated_questions (request_id, admin_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, explanation, status) VALUES (?, ?, 'Database', 'SQL', 'easy', 'Which SQL keyword retrieves data?', 'INSERT', 'SELECT', 'UPDATE', 'DELETE', 'B', 'SELECT is used to query data.', 'pending')");
$stmtIns->bind_param("si", $testReqId, $adminId);
$stmtIns->execute();
$genQId = $conn->insert_id;

// Fetch inserted pending question
$chkPending = $conn->query("SELECT * FROM ai_generated_questions WHERE id = $genQId AND status = 'pending'");
if ($chkPending && $chkPending->num_rows === 1) {
    // Approve question and assign to test exam (Exam ID 24)
    $gq = $chkPending->fetch_assoc();
    $targetExamId = 24;

    $insActive = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option, subject, topic, difficulty, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insActive->bind_param("issssssssss", $targetExamId, $gq['question'], $gq['option_a'], $gq['option_b'], $gq['option_c'], $gq['option_d'], $gq['correct_option'], $gq['subject'], $gq['topic'], $gq['difficulty'], $gq['explanation']);
    $insActive->execute();
    $activeQId = $conn->insert_id;

    // Update status to approved
    $conn->query("UPDATE ai_generated_questions SET status = 'approved', reviewed_by = $adminId, reviewed_at = NOW() WHERE id = $genQId");

    // Verify active question created
    $chkActive = $conn->query("SELECT * FROM questions WHERE id = $activeQId AND exam_id = $targetExamId");
    if ($chkActive && $chkActive->num_rows === 1) {
        echo "PASSED (Question approved and published to active exam question bank!)\n";
        $passed++;

        // Clean up test items
        $conn->query("DELETE FROM questions WHERE id = $activeQId");
        $conn->query("DELETE FROM ai_generated_questions WHERE id = $genQId");
    } else {
        echo "FAILED (Active question insertion failed)\n";
        $failed++;
    }
} else {
    echo "FAILED (Staging insertion failed)\n";
    $failed++;
}

// Test 4: Existing Exams & Results Integrity Check
echo "[Test 4] Verifying Existing Database Data Integrity... ";
$resExams = $conn->query("SELECT COUNT(*) AS c FROM exams");
$resUsers = $conn->query("SELECT COUNT(*) AS c FROM users");
if ($resExams && $resUsers) {
    echo "PASSED (Exams: " . $resExams->fetch_assoc()['c'] . ", Users: " . $resUsers->fetch_assoc()['c'] . ")\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

exit($failed > 0 ? 1 : 0);
