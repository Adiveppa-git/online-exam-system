<?php
session_start();
require_once "../config/db.php";

/* ADMIN AUTH */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$message = "";
/* FETCH EXAM FOR EDIT */
$edit_exam = null;

if (isset($_GET['edit'])) {

    $exam_id = (int)$_GET['edit'];

    $stmt = $conn->prepare("SELECT * FROM exams WHERE id=?");
    $stmt->bind_param("i", $exam_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $edit_exam = $result->fetch_assoc();
}
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


/* UPDATE EXAM */
if (isset($_POST['update_exam'])) {

    $exam_id = (int)$_POST['exam_id'];
    $title = trim($_POST['title']);
    $duration = (int)$_POST['duration'];
    $marks = (int)$_POST['marks'];

    $stmt = $conn->prepare("
        UPDATE exams
        SET title=?, duration=?, marks_per_question=?
        WHERE id=?
    ");

    $stmt->bind_param("siii", $title, $duration, $marks, $exam_id);
    $stmt->execute();

    header("Location: exams.php");
    exit;
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

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/mobile.css">

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

<?php if($edit_exam): ?>
<input type="hidden" name="exam_id" value="<?= $edit_exam['id'] ?>">
<?php endif; ?>
<input type="text"
name="title"
placeholder="Exam Name"
value="<?= $edit_exam['title'] ?? '' ?>"
required>

<input type="number"
name="duration"
placeholder="Duration (minutes)"
value="<?= $edit_exam['duration'] ?? '' ?>"
required>

<input type="number"
name="marks"
placeholder="Marks per Question"
value="<?= $edit_exam['marks_per_question'] ?? '' ?>"
required>

<button type="submit" name="<?= $edit_exam ? 'update_exam' : 'add_exam' ?>">
<?= $edit_exam ? 'Update Exam' : 'Add Exam' ?>
</button>

</form>

<div class="card">
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
