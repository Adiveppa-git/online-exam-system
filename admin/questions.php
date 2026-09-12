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
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/mobile.css">

<style>



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
td a {
    display: inline-block;
    margin-right: 5px;
}

td {
    white-space: nowrap;
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


.content h1 {
    color: #0f172a !important;
}

.manage-q-th {
    background: #1976D2 !important;
    color: #FFFFFF !important;
    font-weight: 700 !important;
}

.edit-link, .delete-link {
    padding: 6px 12px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    display: inline-block;
    cursor: pointer;
}

.edit-link {
    background: #22d3ee !important;
    color: #0f172a !important;
}

.delete-link {
    background: #dc3545 !important;
    color: white !important;
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
.action-btns {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: center;
}

.action-btns a {
    display: inline-block;
    white-space: nowrap;
}
</style>

</head>

<body>

<div class="wrapper">

<?php include "sidebar.php"; ?>

<div class="content">

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <h1 style="margin: 0; color: #0f172a !important;">
        <?= $mode==='add' ? 'Add Question' : ($mode==='edit' ? 'Edit Question' : 'Manage Questions') ?>
    </h1>
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <?php if ($mode === 'manage'): ?>
            <a href="ai_question_generator.php" style="background: #0d6efd; color: white; padding: 10px 18px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; display: inline-block;">✨ Generate AI Questions</a>
            <a href="add_question.php" style="background: #0d6efd; color: white; padding: 10px 18px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; display: inline-block;">➕ Add Question</a>
        <?php elseif ($mode === 'add'): ?>
            <a href="manage_questions.php" style="background: #0d6efd; color: white; padding: 10px 18px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; display: inline-block;">&larr; Manage Questions</a>
            <a href="ai_question_generator.php" style="background: #0d6efd; color: white; padding: 10px 18px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; display: inline-block;">✨ Generate AI Questions</a>
        <?php else: ?>
            <a href="manage_questions.php" style="background: #0d6efd; color: white; padding: 10px 18px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; display: inline-block;">&larr; Manage Questions</a>
        <?php endif; ?>
    </div>
</div>

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
<div class="card" style="background:#ffffff; padding:20px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.06); width:100%; box-sizing:border-box; overflow-x:auto;">
<table style="width:100%; border-collapse:collapse;">
<thead>
<tr style="background:#1976D2;">
<th class="manage-q-th" style="padding:12px; text-align:left; background:#1976D2; color:#FFFFFF; font-weight:700;">SL No</th>
<th class="manage-q-th" style="padding:12px; text-align:left; background:#1976D2; color:#FFFFFF; font-weight:700;">Exam</th>
<th class="manage-q-th" style="padding:12px; text-align:left; background:#1976D2; color:#FFFFFF; font-weight:700;">Question</th>
<th class="manage-q-th" style="padding:12px; text-align:center; background:#1976D2; color:#FFFFFF; font-weight:700;">Correct</th>
<th class="manage-q-th" style="padding:12px; text-align:center; background:#1976D2; color:#FFFFFF; font-weight:700;">Action</th>
</tr>
</thead>
<tbody>
<?php $i=1; while($q=$questions->fetch_assoc()): ?>
<tr>
<td style="padding:12px; border-bottom:1px solid #e2e8f0; vertical-align:middle;"><?= $i++ ?></td>
<td style="padding:12px; border-bottom:1px solid #e2e8f0; vertical-align:middle; font-weight:600;"><?= htmlspecialchars($q['exam_title']) ?></td>
<td style="padding:12px; border-bottom:1px solid #e2e8f0; vertical-align:middle;"><?= htmlspecialchars($q['question']) ?></td>
<td style="padding:12px; border-bottom:1px solid #e2e8f0; vertical-align:middle; text-align:center;"><span style="background:#e0e7ff; color:#3730a3; padding:4px 10px; border-radius:12px; font-weight:bold;"><?= htmlspecialchars($q['correct_option']) ?></span></td>
<td style="padding:12px; border-bottom:1px solid #e2e8f0; vertical-align:middle; text-align:center;" class="action-btns">
<a href="questions.php?mode=edit&id=<?= $q['id'] ?>" class="edit-link" style="background:#22d3ee; color:#0f172a; padding:6px 12px; border-radius:6px; border:none; text-decoration:none; font-weight:600; font-size:13px; display:inline-block;">Edit</a>
<a href="questions.php?delete=<?= $q['id'] ?>" class="delete-link" onclick="return confirm('Are you sure you want to delete this question?')" style="background:#dc3545; color:white; padding:6px 12px; border-radius:6px; border:none; text-decoration:none; font-weight:600; font-size:13px; display:inline-block;">Delete</a>
</td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
<?php endif; ?>


</div>
</div>

</body>
</html>
