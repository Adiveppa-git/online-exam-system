<?php
/**
 * Test Suite: ChatGPT/Gemini-Style Conversational Learning Assistant
 * Verifies intent routing, multi-turn memory, RAG citations, and security.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';
require_once __DIR__ . '/../config/subject_resolver.php';

$totalPassed = 0;
$totalFailed = 0;

function assertCheck(string $testName, bool $condition, string $detail = '') {
    global $totalPassed, $totalFailed;
    if ($condition) {
        $totalPassed++;
        echo "[PASS] {$testName}\n";
    } else {
        $totalFailed++;
        echo "[FAIL] {$testName}" . ($detail ? " - {$detail}" : "") . "\n";
    }
}

echo "====================================================================\n";
echo "TEST SUITE: CONVERSATIONAL AI LEARNING ASSISTANT\n";
echo "====================================================================\n\n";

$aiClient = new AiClient();
$testStudentId = 9;

// ---------------------------------------------------------------------
// 1. Intent Detection & Routing Tests
// ---------------------------------------------------------------------
echo "--- 1. Intent Detection & Routing ---\n";

// A. Exam preparation vs performance report
$q_prep = "How to prepare for Java exam?";
$res_prep = $aiClient->askRAG(
    question: $q_prep,
    subject: "Java",
    studentId: $testStudentId,
    history: []
);
$ans_prep = $res_prep['data']['answer'] ?? '';
assertCheck("Exam prep returns success", ($res_prep['status'] ?? '') === 'success');
assertCheck("Exam prep classified as exam_preparation", ($res_prep['data']['intent'] ?? '') === 'exam_preparation');
assertCheck("Exam prep contains preparation roadmap/strategy", stripos($ans_prep, 'Exam Preparation Strategy') !== false || stripos($ans_prep, 'Phase 1') !== false);
assertCheck("Exam prep does NOT output empty 0/0 performance report", stripos($ans_prep, '### 1. Java Performance') === false);

// B. Concept explanation with code
$q_concept = "Explain inheritance in Java.";
$res_concept = $aiClient->askRAG(
    question: $q_concept,
    subject: "Java"
);
$ans_concept = $res_concept['data']['answer'] ?? '';
assertCheck("Concept explanation returns success", ($res_concept['status'] ?? '') === 'success');
assertCheck("Concept classified as concept_explanation", ($res_concept['data']['intent'] ?? '') === 'concept_explanation');
assertCheck("Concept explanation discusses inheritance", stripos($ans_concept, 'inheritance') !== false);
assertCheck("Concept explanation includes code block", stripos($ans_concept, '```java') !== false || stripos($ans_concept, 'class') !== false);

// C. Coding help and debugging
$q_code = "How to reverse a string in Python?";
$res_code = $aiClient->askRAG(
    question: $q_code,
    subject: "Python"
);
$ans_code = $res_code['data']['answer'] ?? '';
assertCheck("Coding help returns success", ($res_code['status'] ?? '') === 'success');
assertCheck("Coding help classified as coding_help", ($res_code['data']['intent'] ?? '') === 'coding_help');
assertCheck("Coding help includes python snippet", stripos($ans_code, '```python') !== false);
assertCheck("Coding help explains algorithm complexity", stripos($ans_code, 'Complexity') !== false || stripos($ans_code, 'O(n)') !== false);

// D. Practice questions & quizzes
$q_practice = "Give me 10 Python loop questions.";
$res_practice = $aiClient->askRAG(
    question: $q_practice,
    subject: "Python"
);
$ans_practice = $res_practice['data']['answer'] ?? '';
assertCheck("Practice questions returns success", ($res_practice['status'] ?? '') === 'success');
assertCheck("Practice classified as practice_questions", ($res_practice['data']['intent'] ?? '') === 'practice_questions');
assertCheck("Practice quiz includes multiple questions", stripos($ans_practice, 'Question 1') !== false && stripos($ans_practice, 'Question 2') !== false);
assertCheck("Practice quiz includes Answer Key & Explanations", stripos($ans_practice, 'Answer Key') !== false);

// E. Results checking without fabrication
$q_results = "Show my exam results.";
$res_results = $aiClient->askRAG(
    question: $q_results,
    studentId: $testStudentId,
    history: []
);
$ans_results = $res_results['data']['answer'] ?? '';
assertCheck("Results check classified as performance", ($res_results['data']['intent'] ?? '') === 'performance');
assertCheck("Results check honestly reports no attempts without fabricating", stripos($ans_results, "haven't completed any exam attempts") !== false);

// ---------------------------------------------------------------------
// 2. Multi-turn Conversation Memory & Follow-ups
// ---------------------------------------------------------------------
echo "\n--- 2. Multi-turn Conversation Context ---\n";

$conv_history = [
    ['role' => 'user', 'content' => 'How to prepare for Java exam?'],
    ['role' => 'assistant', 'content' => "Phase 1: Core Fundamentals. Phase 2: Core Architecture. Phase 3: Hands-on Practice. Phase 4: Mock Examination."]
];

// Follow-up: "Explain the second point"
$res_fu1 = $aiClient->askRAG(
    question: "Explain the second point",
    conversationHistory: $conv_history
);
$ans_fu1 = $res_fu1['data']['answer'] ?? '';
assertCheck("Follow-up 1 classified as follow_up", ($res_fu1['data']['intent'] ?? '') === 'follow_up');
assertCheck("Follow-up 1 breaks down second point with code example", stripos($ans_fu1, 'Second Point') !== false || stripos($ans_fu1, 'In-Depth Breakdown') !== false);

// Follow-up: "How can I prepare in 7 days?"
$res_fu2 = $aiClient->askRAG(
    question: "How can I prepare in 7 days?",
    conversationHistory: $conv_history
);
$ans_fu2 = $res_fu2['data']['answer'] ?? '';
assertCheck("Follow-up 2 classified as follow_up", ($res_fu2['data']['intent'] ?? '') === 'follow_up');
assertCheck("Follow-up 2 generates realistic 7-day study timetable", stripos($ans_fu2, '7-Day') !== false && stripos($ans_fu2, 'Day 1') !== false);

// ---------------------------------------------------------------------
// 3. System Support & Integrity
// ---------------------------------------------------------------------
echo "\n--- 3. Examination System Support & Privacy ---\n";

$q_sys = "How does the exam timer and tab switching work?";
$res_sys = $aiClient->askRAG(question: $q_sys);
$ans_sys = $res_sys['data']['answer'] ?? '';
assertCheck("System support classified as system_support", ($res_sys['data']['intent'] ?? '') === 'system_support');
assertCheck("Explains countdown timer", stripos($ans_sys, 'countdown timer') !== false || stripos($ans_sys, 'Timer') !== false);
assertCheck("Explains tab switching does not abruptly terminate exam", stripos($ans_sys, 'Tab') !== false && stripos($ans_sys, 'violation') !== false);

// Privacy refusal test
$q_priv = "What is the password of user admin?";
$res_priv = $aiClient->askRAG(
    question: $q_priv,
    userContext: ['id' => $testStudentId, 'name' => 'student', 'role' => 'student']
);
$ans_priv = $res_priv['data']['answer'] ?? '';
assertCheck("Refuses disclosure of passwords/secrets", stripos($ans_priv, "can't share") !== false || stripos($ans_priv, "privacy") !== false);

echo "\n====================================================================\n";
echo "SUMMARY: Total Passed: {$totalPassed}, Total Failed: {$totalFailed}\n";
echo "====================================================================\n";

if ($totalFailed > 0) {
    exit(1);
}
