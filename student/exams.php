<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

$sql = "
SELECT e.id,e.title,e.duration,e.marks_per_question,r.id AS result_id
FROM exams e
LEFT JOIN results r ON r.exam_id=e.id AND r.user_id=?
ORDER BY e.id ASC
";

$stmt=$conn->prepare($sql);
$stmt->bind_param("i",$user_id);
$stmt->execute();
$exams=$stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<title>Available Exams</title>
<link rel="stylesheet" href="../assets/css/style.css">

<style>
.attempt-btn{
color:#0d6efd;
font-weight:bold;
text-decoration:underline;
}
.btn-disabled{
color:gray;
font-weight:bold;
}
</style>

</head>
<body>

<div class="wrapper">

<?php include "sidebar.php"; ?>

<div class="content">

<h1>Available Exams</h1>

<table>

<tr>
<th>SL No</th>
<th>Exam Title</th>
<th>Duration</th>
<th>Marks / Question</th>
<th>Action</th>
</tr>

<?php $i=1; while($exam=$exams->fetch_assoc()): ?>

<tr>

<td><?= $i++ ?></td>

<td><?= htmlspecialchars($exam['title']) ?></td>

<td><?= $exam['duration'] ?> min</td>

<td><?= $exam['marks_per_question'] ?></td>

<td>

<?php if($exam['result_id']): ?>

<span class="btn-disabled">Attempted</span>

<?php else: ?>

<a class="attempt-btn"
href="attempt_exam.php?exam_id=<?= $exam['id'] ?>">
Attempt
</a>

<?php endif; ?>

</td>

</tr>

<?php endwhile; ?>

</table>

</div>
</div>

</body>
</html>
