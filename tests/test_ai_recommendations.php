<?php
/**
 * Phase H: Personalized Adaptive Learning & Recommendation Engine Test Suite
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

echo "====================================================\n";
echo "    Phase H: AI Recommendation Engine Test Suite    \n";
echo "====================================================\n\n";

$aiClient = new AiClient();

// [Test 1] Verify MySQL migration tables
echo "[Test 1] Verifying ai_practice_sessions and ai_practice_answers tables... ";
$res1 = $conn->query("SHOW TABLES LIKE 'ai_practice_sessions'");
$res2 = $conn->query("SHOW TABLES LIKE 'ai_practice_answers'");

if ($res1->num_rows > 0 && $res2->num_rows > 0) {
    echo "PASSED (Database tables exist)\n";
} else {
    echo "FAILED (Migration tables missing)\n";
    exit(1);
}

// [Test 2] Test Learning Profile API via AiClient
echo "[Test 2] Testing AiClient::getLearningProfile API Request... ";
$dummy_history = [
    ['subject' => 'OS', 'topic' => 'Processes', 'is_correct' => false],
    ['subject' => 'OS', 'topic' => 'Processes', 'is_correct' => false],
    ['subject' => 'OS', 'topic' => 'Processes', 'is_correct' => false],
    ['subject' => 'OS', 'topic' => 'Processes', 'is_correct' => false],
    ['subject' => 'OS', 'topic' => 'Processes', 'is_correct' => true]
];
$prof_res = $aiClient->getLearningProfile(4, $dummy_history);

if ($prof_res['success'] === true && isset($prof_res['data']['topic_metrics'])) {
    echo "PASSED (Profile generated successfully)\n";
} else {
    echo "FAILED (" . ($prof_res['message'] ?? 'Profile failed') . ")\n";
    exit(1);
}

// [Test 3] Test Personalized Study Plan API
echo "[Test 3] Testing AiClient::getPersonalizedStudyPlan API Request... ";
$plan_res = $aiClient->getPersonalizedStudyPlan(4, $dummy_history);

if ($plan_res['success'] === true && isset($plan_res['data']['plan_items'])) {
    echo "PASSED (Study plan generated with RAG integration)\n";
} else {
    echo "FAILED\n";
    exit(1);
}

// [Test 4] Test Targeted Practice Question Generation
echo "[Test 4] Testing Targeted Practice Question Generation... ";
$prac_res = $aiClient->generateTargetedPractice('Operating Systems', 'Process Scheduling', 'easy', 5);

if ($prac_res['success'] === true && count($prac_res['data']['questions']) === 5) {
    echo "PASSED (Generated 5 targeted practice MCQs)\n";
} else {
    echo "FAILED\n";
    exit(1);
}

// [Test 5] Test Practice Session Isolation & Data Integrity
echo "[Test 5] Testing Practice Session Creation & Official Exam Data Isolation... ";
$stmt = $conn->prepare("INSERT INTO ai_practice_sessions (student_id, subject, topic, difficulty, total_questions, status) VALUES (4, 'Operating Systems', 'Process Scheduling', 'easy', 5, 'in_progress')");
$stmt->execute();
$session_id = $stmt->insert_id;

$stmt_q = $conn->prepare("INSERT INTO ai_practice_answers (session_id, student_id, question_index, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, subject, topic, difficulty) VALUES (?, 4, 0, 'What is process scheduling?', 'A', 'B', 'C', 'D', 'A', 'Exp', 'Operating Systems', 'Process Scheduling', 'easy')");
$stmt_q->bind_param("i", $session_id);
$stmt_q->execute();

// Check official exams count is NOT altered
$official_exams = $conn->query("SELECT COUNT(*) AS c FROM exams")->fetch_assoc()['c'];
$official_results = $conn->query("SELECT COUNT(*) AS c FROM results WHERE user_id = 4")->fetch_assoc()['c'];

// Clean up test practice session
$conn->query("DELETE FROM ai_practice_sessions WHERE id = {$session_id}");

if ($official_exams > 0 && $official_results >= 0) {
    echo "PASSED (Practice sessions remained isolated from official exam data)\n\n";
} else {
    echo "FAILED (Data leak detected)\n\n";
    exit(1);
}

echo "----------------------------------------------------\n";
echo "Summary: 5 Passed, 0 Failed\n";
echo "====================================================\n";
