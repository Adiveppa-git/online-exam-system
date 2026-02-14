<?php
session_start();
require_once "../config/db.php";

/* ADMIN AUTH */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

/* GET FILTER VALUES */
$exam_id = $_GET['exam_id'] ?? '';
$student_id = $_GET['student_id'] ?? '';

/* FETCH EXAMS */
$exams = $conn->query("
SELECT id,title 
FROM exams 
ORDER BY title ASC
");

/* FETCH STUDENTS */
$students = $conn->query("
SELECT id,name 
FROM users 
WHERE role='student'
ORDER BY name ASC
");

/* BUILD FILTER */
$where = "WHERE 1";

if (!empty($exam_id)) {
    $where .= " AND v.exam_id=".(int)$exam_id;
}

if (!empty($student_id)) {
    $where .= " AND v.user_id=".(int)$student_id;
}

/* FETCH VIOLATIONS WITH COUNT */
$sql = "
SELECT 
    u.name AS student,
    e.title AS exam,
    COUNT(v.id) AS violation_count

FROM violations v

JOIN users u ON u.id = v.user_id
JOIN exams e ON e.id = v.exam_id

$where

GROUP BY v.user_id, v.exam_id

ORDER BY violation_count DESC
";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html>
<head>

<title>Violation Report</title>

<link rel="stylesheet" href="../assets/css/style.css">

<style>

.filter-box{
background:#f8f9fa;
padding:20px;
border-radius:8px;
margin-bottom:20px;
display:flex;
gap:15px;
align-items:center;
flex-wrap:wrap;
}

.filter-box select{
height:42px;
padding:6px 12px;
font-size:16px;
}

.filter-btn{
background:#0d6efd;
color:white;
border:none;
padding:10px 22px;
border-radius:6px;
cursor:pointer;
font-weight:600;
}

.filter-btn:hover{
background:#0b5ed7;
}

.export-btn{
background:#198754;
color:white;
padding:10px 22px;
border-radius:6px;
text-decoration:none;
font-weight:600;
}

.export-btn:hover{
background:#157347;
}

</style>

</head>
<body>

<div class="wrapper">

<?php include "sidebar.php"; ?>

<div class="content">

<h1>Exam Violation Report</h1>

<!-- FILTER -->
<form method="GET">

<div class="filter-box">

<label><b>Exam:</b></label>

<select name="exam_id">

<option value="">All Exams</option>

<?php while($e=$exams->fetch_assoc()): ?>

<option value="<?= $e['id'] ?>"
<?= ($exam_id==$e['id'])?'selected':'' ?>>
<?= htmlspecialchars($e['title']) ?>
</option>

<?php endwhile; ?>

</select>


<label><b>Student:</b></label>

<select name="student_id">

<option value="">All Students</option>

<?php while($s=$students->fetch_assoc()): ?>

<option value="<?= $s['id'] ?>"
<?= ($student_id==$s['id'])?'selected':'' ?>>
<?= htmlspecialchars($s['name']) ?>
</option>

<?php endwhile; ?>

</select>


<button class="filter-btn" type="submit">
Filter
</button>


<a class="export-btn"
href="export_excel.php?exam_id=<?= $exam_id ?>&student_id=<?= $student_id ?>">
Export Excel
</a>


<a class="export-btn"
href="export_pdf.php?exam_id=<?= $exam_id ?>&student_id=<?= $student_id ?>">
Export PDF
</a>

</div>

</form>


<!-- TABLE -->

<table>

<tr>
<th>SL No</th>
<th>Student</th>
<th>Exam</th>
<th>Violations (Tab Switch Count)</th>
<th>Status</th>
</tr>

<?php if($result->num_rows > 0): ?>

<?php $i=1; while($row=$result->fetch_assoc()): 

$status = $row['violation_count'] >= 3 ? "VIOLATED" : "OK";

?>

<tr>

<td><?= $i++ ?></td>

<td><?= htmlspecialchars($row['student']) ?></td>

<td><?= htmlspecialchars($row['exam']) ?></td>

<td><?= $row['violation_count'] ?></td>

<td style="
color:<?= $status=='VIOLATED'?'red':'green' ?>;
font-weight:bold;
">

<?= $status ?>

</td>

</tr>

<?php endwhile; ?>

<?php else: ?>

<tr>
<td colspan="5" style="text-align:center;">
No violations found
</td>
</tr>

<?php endif; ?>

</table>

</div>
</div>

</body>
</html>
