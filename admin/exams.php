<?php
session_start();
require_once "../config/db.php";

/* ADMIN AUTH */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$message = "";

/* DELETE EXAM */
if (isset($_GET['delete'])) {

    $exam_id = (int)$_GET['delete'];

    $conn->query("DELETE FROM student_answers WHERE exam_id=$exam_id");
    $conn->query("DELETE FROM results WHERE exam_id=$exam_id");
    $conn->query("DELETE FROM violations WHERE exam_id=$exam_id");
    $conn->query("DELETE FROM questions WHERE exam_id=$exam_id");
    $conn->query("DELETE FROM exams WHERE id=$exam_id");

    header("Location: exams.php");
    exit;
}

/* REASSIGN EXAM */
if (isset($_GET['reassign'])) {

    $exam_id = (int)$_GET['reassign'];

    $conn->query("DELETE FROM student_answers WHERE exam_id=$exam_id");
    $conn->query("DELETE FROM results WHERE exam_id=$exam_id");
    $conn->query("DELETE FROM violations WHERE exam_id=$exam_id");

    header("Location: exams.php?msg=reassigned");
    exit;
}

/* ADD EXAM */
if (isset($_POST['add_exam'])) {

    $title = trim($_POST['title']);
    $duration = (int)$_POST['duration'];
    $marks = (int)$_POST['marks'];

    $check=$conn->prepare("SELECT id FROM exams WHERE LOWER(title)=LOWER(?)");
    $check->bind_param("s",$title);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {

        $message="Exam already exists";

    } else {

        $stmt=$conn->prepare(
        "INSERT INTO exams(title,duration,marks_per_question)
         VALUES(?,?,?)");

        $stmt->bind_param("sii",$title,$duration,$marks);
        $stmt->execute();

        header("Location: exams.php");
        exit;
    }
}

/* FETCH EXAMS */
$exams=$conn->query("
SELECT e.*,
(SELECT COUNT(*) FROM results r WHERE r.exam_id=e.id) attempted
FROM exams e ORDER BY id ASC
");
?>

<!DOCTYPE html>
<html>
<head>

<title>Manage Exams</title>

<link rel="stylesheet" href="../assets/css/style.css">

<style>

.exam-form{
display:flex;
gap:18px;
margin-bottom:20px;
}

.exam-form input{
height:44px;
padding:0 14px;
border:1px solid #ccc;
border-radius:6px;
}

.exam-form button{
height:44px;
background:#0d6efd;
color:white;
border:none;
padding:0 26px;
border-radius:6px;
cursor:pointer;
font-weight:600;
}

.edit-link{
color:#0d6efd;
font-weight:600;
text-decoration:underline;
}

.delete-link{
color:red;
font-weight:600;
text-decoration:underline;
}

.reassign-link{
color:#198754;
font-weight:600;
text-decoration:underline;
}

.success{
color:green;
font-weight:bold;
margin-bottom:10px;
}

.error{
color:red;
font-weight:bold;
margin-bottom:10px;
}

</style>

</head>
<body>

<div class="wrapper">

<?php include "sidebar.php"; ?>

<div class="content">

<h1>Manage Exams</h1>

<?php if(isset($_GET['msg'])): ?>
<p class="success">Exam reassigned successfully</p>
<?php endif; ?>

<?php if($message): ?>
<p class="error"><?= $message ?></p>
<?php endif; ?>

<form method="post" class="exam-form">

<input type="text"
name="title"
placeholder="Exam Name"
required>

<input type="number"
name="duration"
placeholder="Duration (minutes)"
required>

<input type="number"
name="marks"
placeholder="Marks per Question"
required>

<button type="submit" name="add_exam">
Add Exam
</button>

</form>

<table>

<tr>
<th>SL No</th>
<th>Exam Title</th>
<th>Duration</th>
<th>Marks / Question</th>
<th>Action</th>
</tr>

<?php $i=1; while($row=$exams->fetch_assoc()): ?>

<tr>

<td><?= $i++ ?></td>

<td><?= htmlspecialchars($row['title']) ?></td>

<td><?= $row['duration'] ?> min</td>

<td><?= $row['marks_per_question'] ?></td>

<td>

<a class="edit-link"
href="exams.php?edit=<?= $row['id'] ?>">
Edit
</a>

|

<!-- DELETE WITHOUT CONFIRM -->
<a class="delete-link"
href="exams.php?delete=<?= $row['id'] ?>">
Delete
</a>

|

<!-- REASSIGN WITHOUT CONFIRM -->
<a class="reassign-link"
href="exams.php?reassign=<?= $row['id'] ?>">
Reassign Exam
</a>

</td>

</tr>

<?php endwhile; ?>

</table>

</div>
</div>

</body>
</html>
