<?php
session_start();
require_once "../config/db.php";

/* ================= ADMIN AUTH ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

/* ================= MODE ================= */
$mode = $_GET['mode'] ?? 'manage';

/* ================= FLASH MESSAGE ================= */
$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

/* ================= DELETE QUESTION (NO CONFIRMATION) ================= */
if (isset($_GET['delete'])) {

    $id = (int)$_GET['delete'];

    $stmt = $conn->prepare("DELETE FROM questions WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $_SESSION['success'] = "Question deleted successfully";

    header("Location: questions.php?mode=manage");
    exit;
}

/* ================= ADD QUESTION ================= */
if (isset($_POST['add_question'])) {

    $exam_id  = (int)$_POST['exam_id'];
    $question = trim($_POST['question']);
    $a = trim($_POST['option_a']);
    $b = trim($_POST['option_b']);
    $c = trim($_POST['option_c']);
    $d = trim($_POST['option_d']);
    $correct = $_POST['correct_option'];

    $stmt = $conn->prepare("
        INSERT INTO questions
        (exam_id, question, option_a, option_b, option_c, option_d, correct_option)
        VALUES (?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
        "issssss",
        $exam_id,
        $question,
        $a,
        $b,
        $c,
        $d,
        $correct
    );

    if ($stmt->execute()) {
        $_SESSION['success'] = "Question added successfully";
    } else {
        $_SESSION['error'] = "Failed to add question";
    }

    header("Location: questions.php?mode=add");
    exit;
}

/* ================= UPDATE QUESTION ================= */
if (isset($_POST['update_question'])) {

    $id = (int)$_POST['id'];

    $stmt = $conn->prepare("
        UPDATE questions SET
        question=?,
        option_a=?,
        option_b=?,
        option_c=?,
        option_d=?,
        correct_option=?
        WHERE id=?
    ");

    $stmt->bind_param(
        "ssssssi",
        $_POST['question'],
        $_POST['option_a'],
        $_POST['option_b'],
        $_POST['option_c'],
        $_POST['option_d'],
        $_POST['correct_option'],
        $id
    );

    $stmt->execute();

    $_SESSION['success'] = "Question updated successfully";

    header("Location: questions.php?mode=manage");
    exit;
}

/* ================= EDIT FETCH ================= */
$edit = null;

if ($mode === 'edit' && isset($_GET['id'])) {

    $id = (int)$_GET['id'];

    $edit = $conn
        ->query("SELECT * FROM questions WHERE id=$id")
        ->fetch_assoc();
}

/* ================= FETCH EXAMS ================= */
$exams = $conn->query("
    SELECT id, title
    FROM exams
    ORDER BY title
");

/* ================= FETCH QUESTIONS ================= */
$questions = $conn->query("
    SELECT q.*, e.title AS exam_title
    FROM questions q
    JOIN exams e ON q.exam_id=e.id
    ORDER BY q.id ASC
");
?>

<!DOCTYPE html>
<html>
<head>

<title><?= ucfirst($mode) ?> Question</title>

<link rel="stylesheet" href="../assets/css/style.css">

<style>

.content{
margin-left:240px;
padding:30px;
width:calc(100% - 240px);
}

.question-form{
width:100%;
background:#ffffff;
padding:25px;
border-radius:6px;
box-shadow:0 0 10px rgba(0,0,0,0.08);
}

.form-group{
margin-bottom:18px;
}

.form-group label{
display:block;
font-weight:600;
margin-bottom:6px;
}

.question-form input,
.question-form textarea,
.question-form select{
width:100%;
height:45px;
padding:10px;
font-size:16px;
border:1px solid #ccc;
border-radius:6px;
}

.question-form textarea{
height:100px;
}

.question-form button{
width:100%;
height:45px;
background:#0d6efd;
color:white;
border:none;
font-size:16px;
font-weight:bold;
border-radius:6px;
cursor:pointer;
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

table{
width:100%;
border-collapse:collapse;
margin-top:20px;
}

th{
background:#0d6efd;
color:white;
}

th, td{
padding:12px;
border:1px solid #ccc;
}

.success{
background:#d4edda;
color:#155724;
padding:12px;
margin-bottom:15px;
font-weight:bold;
border-radius:4px;
}

.error{
background:#f8d7da;
color:#721c24;
padding:12px;
margin-bottom:15px;
font-weight:bold;
border-radius:4px;
}

</style>

</head>

<body>

<div class="wrapper">

<?php include "sidebar.php"; ?>

<div class="content">

<h1>
<?= $mode==='add' ? 'Add Question' : ($mode==='edit' ? 'Edit Question' : 'Manage Questions') ?>
</h1>

<?php if($success): ?>
<div class="success"><?= $success ?></div>
<?php endif; ?>

<?php if($error): ?>
<div class="error"><?= $error ?></div>
<?php endif; ?>


<!-- FORM -->
<?php if($mode!=='manage'): ?>

<form method="post" class="question-form">

<?php if($edit): ?>
<input type="hidden" name="id" value="<?= $edit['id'] ?>">
<?php endif; ?>

<div class="form-group">
<label>Select Exam</label>

<select name="exam_id" required <?= $edit?'disabled':'' ?>>

<option value="">-- Select Exam --</option>

<?php while($e=$exams->fetch_assoc()): ?>

<option value="<?= $e['id'] ?>"
<?= $edit && $edit['exam_id']==$e['id']?'selected':'' ?>>

<?= htmlspecialchars($e['title']) ?>

</option>

<?php endwhile; ?>

</select>

</div>


<div class="form-group">
<label>Question</label>
<textarea name="question" required><?= htmlspecialchars($edit['question']??'') ?></textarea>
</div>


<div class="form-group">
<label>Option A</label>
<input type="text" name="option_a" required value="<?= $edit['option_a']??'' ?>">
</div>


<div class="form-group">
<label>Option B</label>
<input type="text" name="option_b" required value="<?= $edit['option_b']??'' ?>">
</div>


<div class="form-group">
<label>Option C</label>
<input type="text" name="option_c" required value="<?= $edit['option_c']??'' ?>">
</div>


<div class="form-group">
<label>Option D</label>
<input type="text" name="option_d" required value="<?= $edit['option_d']??'' ?>">
</div>


<div class="form-group">
<label>Correct Option</label>

<select name="correct_option" required>

<option value="">-- Select --</option>

<option value="A" <?= $edit && $edit['correct_option']=='A'?'selected':'' ?>>A</option>
<option value="B" <?= $edit && $edit['correct_option']=='B'?'selected':'' ?>>B</option>
<option value="C" <?= $edit && $edit['correct_option']=='C'?'selected':'' ?>>C</option>
<option value="D" <?= $edit && $edit['correct_option']=='D'?'selected':'' ?>>D</option>

</select>

</div>


<button type="submit" name="<?= $edit?'update_question':'add_question' ?>">
<?= $edit?'Update Question':'Add Question' ?>
</button>

</form>

<?php endif; ?>


<!-- TABLE -->
<?php if($mode==='manage'): ?>

<table>

<tr>
<th>SL No</th>
<th>Exam</th>
<th>Question</th>
<th>Correct</th>
<th>Action</th>
</tr>

<?php $i=1; while($q=$questions->fetch_assoc()): ?>

<tr>

<td><?= $i++ ?></td>

<td><?= htmlspecialchars($q['exam_title']) ?></td>

<td><?= htmlspecialchars($q['question']) ?></td>

<td><?= $q['correct_option'] ?></td>

<td>

<a href="questions.php?mode=edit&id=<?= $q['id'] ?>" class="edit-link">
Edit
</a>

|

<!-- DELETE WITHOUT CONFIRM -->
<a href="questions.php?delete=<?= $q['id'] ?>" class="delete-link">
Delete
</a>

</td>

</tr>

<?php endwhile; ?>

</table>

<?php endif; ?>


</div>
</div>

</body>
</html>
