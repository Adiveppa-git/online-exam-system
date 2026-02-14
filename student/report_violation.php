<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['user_id']) || !isset($_POST['exam_id'])) {
    exit;
}

$user_id = $_SESSION['user_id'];
$exam_id = (int)$_POST['exam_id'];

/* INSERT VIOLATION */
$stmt = $conn->prepare("
INSERT INTO violations (user_id, exam_id)
VALUES (?,?)
");

$stmt->bind_param("ii", $user_id, $exam_id);
$stmt->execute();

echo "saved";
?>
