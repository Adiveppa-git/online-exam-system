<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];


/* ================= USER INFO ================= */

$user = $conn->query("
SELECT name 
FROM users 
WHERE id = $user_id
")->fetch_assoc();


/* ================= AVAILABLE EXAMS ================= */

$available_exams = $conn->query("
SELECT COUNT(*) AS total
FROM exams
WHERE id NOT IN (
    SELECT exam_id FROM results WHERE user_id = $user_id
)
")->fetch_assoc()['total'];


/* ================= ATTEMPTED EXAMS ================= */

$attempted_exams = $conn->query("
SELECT COUNT(*) AS total
FROM results
WHERE user_id = $user_id
")->fetch_assoc()['total'];


/* ================= PASSED EXAMS ================= */

$passed_exams = $conn->query("
SELECT COUNT(*) AS total
FROM results r
JOIN exams e ON e.id = r.exam_id
WHERE r.user_id = $user_id
AND (r.score * e.marks_per_question) >= 
(
    (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id)
    * e.marks_per_question * 0.4
)
")->fetch_assoc()['total'];


/* ================= FAILED EXAMS ================= */

$failed_exams = $conn->query("
SELECT COUNT(*) AS total
FROM results r
JOIN exams e ON e.id = r.exam_id
WHERE r.user_id = $user_id
AND (r.score * e.marks_per_question) <
(
    (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id)
    * e.marks_per_question * 0.4
)
")->fetch_assoc()['total'];


/* ================= RECENT ACTIVITY ================= */

$recent_activity = $conn->query("
SELECT exams.title, results.score, exams.marks_per_question,
(SELECT COUNT(*) FROM questions WHERE exam_id = exams.id) AS total_questions
FROM results
JOIN exams ON exams.id = results.exam_id
WHERE results.user_id = $user_id
ORDER BY results.id DESC
LIMIT 4
");


/* ================= PERFORMANCE SUMMARY (EXAM WISE) ================= */

$performance = $conn->query("
SELECT 
exams.id,
exams.title,
results.score,
exams.marks_per_question,
(SELECT COUNT(*) FROM questions WHERE exam_id = exams.id) AS total_questions
FROM results
JOIN exams ON exams.id = results.exam_id
WHERE results.user_id = $user_id
ORDER BY exams.id DESC
");


/* ================= EXAM WISE RANK ================= */

$ranks = $conn->query("
SELECT 
e.title,
ranked.rank,
ranked.score,
ranked.total_questions,
ranked.marks_per_question
FROM (
    SELECT 
    r.exam_id,
    r.user_id,
    r.score,
    e.marks_per_question,
    (SELECT COUNT(*) FROM questions WHERE exam_id=e.id) AS total_questions,
    DENSE_RANK() OVER (PARTITION BY r.exam_id ORDER BY r.score DESC) AS rank
    FROM results r
    JOIN exams e ON e.id = r.exam_id
) ranked
JOIN exams e ON e.id = ranked.exam_id
WHERE ranked.user_id = $user_id
ORDER BY e.id DESC
");

?>

<!DOCTYPE html>
<html>
<head>
<title>Student Dashboard</title>
<link rel="stylesheet" href="../assets/css/style.css">

<style>

.dashboard-cards{
display:grid;
grid-template-columns: repeat(4, 1fr);
gap:20px;
margin:20px 0;
}


.card{
background:rgba(255,255,255,0.9);
padding:20px;
border-radius:10px;
box-shadow:0 4px 10px rgba(0,0,0,0.1);
min-width:220px;
}

.card h3{
margin:0;
font-size:18px;
}

.card p{
font-size:28px;
font-weight:bold;
color:#0d6efd;
}

.section{
margin-top:30px;
}

</style>

</head>
<body>

<div class="wrapper">

<?php include "sidebar.php"; ?>

<div class="content">

<h1>Welcome, <?= htmlspecialchars($user['name']) ?> 👋</h1>

<hr>


<!-- ================= CARDS ================= -->

<div class="dashboard-cards">

<div class="card">
<h3>Available Exams</h3>
<p><?= $available_exams ?></p>
</div>

<div class="card">
<h3>Attempted</h3>
<p><?= $attempted_exams ?></p>
</div>

<div class="card">
<h3>Passed</h3>
<p><?= $passed_exams ?></p>
</div>

<div class="card">
<h3>Failed</h3>
<p><?= $failed_exams ?></p>
</div>

</div>


<hr>


<!-- ================= RECENT ACTIVITY ================= -->

<div class="section">

<h2>Recent Activity</h2>

<table>

<tr>
<th>Exam</th>
<th>Marks</th>
<th>Percentage</th>
<th>Status</th>
</tr>

<?php while($row=$recent_activity->fetch_assoc()):

$total = $row['total_questions'] * $row['marks_per_question'];
$obtained = $row['score'] * $row['marks_per_question'];

$percent = round(($obtained/$total)*100,2);

$status = $percent >= 40 ? "PASS":"FAIL";

?>

<tr>

<td><?= htmlspecialchars($row['title']) ?></td>

<td><?= $obtained ?> / <?= $total ?></td>

<td><?= $percent ?>%</td>

<td style="color:<?= $status=="PASS"?"green":"red" ?>;font-weight:bold">
<?= $status ?>
</td>

</tr>

<?php endwhile; ?>

</table>

</div>


<hr>


<!-- ================= PERFORMANCE SUMMARY (EXAM WISE) ================= -->

<div class="section">

<h2>Performance Summary</h2>

<table>

<tr>
<th>Exam</th>
<th>Marks</th>
<th>Total</th>
<th>Percentage</th>
<th>Result</th>
</tr>

<?php while($row=$performance->fetch_assoc()):

$total=$row['total_questions']*$row['marks_per_question'];

$obtained=$row['score']*$row['marks_per_question'];

$percent=round(($obtained/$total)*100,2);

$status=$percent>=40?"PASS":"FAIL";

?>

<tr>

<td><?= htmlspecialchars($row['title']) ?></td>

<td><?= $obtained ?></td>

<td><?= $total ?></td>

<td><?= $percent ?>%</td>

<td style="color:<?= $status=="PASS"?"green":"red" ?>;font-weight:bold">
<?= $status ?>
</td>

</tr>

<?php endwhile; ?>

</table>

</div>


<hr>


<!-- ================= EXAM WISE RANK ================= -->

<div class="section">

<h2>Rank and Progress (Exam Wise)</h2>

<table>

<tr>
<th>Exam</th>
<th>Your Marks</th>
<th>Total Marks</th>
<th>Percentage</th>
<th>Your Rank</th>
</tr>

<?php while($row=$ranks->fetch_assoc()):

$total=$row['total_questions']*$row['marks_per_question'];

$obtained=$row['score']*$row['marks_per_question'];

$percent=round(($obtained/$total)*100,2);

?>

<tr>

<td><?= htmlspecialchars($row['title']) ?></td>

<td><?= $obtained ?></td>

<td><?= $total ?></td>

<td><?= $percent ?>%</td>

<td style="font-weight:bold;color:#0d6efd">
<?= $row['rank'] ?>
</td>

</tr>

<?php endwhile; ?>

</table>

</div>


</div>
</div>

</body>
</html>
