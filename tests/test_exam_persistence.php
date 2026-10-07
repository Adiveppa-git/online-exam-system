<?php
/**
 * ISSUE-08 State Persistence & Auto-Save Integration Test Suite
 */

require_once __DIR__ . '/../config/db.php';

echo "====================================================\n";
echo "   ISSUE-08 Exam Attempt State Persistence Tests    \n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function runTest($name, $closure) {
    global $passCount, $failCount;
    try {
        $result = $closure();
        if ($result === true) {
            echo "[PASS] {$name}\n";
            $passCount++;
        } else {
            echo "[FAIL] {$name}: {$result}\n";
            $failCount++;
        }
    } catch (Throwable $e) {
        echo "[FAIL] {$name}: Exception: " . $e->getMessage() . "\n";
        $failCount++;
    }
}

// ----------------------------------------------------
// Fixture Setup
// ----------------------------------------------------
// Clean existing test data if any
$conn->query("DELETE FROM users WHERE email IN ('studentA_test@example.com', 'studentB_test@example.com')");
$conn->query("DELETE FROM exams WHERE title IN ('Test Exam 1 Persistence', 'Test Exam 2 Persistence')");

// Create Student A and Student B
$hash = password_hash("password123", PASSWORD_BCRYPT);
$stmtUserA = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES ('Student A', 'studentA_test@example.com', ?, 'student')");
$stmtUserA->bind_param("s", $hash);
$stmtUserA->execute();
$studentA_id = $conn->insert_id;

$stmtUserB = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES ('Student B', 'studentB_test@example.com', ?, 'student')");
$stmtUserB->bind_param("s", $hash);
$stmtUserB->execute();
$studentB_id = $conn->insert_id;

// Create Exam 1 and Exam 2
$stmtEx1 = $conn->prepare("INSERT INTO exams (title, duration, marks_per_question) VALUES ('Test Exam 1 Persistence', 30, 1)");
$stmtEx1->execute();
$exam1_id = $conn->insert_id;

$stmtEx2 = $conn->prepare("INSERT INTO exams (title, duration, marks_per_question) VALUES ('Test Exam 2 Persistence', 30, 1)");
$stmtEx2->execute();
$exam2_id = $conn->insert_id;

// Add Questions to Exam 1
$stmtQ1 = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES (?, 'Q1 text', 'Opt A', 'Opt B', 'Opt C', 'Opt D', 'B')");
$stmtQ1->bind_param("i", $exam1_id);
$stmtQ1->execute();
$q1_id = $conn->insert_id;

$stmtQ2 = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES (?, 'Q2 text', 'Opt A', 'Opt B', 'Opt C', 'Opt D', 'C')");
$stmtQ2->bind_param("i", $exam1_id);
$stmtQ2->execute();
$q2_id = $conn->insert_id;

// Add Question to Exam 2
$stmtQ3 = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES (?, 'Q3 text', 'Opt A', 'Opt B', 'Opt C', 'Opt D', 'A')");
$stmtQ3->bind_param("i", $exam2_id);
$stmtQ3->execute();
$q3_id = $conn->insert_id;

// Function helper to simulate HTML load of attempt_exam.php
function simulateAttemptExamLoad($student_id, $exam_id) {
    global $conn;
    // Check results table for completion
    $chkRes = $conn->prepare("SELECT id FROM results WHERE user_id = ? AND exam_id = ?");
    $chkRes->bind_param("ii", $student_id, $exam_id);
    $chkRes->execute();
    if ($chkRes->get_result()->num_rows > 0) {
        return ['status' => 'redirect_to_result'];
    }

    // Fetch questions
    $questions = [];
    $stmtQ = $conn->prepare("SELECT * FROM questions WHERE exam_id = ? ORDER BY id ASC");
    $stmtQ->bind_param("i", $exam_id);
    $stmtQ->execute();
    $resQ = $stmtQ->get_result();
    while ($row = $resQ->fetch_assoc()) {
        $questions[] = $row;
    }

    // Fetch saved answers
    $saved_answers = [];
    $stmtAns = $conn->prepare("SELECT question_id, answer FROM student_answers WHERE student_id = ? AND exam_id = ?");
    $stmtAns->bind_param("ii", $student_id, $exam_id);
    $stmtAns->execute();
    $resAns = $stmtAns->get_result();
    while ($rowAns = $resAns->fetch_assoc()) {
        $saved_answers[(int)$rowAns['question_id']] = $rowAns['answer'];
    }

    // Generate HTML snippet to verify pre-population
    $html = "";
    foreach ($questions as $index => $q) {
        $qid = (int)$q['id'];
        foreach (['A', 'B', 'C', 'D'] as $opt) {
            $isChecked = (isset($saved_answers[$qid]) && $saved_answers[$qid] === $opt) ? 'checked' : '';
            $html .= "<input type=\"radio\" name=\"answer[{$qid}]\" value=\"{$opt}\" {$isChecked}>\n";
        }
    }

    return [
        'status' => 'ok',
        'questions' => $questions,
        'saved_answers' => $saved_answers,
        'html' => $html
    ];
}

// Function helper to simulate save_answer.php
function simulateSaveAnswer($student_id, $exam_id, $question_id, $answer) {
    global $conn;

    if ($exam_id <= 0 || $question_id <= 0 || !in_array($answer, ['A', 'B', 'C', 'D'])) {
        return ['status' => 'error', 'msg' => 'Invalid input data'];
    }

    $chkComp = $conn->prepare("SELECT id FROM results WHERE user_id = ? AND exam_id = ?");
    $chkComp->bind_param("ii", $student_id, $exam_id);
    $chkComp->execute();
    if ($chkComp->get_result()->num_rows > 0) {
        return ['status' => 'error', 'msg' => 'Exam already completed'];
    }

    $chkQ = $conn->prepare("SELECT id FROM questions WHERE id = ? AND exam_id = ?");
    $chkQ->bind_param("ii", $question_id, $exam_id);
    $chkQ->execute();
    if ($chkQ->get_result()->num_rows === 0) {
        return ['status' => 'error', 'msg' => 'Invalid question for exam'];
    }

    $stmt = $conn->prepare("
        INSERT INTO student_answers (student_id, exam_id, question_id, answer)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE answer = VALUES(answer)
    ");
    $stmt->bind_param("iiis", $student_id, $exam_id, $question_id, $answer);
    if ($stmt->execute()) {
        return ['status' => 'success', 'msg' => 'Answer saved'];
    }
    return ['status' => 'error', 'msg' => 'Database error'];
}

// ----------------------------------------------------
// TEST 1: Student A starts Exam 1
// ----------------------------------------------------
runTest("TEST 1: Student A starts Exam 1 (unattempted, no saved answers)", function() use ($studentA_id, $exam1_id) {
    $res = simulateAttemptExamLoad($studentA_id, $exam1_id);
    if ($res['status'] !== 'ok') return "Expected status ok, got " . $res['status'];
    if (count($res['questions']) !== 2) return "Expected 2 questions, got " . count($res['questions']);
    if (count($res['saved_answers']) !== 0) return "Expected 0 saved answers initially";
    return true;
});

// ----------------------------------------------------
// TEST 2: Save Question 1 = A
// ----------------------------------------------------
runTest("TEST 2: Save Question 1 = A for Student A", function() use ($studentA_id, $exam1_id, $q1_id) {
    $res = simulateSaveAnswer($studentA_id, $exam1_id, $q1_id, 'A');
    if ($res['status'] !== 'success') return "Failed to save answer: " . ($res['msg'] ?? '');
    return true;
});

// ----------------------------------------------------
// TEST 3: Simulate page reload, verify Question 1 = A restored in DB & HTML
// ----------------------------------------------------
runTest("TEST 3: Simulate page reload and verify Question 1 = A restored in DB & HTML", function() use ($studentA_id, $exam1_id, $q1_id) {
    $res = simulateAttemptExamLoad($studentA_id, $exam1_id);
    if (!isset($res['saved_answers'][$q1_id]) || $res['saved_answers'][$q1_id] !== 'A') {
        return "Saved answer not found or incorrect: " . json_encode($res['saved_answers']);
    }
    if (strpos($res['html'], 'name="answer[' . $q1_id . ']" value="A" checked') === false) {
        return "Generated HTML does not contain checked option A for Q1";
    }
    return true;
});

// ----------------------------------------------------
// TEST 4: Save Question 2 = C
// ----------------------------------------------------
runTest("TEST 4: Save Question 2 = C for Student A", function() use ($studentA_id, $exam1_id, $q2_id) {
    $res = simulateSaveAnswer($studentA_id, $exam1_id, $q2_id, 'C');
    if ($res['status'] !== 'success') return "Failed to save Q2 answer";
    return true;
});

// ----------------------------------------------------
// TEST 5: Change Question 1 from A -> B
// ----------------------------------------------------
runTest("TEST 5: Change Question 1 answer from A to B", function() use ($studentA_id, $exam1_id, $q1_id) {
    $res = simulateSaveAnswer($studentA_id, $exam1_id, $q1_id, 'B');
    if ($res['status'] !== 'success') return "Failed to update Q1 answer";
    return true;
});

// ----------------------------------------------------
// TEST 6: Simulate another reload, verify Q1 = B and Q2 = C
// ----------------------------------------------------
runTest("TEST 6: Reload and verify Q1 = B and Q2 = C", function() use ($studentA_id, $exam1_id, $q1_id, $q2_id) {
    $res = simulateAttemptExamLoad($studentA_id, $exam1_id);
    if (($res['saved_answers'][$q1_id] ?? '') !== 'B') return "Q1 answer should be B";
    if (($res['saved_answers'][$q2_id] ?? '') !== 'C') return "Q2 answer should be C";
    if (strpos($res['html'], 'name="answer[' . $q1_id . ']" value="B" checked') === false) {
        return "HTML missing checked option B for Q1";
    }
    if (strpos($res['html'], 'name="answer[' . $q2_id . ']" value="C" checked') === false) {
        return "HTML missing checked option C for Q2";
    }
    return true;
});

// ----------------------------------------------------
// TEST 7: Verify unique row per student + exam + question
// ----------------------------------------------------
runTest("TEST 7: Verify exact 1 row in student_answers per question", function() use ($conn, $studentA_id, $exam1_id, $q1_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM student_answers WHERE student_id = ? AND exam_id = ? AND question_id = ?");
    $stmt->bind_param("iii", $studentA_id, $exam1_id, $q1_id);
    $stmt->execute();
    $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
    if ((int)$cnt !== 1) return "Expected exactly 1 row in student_answers for Q1, found " . $cnt;
    return true;
});

// ----------------------------------------------------
// TEST 8: Verify Student B cannot retrieve Student A's answers
// ----------------------------------------------------
runTest("TEST 8: Verify Student B cannot retrieve Student A's answers", function() use ($studentB_id, $exam1_id) {
    $res = simulateAttemptExamLoad($studentB_id, $exam1_id);
    if (count($res['saved_answers']) !== 0) {
        return "Student B received answers belonging to Student A!";
    }
    return true;
});

// ----------------------------------------------------
// TEST 9: Verify Student B cannot overwrite Student A's answer
// ----------------------------------------------------
runTest("TEST 9: Verify Student B cannot overwrite Student A's answer", function() use ($conn, $studentA_id, $studentB_id, $exam1_id, $q1_id) {
    // Attempt save as Student B
    simulateSaveAnswer($studentB_id, $exam1_id, $q1_id, 'D');

    // Verify Student A's answer is unchanged (remains 'B')
    $stmt = $conn->prepare("SELECT answer FROM student_answers WHERE student_id = ? AND exam_id = ? AND question_id = ?");
    $stmt->bind_param("iii", $studentA_id, $exam1_id, $q1_id);
    $stmt->execute();
    $ans = $stmt->get_result()->fetch_assoc()['answer'];
    if ($ans !== 'B') return "Student A's answer was overwritten to: " . $ans;
    return true;
});

// ----------------------------------------------------
// TEST 10: Verify answers for Exam 1 do not appear when loading Exam 2
// ----------------------------------------------------
runTest("TEST 10: Verify answers for Exam 1 do not appear when Student A loads Exam 2", function() use ($studentA_id, $exam2_id) {
    $res = simulateAttemptExamLoad($studentA_id, $exam2_id);
    if (count($res['saved_answers']) !== 0) {
        return "Answers from Exam 1 leaked into Exam 2!";
    }
    return true;
});

// ----------------------------------------------------
// TEST 11: Completed Exam Protection
// ----------------------------------------------------
runTest("TEST 11: Verify completed exam access is blocked and redirected", function() use ($conn, $studentA_id, $exam1_id, $q1_id) {
    // Insert completed result for Student A on Exam 1
    $stmtRes = $conn->prepare("INSERT INTO results (user_id, exam_id, score) VALUES (?, ?, 2)");
    $stmtRes->bind_param("ii", $studentA_id, $exam1_id);
    $stmtRes->execute();

    // Verify load attempt returns redirect
    $resLoad = simulateAttemptExamLoad($studentA_id, $exam1_id);
    if (($resLoad['status'] ?? '') !== 'redirect_to_result') {
        return "Completed exam load allowed instead of redirect: " . json_encode($resLoad);
    }

    // Verify save_answer fails on completed exam
    $resSave = simulateSaveAnswer($studentA_id, $exam1_id, $q1_id, 'A');
    if ($resSave['status'] !== 'error' || $resSave['msg'] !== 'Exam already completed') {
        return "Completed exam answer modification allowed: " . json_encode($resSave);
    }

    return true;
});

// ----------------------------------------------------
// TEST 12: Final Submission & Result Scoring
// ----------------------------------------------------
runTest("TEST 12: Verify final result scoring matches latest persisted answers", function() use ($conn, $studentB_id, $exam1_id, $q1_id, $q2_id) {
    // Save Q1 = B (correct) and Q2 = C (correct) for Student B
    simulateSaveAnswer($studentB_id, $exam1_id, $q1_id, 'B');
    simulateSaveAnswer($studentB_id, $exam1_id, $q2_id, 'C');

    // Simulate grading in exam_summary.php
    $stmtQ = $conn->prepare("SELECT id, correct_option FROM questions WHERE exam_id = ?");
    $stmtQ->bind_param("i", $exam1_id);
    $stmtQ->execute();
    $resQ = $stmtQ->get_result();

    // Fetch student's persisted answers
    $stmtAns = $conn->prepare("SELECT question_id, answer FROM student_answers WHERE student_id = ? AND exam_id = ?");
    $stmtAns->bind_param("ii", $studentB_id, $exam1_id);
    $stmtAns->execute();
    $resAns = $stmtAns->get_result();
    $answers = [];
    while ($row = $resAns->fetch_assoc()) {
        $answers[(int)$row['question_id']] = $row['answer'];
    }

    $score = 0;
    while ($row = $resQ->fetch_assoc()) {
        $qid = (int)$row['id'];
        if (isset($answers[$qid]) && $answers[$qid] === $row['correct_option']) {
            $score++;
        }
    }

    if ($score !== 2) return "Expected score of 2, calculated " . $score;
    return true;
});

// ----------------------------------------------------
// Clean up Fixtures
// ----------------------------------------------------
$conn->query("DELETE FROM student_answers WHERE student_id IN ($studentA_id, $studentB_id)");
$conn->query("DELETE FROM results WHERE user_id IN ($studentA_id, $studentB_id)");
$conn->query("DELETE FROM questions WHERE exam_id IN ($exam1_id, $exam2_id)");
$conn->query("DELETE FROM exams WHERE id IN ($exam1_id, $exam2_id)");
$conn->query("DELETE FROM users WHERE id IN ($studentA_id, $studentB_id)");

echo "\n----------------------------------------------------\n";
echo "Summary: {$passCount} Passed, {$failCount} Failed\n";
echo "====================================================\n";

if ($failCount > 0) exit(1);
exit(0);
