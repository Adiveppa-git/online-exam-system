<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

/* DATABASE */
require_once "../config/db.php";

/* ADMIN INFO */
$admin_name = $_SESSION['name'] ?? 'Admin';
$last_login = date("d M Y, h:i A");

/* COUNT DATA FOR CARDS */
$total_students = $conn->query(
"SELECT COUNT(*) AS total FROM users WHERE role='student'"
)->fetch_assoc()['total'];

$total_exams = $conn->query(
"SELECT COUNT(*) AS total FROM exams"
)->fetch_assoc()['total'];

$total_questions = $conn->query(
"SELECT COUNT(*) AS total FROM questions"
)->fetch_assoc()['total'];

$total_attempts = $conn->query(
"SELECT COUNT(*) AS total FROM results"
)->fetch_assoc()['total'];


/* ================= RECENT ACTIVITY (LAST 4) ================= */
$recent_activity = $conn->query("
SELECT users.name, exams.title, results.score
FROM results
JOIN users ON users.id = results.user_id
JOIN exams ON exams.id = results.exam_id
ORDER BY results.id DESC
LIMIT 4
");


/* ================= EXAM LIST ================= */
$exam_list = $conn->query("
SELECT id, title, marks_per_question
FROM exams
ORDER BY id ASC
");

?>


<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<title>Admin Dashboard</title>
<link rel="stylesheet" href="../assets/css/style.css">

</head>

<body>

<div class="wrapper">

<!-- SIDEBAR -->
<?php include "sidebar.php"; ?>


<!-- MAIN CONTENT -->
<div class="content">

<h1>Welcome Admin, <?= htmlspecialchars($admin_name) ?></h1>

<p>Last login: <?= $last_login ?></p>

<p>Use the sidebar to manage the Online Examination System.</p>

<hr>


<!-- DASHBOARD CARDS -->
<div class="dashboard-cards">

<div class="card">
<h3>Students</h3>
<p><?= $total_students ?></p>
</div>

<div class="card">
<h3>Exams</h3>
<p><?= $total_exams ?></p>
</div>

<div class="card">
<h3>Questions</h3>
<p><?= $total_questions ?></p>
</div>

<div class="card">
<h3>Attempts</h3>
<p><?= $total_attempts ?></p>
</div>

</div>


<hr>


<!-- RECENT ACTIVITY -->
<h2>Recent Activity</h2>

<div class="card">

<?php if($recent_activity->num_rows > 0): ?>

<table>

<tr>
<th>Student</th>
<th>Exam</th>
<th>Score</th>
</tr>

<?php while($row = $recent_activity->fetch_assoc()): ?>

<tr>
<td><?= htmlspecialchars($row['name']) ?></td>
<td><?= htmlspecialchars($row['title']) ?></td>
<td><?= $row['score'] ?></td>
</tr>

<?php endwhile; ?>

</table>

<?php else: ?>

<p>No recent activity found.</p>

<?php endif; ?>

</div>


<hr>


<!-- TOP STUDENTS EXAM WISE -->
<h2>Top Students (Exam Wise)</h2>

<?php while($exam = $exam_list->fetch_assoc()): ?>

<div class="card">

<h3><?= htmlspecialchars($exam['title']) ?></h3>

<?php

$exam_id = $exam['id'];
$marks_per_question = $exam['marks_per_question'];

/* total questions */
$q = $conn->query("
SELECT COUNT(*) AS total_questions
FROM questions
WHERE exam_id = $exam_id
")->fetch_assoc();

$total_marks = $q['total_questions'] * $marks_per_question;


/* top 3 distinct scores */
$top_scores = $conn->query("
SELECT DISTINCT score
FROM results
WHERE exam_id = $exam_id
ORDER BY score DESC
LIMIT 3
");

if($top_scores->num_rows > 0):
?>

<table>

<tr>
<th>Rank</th>
<th>Student</th>
<th>Total Marks</th>
<th>Score</th>
<th>Percentage</th>
</tr>

<?php

$rank = 1;

while($score_row = $top_scores->fetch_assoc()):

$score = $score_row['score'];

/* all students with same score */
$students = $conn->query("
SELECT users.name, results.score
FROM results
JOIN users ON users.id = results.user_id
WHERE results.exam_id = $exam_id
AND results.score = $score
");

while($student = $students->fetch_assoc()):

$percentage =
$total_marks > 0
? round(($student['score'] / $total_marks) * 100, 2)
: 0;

?>

<tr>

<td><?= $rank ?></td>

<td><?= htmlspecialchars($student['name']) ?></td>

<td><?= $total_marks ?></td>

<td><?= $student['score'] ?></td>

<td><?= $percentage ?>%</td>

</tr>

<?php endwhile; ?>

<?php $rank++; endwhile; ?>

</table>

<?php else: ?>

<p>No attempts for this exam.</p>

<?php endif; ?>

</div>

<br>

<?php endwhile; ?>


</div>

</div>

</body>

</html>
