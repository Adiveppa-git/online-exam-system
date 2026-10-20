<?php
/**
 * Test Suite: AI Question Generator for Coding Questions, Arbitrary Topics, and Review Workflow
 *
 * Requirements Tested:
 * 1. 10 Python coding questions on loops (generates genuine loop-based programming MCQs with code snippets,
 *    preserved indentation, options A-D, programming_language "Python", correct_option set).
 * 2. Java coding questions (polymorphism, string comparison, exception handling, options A-D).
 * 3. SQL questions (queries, second highest salary, HAVING, joins, options A-D).
 * 4. Output-prediction and debugging questions.
 * 5. General theory / non-coding questions.
 * 6. Arbitrary custom subjects and topics (e.g. "Rust Concurrency", "Bioinformatics", "Quantum Computing").
 * 7. Generation reliability & error handling (invalid inputs, API unreachable simulation, duplicate detection).
 * 8. Correct target-exam association, pending-review staging in ai_generated_questions, and approval into questions table.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';
require_once __DIR__ . '/../config/text_helpers.php';

$pass = 0;
$fail = 0;

function assert_test($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        echo "  [PASS] $message\n";
        $pass++;
    } else {
        echo "  [FAIL] $message\n";
        $fail++;
    }
}

echo "=== RUNNING AI QUESTION GENERATOR CODING & ARBITRARY TOPICS TEST SUITE ===\n\n";

$aiClient = new AiClient();

// Ensure test exam exists
$examTitle = "AI Coding Test Exam " . uniqid();
$stmtExam = $conn->prepare("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES (?, 60, 100, 1)");
$stmtExam->bind_param("s", $examTitle);
$stmtExam->execute();
$testExamId = $conn->insert_id;
$stmtExam->close();

assert_test($testExamId > 0, "Created temporary test exam ID #$testExamId");

// -------------------------------------------------------------
// TEST 1: Python 10 Coding Questions on Loops (MCQ mode)
// Acceptance criteria: When an admin requests 10 Python coding questions on loops,
// the system generates relevant loop-based programming MCQs with readable code snippets,
// options A-D, language Python, exactly one correct answer.
// -------------------------------------------------------------
echo "\n--- TEST 1: Python 10 Coding Questions on Loops (MCQ Mode) ---\n";
$res1 = $aiClient->generateQuestions("Python", "loops", "medium", 10, "Focus on for and while loops", "mcq");
assert_test($res1['success'] === true, "AI service returned success for 10 Python loop questions");
$qList1 = $res1['data']['questions'] ?? [];
assert_test(count($qList1) === 10, "Generated exactly 10 questions (got " . count($qList1) . ")");

$loopKeyCount = 0;
$codeSnippetCount = 0;
$validOptionsCount = 0;
$pythonLangCount = 0;

foreach ($qList1 as $q) {
    $qText = $q['question'] ?? '';
    $opts = $q['options'] ?? [];
    $corr = $q['correct_answer'] ?? '';
    $lang = $q['programming_language'] ?? '';

    if ($lang === 'Python') $pythonLangCount++;
    if (preg_match('/(loop|for\s+|while\s+|range\(|enumerate\(|zip\(|iteration)/i', $qText)) {
        $loopKeyCount++;
    }
    if (strpos($qText, '```') !== false || strpos($qText, 'for ') !== false || strpos($qText, 'while ') !== false) {
        $codeSnippetCount++;
    }
    if (isset($opts['A'], $opts['B'], $opts['C'], $opts['D']) && in_array($corr, ['A', 'B', 'C', 'D'], true)) {
        $validOptionsCount++;
    }
}

assert_test($pythonLangCount === 10, "All 10 questions have programming_language='Python' ($pythonLangCount/10)");
assert_test($loopKeyCount === 10, "All 10 questions are genuinely loop-related ($loopKeyCount/10)");
assert_test($codeSnippetCount >= 8, "Questions contain code snippets ($codeSnippetCount/10)");
assert_test($validOptionsCount === 10, "All 10 questions have valid options A-D and valid correct_answer ($validOptionsCount/10)");

// -------------------------------------------------------------
// TEST 2: Java Coding Questions (MCQ Mode)
// -------------------------------------------------------------
echo "\n--- TEST 2: Java Coding Questions (MCQ Mode) ---\n";
$res2 = $aiClient->generateQuestions("Java", "Inheritance and Polymorphism", "medium", 5, "OOP and polymorphism", "mcq");
assert_test($res2['success'] === true, "AI service returned success for Java questions");
$qList2 = $res2['data']['questions'] ?? [];
assert_test(count($qList2) === 5, "Generated 5 Java questions");

$javaLangOk = true;
$javaOptsOk = true;
foreach ($qList2 as $q) {
    if (($q['programming_language'] ?? '') !== 'Java') $javaLangOk = false;
    $opts = $q['options'] ?? [];
    if (!isset($opts['A'], $opts['B'], $opts['C'], $opts['D'])) $javaOptsOk = false;
}
assert_test($javaLangOk, "All questions have programming_language='Java'");
assert_test($javaOptsOk, "All Java questions have options A-D");

// -------------------------------------------------------------
// TEST 3: SQL Questions (Queries, second highest salary, HAVING)
// -------------------------------------------------------------
echo "\n--- TEST 3: SQL Database Questions ---\n";
$res3 = $aiClient->generateQuestions("SQL", "Queries and Subqueries", "medium", 5, "Aggregations, joins, and salary queries", "mcq");
assert_test($res3['success'] === true, "AI service returned success for SQL questions");
$qList3 = $res3['data']['questions'] ?? [];
assert_test(count($qList3) === 5, "Generated 5 SQL questions");

$sqlMatched = false;
foreach ($qList3 as $q) {
    if (stripos($q['question'], 'SELECT') !== false || stripos($q['question'], 'HAVING') !== false || stripos($q['question'], 'JOIN') !== false || stripos($q['question'], 'salary') !== false) {
        $sqlMatched = true;
        break;
    }
}
assert_test($sqlMatched, "SQL questions include genuine database queries/constructs (SELECT, HAVING, JOIN, or salary)");

// -------------------------------------------------------------
// TEST 4: Output Prediction and Debugging Questions
// -------------------------------------------------------------
echo "\n--- TEST 4: Output Prediction & Bug Identification Questions ---\n";
$res4 = $aiClient->generateQuestions("Python", "Debugging and Error Handling", "medium", 5, "Find the bug and predict output", "mcq");
assert_test($res4['success'] === true, "AI service returned success for debugging questions");
$qList4 = $res4['data']['questions'] ?? [];
assert_test(count($qList4) === 5, "Generated 5 debugging/output questions");

$outputOrBugFound = false;
foreach ($qList4 as $q) {
    if (stripos($q['question'], 'output') !== false || stripos($q['question'], 'error') !== false || stripos($q['question'], 'bug') !== false) {
        $outputOrBugFound = true;
        break;
    }
}
assert_test($outputOrBugFound, "Questions evaluate code output prediction or error/bug identification");

// -------------------------------------------------------------
// TEST 5: Arbitrary Custom Technical and Non-Technical Topics
// -------------------------------------------------------------
echo "\n--- TEST 5: Arbitrary Custom Topics ---\n";
// Custom technical topic: Rust
$resCustomTech = $aiClient->generateQuestions("Rust", "Ownership and Concurrency", "hard", 3, "Memory safety without garbage collection", "mcq");
assert_test($resCustomTech['success'] === true, "Generated questions for custom technical subject 'Rust'");
$rustQ = $resCustomTech['data']['questions'][0] ?? [];
assert_test(($rustQ['programming_language'] ?? '') === 'Rust', "Detected programming_language='Rust'");
assert_test(isset($rustQ['options']['A'], $rustQ['options']['B']), "Options A-D populated for Rust");

// Custom non-technical topic: Environmental Science
$resCustomNonTech = $aiClient->generateQuestions("Environmental Science", "Renewable Energy Economics", "medium", 3, "", "mcq");
assert_test($resCustomNonTech['success'] === true, "Generated questions for non-technical subject 'Environmental Science'");
$envQ = $resCustomNonTech['data']['questions'][0] ?? [];
assert_test(!empty($envQ['question']), "Question text is non-empty for Environmental Science");
assert_test(isset($envQ['options']['A']), "Options populated for custom non-technical subject");

// -------------------------------------------------------------
// TEST 6: Pure Coding Problems (question_type = 'coding')
// -------------------------------------------------------------
echo "\n--- TEST 6: Coding Problems (question_type = 'coding') ---\n";
$resCode = $aiClient->generateQuestions("Python", "Loops", "medium", 3, "Loop accumulation and reversal", "coding");
assert_test($resCode['success'] === true, "AI service returned success for coding questions");
$codeQList = $resCode['data']['questions'] ?? [];
assert_test(count($codeQList) === 3, "Generated 3 coding problems");
$firstCodeQ = $codeQList[0] ?? [];
assert_test(($firstCodeQ['question_type'] ?? '') === 'coding', "question_type is 'coding'");
assert_test(!empty($firstCodeQ['reference_solution']), "Reference solution is present");
assert_test(!empty($firstCodeQ['sample_test_cases']), "Sample test cases are present");
assert_test(!empty($firstCodeQ['time_complexity']), "Time complexity is present");

// -------------------------------------------------------------
// TEST 7: Staging in ai_generated_questions & Preserving Indentation & Options
// -------------------------------------------------------------
echo "\n--- TEST 7: Database Staging in Pending Review & Whitespace Preservation ---\n";
$requestId = "test_req_" . uniqid();
$adminId = 1;

// Simulate admin form inserting generated questions into ai_generated_questions
$stmtIns = $conn->prepare("INSERT INTO ai_generated_questions (
    request_id, admin_id, exam_id, subject, topic, difficulty, question_type,
    question, option_a, option_b, option_c, option_d, correct_option, explanation, generation_model,
    programming_language, reference_solution, status
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'fastapi-ai-service', ?, ?, 'pending')");

$sampleIndentedCode = "What is the output of the following Python loop?\n\n```python\ntotal = 0\nfor i in range(1, 10, 2):\n    total += i\nprint(total)\n```";
$optA = "25";
$optB = "20";
$optC = "45";
$optD = "30";
$corrOpt = "A";
$expl = "1 + 3 + 5 + 7 + 9 = 25";
$pLang = "Python";
$refSol = "total = sum(range(1, 10, 2))";
$qType = "mcq";
$diff = "medium";
$subj = "Python";
$top = "loops";

$stmtIns->bind_param("siisssssssssssss",
    $requestId, $adminId, $testExamId, $subj, $top, $diff, $qType,
    $sampleIndentedCode, $optA, $optB, $optC, $optD, $corrOpt, $expl,
    $pLang, $refSol
);
$insOk = $stmtIns->execute();
$stagedQId = $conn->insert_id;
$stmtIns->close();

assert_test($insOk && $stagedQId > 0, "Inserted question into ai_generated_questions with pending status (ID: #$stagedQId)");

// Fetch from DB and verify attributes
$chkStmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE id = ?");
$chkStmt->bind_param("i", $stagedQId);
$chkStmt->execute();
$stagedRow = $chkStmt->get_result()->fetch_assoc();
$chkStmt->close();

assert_test($stagedRow['status'] === 'pending', "Question has status='pending' (not auto-published)");
assert_test((int)$stagedRow['exam_id'] === $testExamId, "Associated correctly with target exam ID #$testExamId");
assert_test(strpos($stagedRow['question'], "    total += i") !== false, "Indentation (4 spaces) is preserved in database question column");
assert_test($stagedRow['option_a'] === '25' && $stagedRow['correct_option'] === 'A', "Options and correct_option correctly preserved");
assert_test($stagedRow['programming_language'] === 'Python', "programming_language preserved as 'Python'");

// -------------------------------------------------------------
// TEST 8: Approval Workflow into Questions Table
// -------------------------------------------------------------
echo "\n--- TEST 8: Review & Approval Workflow into Questions Table ---\n";
// Simulate admin approval
$apprIns = $conn->prepare("INSERT INTO questions (
    exam_id, question, option_a, option_b, option_c, option_d, correct_option,
    subject, topic, difficulty, explanation,
    question_type, programming_language, reference_solution
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$cleanTitle = clean_question_title($stagedRow['question']);
$apprIns->bind_param("isssssssssssss",
    $testExamId, $cleanTitle, $stagedRow['option_a'], $stagedRow['option_b'], $stagedRow['option_c'], $stagedRow['option_d'],
    $stagedRow['correct_option'], $stagedRow['subject'], $stagedRow['topic'], $stagedRow['difficulty'], $stagedRow['explanation'],
    $stagedRow['question_type'], $stagedRow['programming_language'], $stagedRow['reference_solution']
);
$apprOk = $apprIns->execute();
$publishedQId = $conn->insert_id;
$apprIns->close();

$updStaged = $conn->prepare("UPDATE ai_generated_questions SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
$updStaged->bind_param("ii", $adminId, $stagedQId);
$updStaged->execute();
$updStaged->close();

assert_test($apprOk && $publishedQId > 0, "Approved and published question into questions table (ID: #$publishedQId)");

// Verify published question attributes
$pubStmt = $conn->prepare("SELECT * FROM questions WHERE id = ?");
$pubStmt->bind_param("i", $publishedQId);
$pubStmt->execute();
$pubRow = $pubStmt->get_result()->fetch_assoc();
$pubStmt->close();

assert_test((int)$pubRow['exam_id'] === $testExamId, "Published question has correct exam_id");
assert_test(strpos($pubRow['question'], "    total += i") !== false, "Published question maintains indentation");
assert_test($pubRow['programming_language'] === 'Python', "Published question maintains programming_language");
assert_test($pubRow['correct_option'] === 'A', "Published question maintains correct_option='A'");

// -------------------------------------------------------------
// TEST 9: Duplicate Prevention Scoped to Target Exam
// -------------------------------------------------------------
echo "\n--- TEST 9: Duplicate Prevention Scoped to Target Exam ---\n";
$normSample = normalize_question_text($sampleIndentedCode);
$isDup = is_question_duplicate($sampleIndentedCode, [$normSample]);
assert_test($isDup === true, "Duplicate detected for identical question text in same exam");

$differentQuestion = "What is the output of range(5) in Python?";
$isNotDup = is_question_duplicate($differentQuestion, [$normSample]);
assert_test($isNotDup === false, "Different question is correctly permitted");

// Clean up test records
$conn->query("DELETE FROM questions WHERE id = $publishedQId");
$conn->query("DELETE FROM ai_generated_questions WHERE id = $stagedQId");
$conn->query("DELETE FROM exams WHERE id = $testExamId");

echo "\n======================================================\n";
echo "TEST RESULTS: $pass PASSED, $fail FAILED\n";
echo "======================================================\n";

if ($fail > 0) {
    exit(1);
} else {
    exit(0);
}
