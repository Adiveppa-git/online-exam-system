<?php

require_once __DIR__ . '/../config/db.php';

echo "====================================================\n";
echo "    Phase D: Existing Core System Regression Test   \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

// Test 1: Users table query (Admin & Student roles)
echo "[Test 1] Testing Users table query... ";
$users = $conn->query("SELECT id, name, role FROM users");
if ($users && $users->num_rows > 0) {
    echo "PASSED (" . $users->num_rows . " users found)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// Test 2: Exams table query
echo "[Test 2] Testing Exams table query... ";
$exams = $conn->query("SELECT id, title, duration FROM exams");
if ($exams && $exams->num_rows > 0) {
    echo "PASSED (" . $exams->num_rows . " exams found)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// Test 3: Active Questions table query (including metadata columns)
echo "[Test 3] Testing Questions table query... ";
$questions = $conn->query("SELECT id, exam_id, question, correct_option, subject, topic, difficulty FROM questions");
if ($questions) {
    echo "PASSED (" . $questions->num_rows . " active questions found)\n";
    $passed++;
} else {
    echo "FAILED: " . $conn->error . "\n";
    $failed++;
}

// Test 4: Results & Student Answers tables
echo "[Test 4] Testing Results table query... ";
$results = $conn->query("SELECT id, user_id, score FROM results");
if ($results) {
    echo "PASSED (" . $results->num_rows . " results records found)\n";
    $passed++;
} else {
    echo "FAILED: " . $conn->error . "\n";
    $failed++;
}

// Test 5: Violation Reports table
echo "[Test 5] Testing Violation Reports table query... ";
$violations = $conn->query("SELECT id, user_id, exam_id, violation_count FROM violations");
if ($violations) {
    echo "PASSED (" . $violations->num_rows . " violation records found)\n";
    $passed++;
} else {
    echo "FAILED: " . $conn->error . "\n";
    $failed++;
}

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

exit($failed > 0 ? 1 : 0);
