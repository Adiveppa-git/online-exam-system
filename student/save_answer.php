<?php
session_start();
require_once "../config/db.php";

header("Content-Type: application/json");

/* ===== STUDENT AUTH CHECK ===== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    echo json_encode([
        "status" => "error",
        "msg" => "Unauthorized access"
    ]);
    exit;
}

$student_id  = (int)$_SESSION['user_id'];
$exam_id     = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
$question_id = isset($_POST['question_id']) ? (int)$_POST['question_id'] : 0;
$answer      = $_POST['answer'] ?? '';

/* ===== VALIDATION ===== */
if (
    $exam_id <= 0 ||
    $question_id <= 0 ||
    !in_array($answer, ['A','B','C','D'])
) {
    echo json_encode([
        "status" => "error",
        "msg" => "Invalid input data"
    ]);
    exit;
}

/* ===== INSERT OR UPDATE ANSWER ===== */
$stmt = $conn->prepare("
    INSERT INTO student_answers (student_id, exam_id, question_id, answer)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE answer = VALUES(answer)
");

$stmt->bind_param(
    "iiis",
    $student_id,
    $exam_id,
    $question_id,
    $answer
);

if ($stmt->execute()) {
    echo json_encode([
        "status" => "success",
        "msg" => "Answer saved"
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "msg" => "Database error"
    ]);
}
