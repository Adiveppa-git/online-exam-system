<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../config/db.php";

header("Content-Type: application/json");

/* ===== STUDENT AUTH CHECK ===== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    echo json_encode([
        "status" => "error",
        "msg" => "Unauthorized access"
    ]);
    exit;
}

/* ===== CSRF VALIDATION ===== */
$post_token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $post_token)) {
    echo json_encode([
        "status" => "error",
        "msg" => "Invalid CSRF security token"
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
    $question_id <= 0
) {
    echo json_encode([
        "status" => "error",
        "msg" => "Invalid input data"
    ]);
    exit;
}

/* ===== CHECK IF EXAM ALREADY COMPLETED ===== */
$chkComp = $conn->prepare("SELECT id FROM results WHERE user_id = ? AND exam_id = ?");
$chkComp->bind_param("ii", $student_id, $exam_id);
$chkComp->execute();
if ($chkComp->get_result()->num_rows > 0) {
    echo json_encode([
        "status" => "error",
        "msg" => "Exam already completed"
    ]);
    exit;
}

/* ===== VALIDATE QUESTION BELONGS TO EXAM ===== */
$chkQ = $conn->prepare("SELECT id, option_a FROM questions WHERE id = ? AND exam_id = ?");
$chkQ->bind_param("ii", $question_id, $exam_id);
$chkQ->execute();
$qRow = $chkQ->get_result()->fetch_assoc();
$chkQ->close();

if (!$qRow) {
    echo json_encode([
        "status" => "error",
        "msg" => "Invalid question for exam"
    ]);
    exit;
}

$has_options = !empty($qRow['option_a']);
if ($has_options) {
    if (!in_array($answer, ['A','B','C','D'], true)) {
        echo json_encode([
            "status" => "error",
            "msg" => "Invalid MCQ choice"
        ]);
        exit;
    }
} else {
    if (strlen($answer) > 255) {
        $answer = substr($answer, 0, 255);
    }
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
