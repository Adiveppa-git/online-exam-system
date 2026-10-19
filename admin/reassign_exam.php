<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../config/db.php";

/* ADMIN CHECK */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    if (!headers_sent()) {
        header("Location: ../index.php");
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Destructive action requires POST request.");
}

$post_token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $post_token)) {
    die("Invalid CSRF security token.");
}

$exam_id = (int)($_POST['exam_id'] ?? 0);

if (!$exam_id) {
    if (!headers_sent()) {
        header("Location: exams.php");
    }
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

$conn->query("
    DELETE FROM violation_report
    WHERE exam_id = $exam_id
");

$conn->query("
    DELETE FROM exam_violations
    WHERE exam_id = $exam_id
");

/* DELETE SAVED ANSWERS */
$conn->query("
    DELETE FROM student_answers 
    WHERE exam_id = $exam_id
");

/* SUCCESS */
if (!headers_sent()) {
    header("Location: exams.php?msg=reassigned");
}
exit;
?>
