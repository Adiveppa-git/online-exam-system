<?php
session_start();
require_once "../config/db.php";

/* ===== AUTH ===== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') die("Invalid request");

$user_id = $_SESSION['user_id'];
$exam_id = (int)($_POST['exam_id'] ?? 0);
$answers = $_POST['answer'] ?? [];

if (!$exam_id) die("Invalid exam");

/* PREVENT DOUBLE SUBMISSION */
$chk = $conn->prepare("SELECT id FROM results WHERE user_id=? AND exam_id=?");
$chk->bind_param("ii",$user_id,$exam_id);
$chk->execute();
$resChk = $chk->get_result();
if ($resChk->num_rows > 0) {
    header("Location: result.php");
    exit;
}

/* FETCH CORRECT ANSWERS */
$q = $conn->prepare("SELECT id, correct_option FROM questions WHERE exam_id=?");
$q->bind_param("i",$exam_id);
$q->execute();
$res = $q->get_result();

/* CALCULATE SCORE */
$score = 0;
while ($row = $res->fetch_assoc()) {
    $qid = $row['id'];
    if (isset($answers[$qid]) && $answers[$qid] === $row['correct_option']) {
        $score++;
    }
}

/* SAVE PER-QUESTION STUDENT ANSWERS */
if (!empty($answers) && is_array($answers)) {
    $saveAns = $conn->prepare("
        INSERT INTO student_answers (student_id, exam_id, question_id, answer)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE answer = VALUES(answer)
    ");
    foreach ($answers as $qid => $ansVal) {
        $qidInt = (int)$qid;
        $cleanAns = trim((string)$ansVal);
        if ($qidInt > 0 && in_array($cleanAns, ['A', 'B', 'C', 'D'], true)) {
            $saveAns->bind_param("iiis", $user_id, $exam_id, $qidInt, $cleanAns);
            $saveAns->execute();
        }
    }
}

/* SAVE RESULT */
$ins = $conn->prepare("INSERT INTO results (user_id, exam_id, score) VALUES (?,?,?)");
$ins->bind_param("iii",$user_id,$exam_id,$score);
$ins->execute();

/* REDIRECT */
header("Location: result.php");
exit;
