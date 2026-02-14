<?php
session_start();
require_once "../config/db.php";

/* ADMIN CHECK */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$exam_id = (int)($_GET['exam_id'] ?? 0);

if (!$exam_id) {
    header("Location: exams.php");
    exit;
}

/* DELETE ALL RESULTS */
$conn->query("
    DELETE FROM results 
    WHERE exam_id = $exam_id
");

/* DELETE VIOLATIONS */
$conn->query("
    DELETE FROM violations 
    WHERE exam_id = $exam_id
");

/* DELETE SAVED ANSWERS */
$conn->query("
    DELETE FROM student_answers 
    WHERE exam_id = $exam_id
");

/* SUCCESS */
header("Location: exams.php?msg=reassigned");
exit;
?>
