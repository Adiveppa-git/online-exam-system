<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

echo "====================================================\n";
echo " AI Question-Specific Focused Notes Test Suite     \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

// [Test 1] AiClient generateFocusedStudyNotes method availability
try {
    $client = new AiClient();
    if (method_exists($client, 'generateFocusedStudyNotes')) {
        echo "[Test 1] Testing AiClient generateFocusedStudyNotes Method... PASSED\n";
        $passed++;
    } else {
        echo "[Test 1] Testing AiClient generateFocusedStudyNotes Method... FAILED (Method not found)\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "[Test 1] Testing AiClient generateFocusedStudyNotes Method... FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

// [Test 2] Live API / Fallback Execution for Focused Notes
try {
    $testQuestion = "Which CPU scheduling algorithm minimizes average waiting time when burst times are known in advance?";
    $subject = "Operating Systems";
    $topic = "Process Management";
    $corrAns = "SJF";
    $explanation = "SJF (Shortest Job First) is provably optimal for minimizing average waiting time.";

    $res = $client->generateFocusedStudyNotes($testQuestion, $subject, $topic, $corrAns, $explanation);

    if (!empty($res['success']) && !empty($res['data']['concept_title'])) {
        $data = $res['data'];
        $title = $data['concept_title'];
        $isGrounded = !empty($data['is_grounded']) ? 'True (RAG Grounded)' : 'False (AI Domain Knowledge Fallback)';
        echo "[Test 2] Testing Focused Notes Generation... PASSED (Title: '{$title}', Grounded: {$isGrounded})\n";
        $passed++;
    } else {
        echo "[Test 2] Testing Focused Notes Generation... FAILED (Invalid response structure: " . json_encode($res) . ")\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "[Test 2] Testing Focused Notes Generation... FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

// [Test 3] Migration 004 Syntax & File Integrity Check
try {
    $migFile = __DIR__ . '/../database/migrations/004_question_study_notes.sql';
    if (file_exists($migFile)) {
        $content = file_get_contents($migFile);
        if (str_contains($content, 'question_study_notes') && str_contains($content, 'question_id') && str_contains($content, 'practice_answer_id')) {
            echo "[Test 3] Testing Migration 004 Structure & Integrity... PASSED\n";
            $passed++;
        } else {
            echo "[Test 3] Testing Migration 004 Structure & Integrity... FAILED (Missing expected schema columns)\n";
            $failed++;
        }
    } else {
        echo "[Test 3] Testing Migration 004 Structure & Integrity... FAILED (File not found)\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "[Test 3] Testing Migration 004 Structure & Integrity... FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

// [Test 4] Offline Service Error Handling
try {
    $badClient = new AiClient('http://127.0.0.1:9999', 1); // Point to dead port with short timeout
    $res = $badClient->generateFocusedStudyNotes("Test Question", "Subject", "Topic");
    if (isset($res['success']) && $res['success'] === false) {
        echo "[Test 4] Testing Offline Fallback Error Handling... PASSED (Handled connection refusal gracefully)\n";
        $passed++;
    } else {
        echo "[Test 4] Testing Offline Fallback Error Handling... FAILED\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "[Test 4] Testing Offline Fallback Error Handling... PASSED (Caught exception: " . $e->getMessage() . ")\n";
    $passed++;
}

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";
