<?php
session_start();
require_once "../config/db.php";

/* ADMIN AUTH */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

/* FETCH RESULTS */
$sql = "
SELECT 
    e.id AS exam_id,
    e.title,
    e.marks_per_question,
    
    u.name AS student_name,

    r.id AS result_id,
    r.score,

    (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS total_questions

FROM exams e

LEFT JOIN results r ON r.exam_id = e.id
LEFT JOIN users u ON u.id = r.user_id

ORDER BY e.id DESC, u.name ASC
";

$results = $conn->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
<title>All Results</title>
<link rel="stylesheet" href="../assets/css/style.css">

<style>
.no-result{
    color:#999;
    font-style:italic;
}
</style>

</head>
<body>

<div class="wrapper">

<?php include "sidebar.php"; ?>

<div class="content">

<h1>All Results</h1>

<table>

<tr>
<th>SL No</th>
<th>Student</th>
<th>Exam</th>
<th>Total Marks</th>
<th>Score</th>
<th>Percentage</th>
<th>Result</th>
</tr>

<?php
$i=1;

while($row=$results->fetch_assoc()):

$totalMarks=$row['total_questions'] * $row['marks_per_question'];

$score=$row['score'] ?? 0;

$percentage=$totalMarks>0
? round(($score/$totalMarks)*100,2)
: 0;

$status=$row['result_id']
? ($percentage>=40 ? "PASS":"FAIL")
: "NOT ATTEMPTED";
?>

<tr>

<td><?= $i++ ?></td>

<td>
<?= $row['student_name']
? htmlspecialchars($row['student_name'])
: '<span class="no-result">No attempt yet</span>'
?>
</td>

<td><?= htmlspecialchars($row['title']) ?></td>

<td><?= $totalMarks ?></td>

<td>
<?= $row['result_id']
? $score
: '<span class="no-result">-</span>'
?>
</td>

<td>
<?= $row['result_id']
? $percentage."%"
: '<span class="no-result">-</span>'
?>
</td>

<td style="font-weight:bold;
color:
<?= $status=='PASS'?'green':
($status=='FAIL'?'red':'#999') ?>">
<?= $status ?>
</td>

</tr>

<?php endwhile; ?>

</table>

</div>
</div>

</body>
</html>
