<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../config/db.php";

/* ================= STUDENT AUTH CHECK ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    if (!headers_sent()) {
        header("Location: ../index.php");
    }
    exit;
}

/* ================= POST ONLY ================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request");
}

/* ================= CSRF VALIDATION ================= */
$post_token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $post_token)) {
    die("Invalid CSRF security token");
}

$student_id = (int)$_SESSION['user_id'];
$exam_id    = (int)($_POST['exam_id'] ?? 0);

if ($exam_id <= 0) {
    die("Invalid exam");
}

/* ================= PREVENT DOUBLE SUBMISSION ================= */
$check = $conn->prepare(
    "SELECT id FROM results WHERE user_id=? AND exam_id=?"
);
$check->bind_param("ii", $student_id, $exam_id);
$check->execute();
$checkRes = $check->get_result();

if ($checkRes->num_rows > 0) {
    if (!headers_sent()) {
        header("Location: result.php");
    }
    exit;
}

/* ================= FETCH CORRECT ANSWERS ================= */
$q = $conn->prepare("
    SELECT id, correct_option
    FROM questions
    WHERE exam_id = ?
");
$q->bind_param("i", $exam_id);
$q->execute();
$qRes = $q->get_result();

/* ================= FETCH STUDENT ANSWERS ================= */
$a = $conn->prepare("
    SELECT question_id, answer
    FROM student_answers
    WHERE student_id=? AND exam_id=?
");
$a->bind_param("ii", $student_id, $exam_id);
$a->execute();
$aRes = $a->get_result();

/* ================= MAP STUDENT ANSWERS ================= */
$studentAnswers = [];
while ($row = $aRes->fetch_assoc()) {
    $studentAnswers[$row['question_id']] = $row['answer'];
}

/* ================= CALCULATE SCORE ================= */
$score = 0;

while ($row = $qRes->fetch_assoc()) {
    $qid = $row['id'];

    if (
        isset($studentAnswers[$qid]) &&
        $studentAnswers[$qid] === $row['correct_option']
    ) {
        $score++;
    }
}

/* ================= FETCH VIOLATION COUNT ================= */
$vCount = 0;
$vStmt = $conn->prepare("SELECT COUNT(*) AS total FROM violations WHERE user_id = ? AND exam_id = ?");
if ($vStmt) {
    $vStmt->bind_param("ii", $student_id, $exam_id);
    $vStmt->execute();
    $vRes = $vStmt->get_result();
    if ($vRow = $vRes->fetch_assoc()) {
        $vCount = (int)$vRow['total'];
    }
}

/* ================= SAVE RESULT ================= */
$insert = $conn->prepare("
    INSERT INTO results (user_id, exam_id, score, tab_switch_count)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE score = VALUES(score), tab_switch_count = VALUES(tab_switch_count)
");
if (!$insert) {
    $insert = $conn->prepare("
        INSERT INTO results (user_id, exam_id, score)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE score = VALUES(score)
    ");
    $insert->bind_param("iii", $student_id, $exam_id, $score);
} else {
    $insert->bind_param("iiii", $student_id, $exam_id, $score, $vCount);
}
$insert->execute();

/* ================= FLASH CONFIRMATION ================= */
$_SESSION['submission_success'] = [
    'exam_id' => $exam_id,
    'violations' => $vCount,
    'message' => 'Exam submitted successfully.'
];

/* ================= CLEAR SESSION ================= */
unset($_SESSION['exam_id']);

/* ================= REDIRECT TO RESULTS ================= */
if (!headers_sent()) {
    header("Location: result.php");
}
exit;
