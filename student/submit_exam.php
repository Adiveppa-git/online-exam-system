<?php
session_start();
require_once "../config/db.php";

/* ================= STUDENT AUTH CHECK ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit;
}

/* ================= POST ONLY ================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request");
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
$check->store_result();

if ($check->num_rows > 0) {
    header("Location: result.php");
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

/* ================= SAVE RESULT ================= */
$insert = $conn->prepare("
    INSERT INTO results (user_id, exam_id, score)
    VALUES (?, ?, ?)
");
$insert->bind_param("iii", $student_id, $exam_id, $score);
$insert->execute();

/* ================= CLEAN STUDENT ANSWERS (VERY IMPORTANT) ================= */
$clean = $conn->prepare("
    DELETE FROM student_answers
    WHERE student_id=? AND exam_id=?
");
$clean->bind_param("ii", $student_id, $exam_id);
$clean->execute();

/* ================= CLEAR SESSION ================= */
unset($_SESSION['exam_id']);

/* ================= REDIRECT TO RESULTS ================= */
header("Location: result.php");
exit;
