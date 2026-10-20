<?php
/**
 * Automated Test Suite: Multiple Question Types & Coding Problem Generation
 * 
 * Verifies:
 * 1. MCQ question generation
 * 2. Coding problem generation (genuine problems, not MCQs)
 * 3. Specific problem requirements (Python, Java, C, SQL, DSA stack)
 * 4. Arbitrary languages and custom topics (JavaScript, Rust, Operating Systems)
 * 5. Short answer generation
 * 6. Descriptive / long answer generation
 * 7. Mixed questions count & distribution
 * 8. Staging into ai_generated_questions (pending queue)
 * 9. Review, editing, and publishing into active questions bank
 * 10. Invalid AI response safety handling
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

$aiClient = new AiClient();
$passed = 0;
$failed = 0;

function run_test($name, $closure) {
    global $passed, $failed;
    echo "Running: {$name} ... ";
    try {
        $result = $closure();
        if ($result === true || $result === null) {
            echo "[PASSED]\n";
            $passed++;
        } else {
            echo "[FAILED]: {$result}\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "[ERROR]: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "======================================================================\n";
echo "   AI GENERATOR MULTIPLE QUESTION TYPES & CODING TEST SUITE\n";
echo "======================================================================\n\n";

// 1. Verify AI Service Health
run_test("1. AI Service Health Check", function() use ($aiClient) {
    $health = $aiClient->checkHealth();
    if (empty($health['online'])) {
        return "AI Service is not online on port 8001: " . ($health['error'] ?? 'Unknown error');
    }
    return true;
});

// 2. MCQ Generation Works
run_test("2. MCQ Question Generation", function() use ($aiClient) {
    $res = $aiClient->generateQuestions("Computer Science", "Database Normalization", "medium", 2, "", "mcq");
    if (empty($res['success'])) {
        return "Generation failed: " . ($res['error'] ?? 'Unknown error');
    }
    $questions = $res['data']['questions'] ?? [];
    if (count($questions) !== 2) {
        return "Expected 2 questions, got " . count($questions);
    }
    foreach ($questions as $q) {
        if (($q['question_type'] ?? '') !== 'mcq') {
            return "Expected question_type mcq, got " . ($q['question_type'] ?? 'none');
        }
        if (empty($q['options']) || !isset($q['options']['A'], $q['options']['B'], $q['options']['C'], $q['options']['D'])) {
            return "MCQ missing options A-D";
        }
        if (!in_array($q['correct_answer'] ?? '', ['A', 'B', 'C', 'D'], true)) {
            return "MCQ correct_answer is not A/B/C/D";
        }
    }
    return true;
});

// 3. Coding Question Generation (Python - Second Largest)
run_test("3. Coding Question - Python (Second Largest Element)", function() use ($aiClient) {
    $res = $aiClient->generateQuestions("Python", "Find the second-largest distinct element in an array", "medium", 1, "", "coding");
    if (empty($res['success'])) {
        return "Generation failed: " . ($res['error'] ?? 'Unknown error');
    }
    $q = $res['data']['questions'][0] ?? [];
    if (($q['question_type'] ?? '') !== 'coding') {
        return "Expected question_type coding, got " . ($q['question_type'] ?? '');
    }
    if (stripos($q['programming_language'] ?? '', 'python') === false) {
        return "Expected programming_language Python, got " . ($q['programming_language'] ?? '');
    }
    if (stripos($q['question'], 'second') === false) {
        return "Problem statement missing second-largest context";
    }
    if (empty($q['input_format']) || empty($q['output_format'])) {
        return "Coding question missing input/output formats";
    }
    if (empty($q['sample_test_cases']) || !is_array($q['sample_test_cases'])) {
        return "Coding question missing sample_test_cases array";
    }
    if (empty($q['reference_solution'])) {
        return "Coding question missing reference_solution";
    }
    // Verify NOT forced into MCQ options
    if (!empty($q['options'])) {
        return "Coding question should not have MCQ options";
    }
    return true;
});

// 4. Coding Question Generation (Java - Palindrome)
run_test("4. Coding Question - Java (Palindrome String)", function() use ($aiClient) {
    $res = $aiClient->generateQuestions("Java", "Check whether a string is a palindrome", "easy", 1, "", "coding");
    if (empty($res['success'])) {
        return "Generation failed: " . ($res['error'] ?? '');
    }
    $q = $res['data']['questions'][0] ?? [];
    if (($q['question_type'] ?? '') !== 'coding') {
        return "Expected coding type";
    }
    if (stripos($q['programming_language'] ?? '', 'java') === false) {
        return "Expected Java, got " . ($q['programming_language'] ?? '');
    }
    if (stripos($q['question'], 'palindrome') === false) {
        return "Problem statement missing palindrome reference";
    }
    if (empty($q['reference_solution'])) {
        return "Missing reference solution";
    }
    return true;
});

// 5. Coding Question Generation (C - Binary Search)
run_test("5. Coding Question - C (Binary Search)", function() use ($aiClient) {
    $res = $aiClient->generateQuestions("C", "Implement binary search", "medium", 1, "", "coding");
    if (empty($res['success'])) {
        return "Generation failed: " . ($res['error'] ?? '');
    }
    $q = $res['data']['questions'][0] ?? [];
    if (($q['question_type'] ?? '') !== 'coding') {
        return "Expected coding type";
    }
    if (strcasecmp($q['programming_language'] ?? '', 'C') !== 0) {
        return "Expected C language, got " . ($q['programming_language'] ?? '');
    }
    if (stripos($q['question'], 'binary search') === false) {
        return "Missing binary search in problem statement";
    }
    return true;
});

// 6. Coding Question Generation (SQL - Second Highest Salary)
run_test("6. Coding Question - SQL (Second Highest Salary)", function() use ($aiClient) {
    $res = $aiClient->generateQuestions("SQL", "Write a query to find the second-highest salary", "medium", 1, "", "coding");
    if (empty($res['success'])) {
        return "Generation failed: " . ($res['error'] ?? '');
    }
    $q = $res['data']['questions'][0] ?? [];
    if (($q['question_type'] ?? '') !== 'coding') {
        return "Expected coding type";
    }
    if (stripos($q['programming_language'] ?? '', 'sql') === false) {
        return "Expected SQL language, got " . ($q['programming_language'] ?? '');
    }
    if (stripos($q['reference_solution'] ?? '', 'select') === false) {
        return "Expected SQL query in reference solution, got: " . ($q['reference_solution'] ?? '');
    }
    return true;
});

// 7. Coding Question Generation (Data Structures - Stack using an Array)
run_test("7. Coding Question - DSA (Implement Stack using Array)", function() use ($aiClient) {
    $res = $aiClient->generateQuestions("Data Structures", "Implement a stack using an array", "medium", 1, "", "coding");
    if (empty($res['success'])) {
        return "Generation failed: " . ($res['error'] ?? '');
    }
    $q = $res['data']['questions'][0] ?? [];
    if (($q['question_type'] ?? '') !== 'coding') {
        return "Expected coding type";
    }
    if (stripos($q['question'], 'stack') === false) {
        return "Missing stack context";
    }
    return true;
});

// 8. Arbitrary Custom Subject & Language (Rust)
run_test("8. Arbitrary Custom Language (Rust Memory Safety / Concurrency)", function() use ($aiClient) {
    $res = $aiClient->generateQuestions("Rust", "Thread-safe Counter using Arc and Mutex", "hard", 1, "", "coding");
    if (empty($res['success'])) {
        return "Generation failed: " . ($res['error'] ?? '');
    }
    $q = $res['data']['questions'][0] ?? [];
    if (($q['question_type'] ?? '') !== 'coding') {
        return "Expected coding type";
    }
    if (stripos($q['programming_language'] ?? '', 'rust') === false) {
        return "Expected Rust language, got " . ($q['programming_language'] ?? '');
    }
    return true;
});

// 9. Short Answer Question Generation
run_test("9. Short Answer Question Generation", function() use ($aiClient) {
    $res = $aiClient->generateQuestions("Operating Systems", "Virtual Memory and Paging", "medium", 2, "", "short_answer");
    if (empty($res['success'])) {
        return "Generation failed: " . ($res['error'] ?? '');
    }
    $questions = $res['data']['questions'] ?? [];
    if (count($questions) !== 2) {
        return "Expected 2 questions";
    }
    foreach ($questions as $q) {
        if (($q['question_type'] ?? '') !== 'short_answer') {
            return "Expected question_type short_answer, got " . ($q['question_type'] ?? '');
        }
        if (empty($q['correct_answer'])) {
            return "Short answer question missing expected model answer in correct_answer";
        }
    }
    return true;
});

// 10. Descriptive / Long Answer Generation
run_test("10. Descriptive / Long Answer Generation", function() use ($aiClient) {
    $res = $aiClient->generateQuestions("Software Engineering", "Microservices vs Monolith Architecture", "hard", 1, "", "descriptive");
    if (empty($res['success'])) {
        return "Generation failed: " . ($res['error'] ?? '');
    }
    $q = $res['data']['questions'][0] ?? [];
    if (($q['question_type'] ?? '') !== 'descriptive') {
        return "Expected question_type descriptive, got " . ($q['question_type'] ?? '');
    }
    if (empty($q['correct_answer'])) {
        return "Descriptive question missing expected scoring rubric / comprehensive answer";
    }
    return true;
});

// 11. Mixed Questions Count & Distribution
run_test("11. Mixed Questions Respects Total Count", function() use ($aiClient) {
    $res = $aiClient->generateQuestions("Computer Science", "Algorithms and Data Structures", "medium", 5, "", "mixed");
    if (empty($res['success'])) {
        return "Generation failed: " . ($res['error'] ?? '');
    }
    $questions = $res['data']['questions'] ?? [];
    if (count($questions) !== 5) {
        return "Expected 5 questions for mixed request, got " . count($questions);
    }
    $types = [];
    foreach ($questions as $q) {
        $t = $q['question_type'] ?? 'unknown';
        $types[$t] = ($types[$t] ?? 0) + 1;
    }
    // Check that multiple types exist
    if (count($types) < 2) {
        return "Mixed questions should contain at least 2 distinct types, got types: " . json_encode($types);
    }
    return true;
});

// 12. Invalid AI Response Handling
run_test("12. Invalid AI Request Rejection", function() use ($aiClient) {
    $res = $aiClient->generateQuestions("", "", "invalid_difficulty", -5, "", "invalid_type");
    if (!empty($res['success'])) {
        return "Expected validation failure for invalid request params";
    }
    if (empty($res['error']) && empty($res['message'])) {
        return "Expected error message on validation failure";
    }
    return true;
});

// 13. End-to-End Database Staging, Review & Approval for Coding Questions
run_test("13. DB Staging & Approval: Coding Question -> Active Bank", function() use ($conn) {
    // A. Create test exam
    $conn->query("INSERT INTO exams (title, duration, total_marks, created_at) VALUES ('Multitype Test Exam', 60, 100, NOW())");
    $testExamId = $conn->insert_id;

    $reqId = "req_multitype_" . uniqid();
    
    // B. Insert into ai_generated_questions as a pending coding question
    $stmt = $conn->prepare("INSERT INTO ai_generated_questions (
        request_id, admin_id, exam_id, subject, topic, difficulty, question_type,
        question, programming_language, input_format, output_format, constraints,
        sample_test_cases, hidden_test_cases, reference_solution, time_complexity, space_complexity,
        status, generation_model
    ) VALUES (?, 1, ?, 'Python', 'Arrays', 'medium', 'coding', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'heuristic')");

    $qTitle = "Find the second-largest distinct element in an array";
    $pLang = "Python";
    $inFmt = "First line integer N, second line N space-separated integers.";
    $outFmt = "Single integer representing second-largest distinct element, or -1.";
    $consts = "2 <= N <= 10^5, -10^9 <= A[i] <= 10^9";
    $samples = json_encode([['input' => "5\n12 35 1 10 34 1", 'output' => '34', 'explanation' => 'Second largest is 34.']]);
    $hidden = json_encode([['input' => "2\n5 5", 'output' => '-1']]);
    $refSol = "def second_largest(arr):\n    distinct = sorted(list(set(arr)), reverse=True)\n    return distinct[1] if len(distinct) >= 2 else -1";
    $tComp = "O(N log N)";
    $sComp = "O(N)";

    $stmt->bind_param("sissssssssss",
        $reqId, $testExamId, $qTitle, $pLang, $inFmt, $outFmt, $consts,
        $samples, $hidden, $refSol, $tComp, $sComp
    );
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->query("DELETE FROM exams WHERE id = {$testExamId}");
        return "Failed to insert into ai_generated_questions: " . $conn->error;
    }
    $aiQId = $conn->insert_id;
    $stmt->close();

    // Verify it is pending
    $chk = $conn->query("SELECT status, question_type, programming_language, reference_solution FROM ai_generated_questions WHERE id = {$aiQId}")->fetch_assoc();
    if ($chk['status'] !== 'pending') {
        return "Question should be in pending status, got " . $chk['status'];
    }
    if ($chk['question_type'] !== 'coding' || $chk['programming_language'] !== 'Python') {
        return "Question type or language mismatch";
    }

    // C. Perform Approval (as executed by review_ai_questions.php)
    $insStmt = $conn->prepare("INSERT INTO questions (
        exam_id, question, option_a, option_b, option_c, option_d, correct_option,
        subject, topic, difficulty, explanation,
        question_type, programming_language, input_format, output_format, constraints,
        sample_test_cases, hidden_test_cases, reference_solution, time_complexity, space_complexity
    ) VALUES (?, ?, NULL, NULL, NULL, NULL, NULL, 'Python', 'Arrays', 'medium', 'Standard array processing', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $insStmt->bind_param("isssssssssss",
        $testExamId, $qTitle, $chk['question_type'], $pLang, $inFmt, $outFmt, $consts,
        $samples, $hidden, $refSol, $tComp, $sComp
    );
    if (!$insStmt->execute()) {
        $insStmt->close();
        $conn->query("DELETE FROM ai_generated_questions WHERE id = {$aiQId}");
        $conn->query("DELETE FROM exams WHERE id = {$testExamId}");
        return "Failed to publish into questions table: " . $conn->error;
    }
    $publishedQId = $conn->insert_id;
    $insStmt->close();

    // Update status to approved
    $conn->query("UPDATE ai_generated_questions SET status = 'approved', reviewed_by = 1, reviewed_at = NOW() WHERE id = {$aiQId}");

    // Verify in active questions bank
    $published = $conn->query("SELECT * FROM questions WHERE id = {$publishedQId}")->fetch_assoc();
    if (!$published) {
        return "Published question not found in questions table";
    }
    if ($published['question_type'] !== 'coding') {
        return "Published question_type mismatch: " . $published['question_type'];
    }
    if ($published['programming_language'] !== 'Python') {
        return "Published programming_language mismatch: " . $published['programming_language'];
    }
    if (empty($published['reference_solution'])) {
        return "Published reference_solution missing";
    }

    // Cleanup test data
    $conn->query("DELETE FROM questions WHERE id = {$publishedQId}");
    $conn->query("DELETE FROM ai_generated_questions WHERE id = {$aiQId}");
    $conn->query("DELETE FROM exams WHERE id = {$testExamId}");

    return true;
});

echo "\n======================================================================\n";
echo "SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
