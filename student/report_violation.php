<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id']) || !isset($_POST['exam_id'])) {
    exit;
}

/* CSRF VALIDATION */
$post_token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $post_token)) {
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
