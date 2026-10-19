<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../config/db.php";

/* ADMIN AUTH */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    if (!headers_sent()) {
        header("Location: ../index.php");
    }
    exit;
}

$message = "";

/* CSRF TOKEN GENERATION */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

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

/* REJECT DESTRUCTIVE GET ACTIONS */
if (isset($_GET['delete']) || isset($_GET['reassign'])) {
    $message = "Destructive actions must use POST with a valid CSRF security token.";
}

/* POST ACTIONS (WITH CSRF VALIDATION) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $post_token)) {
        $message = "Invalid CSRF security token.";
    } else {
        /* DELETE EXAM */
        if (isset($_POST['delete_exam'])) {
            $exam_id = (int)($_POST['exam_id'] ?? 0);
            if ($exam_id > 0) {
                // Automatically clean up associated course materials, vector embeddings, chunks, and storage files
                require_once __DIR__ . "/../config/study_material_service.php";
                StudyMaterialService::deleteMaterialsForExam($conn, $exam_id);

                $conn->query("DELETE FROM student_answers WHERE exam_id=$exam_id");
                $conn->query("DELETE FROM results WHERE exam_id=$exam_id");
                $conn->query("DELETE FROM violations WHERE exam_id=$exam_id");
                $conn->query("DELETE FROM violation_report WHERE exam_id=$exam_id");
                $conn->query("DELETE FROM exam_violations WHERE exam_id=$exam_id");
                $conn->query("DELETE FROM questions WHERE exam_id=$exam_id");
                $conn->query("DELETE FROM ai_generated_questions WHERE exam_id=$exam_id");
                $conn->query("DELETE FROM ai_generation_requests WHERE exam_id=$exam_id");
                $conn->query("DELETE FROM exams WHERE id=$exam_id");

                if (!headers_sent()) {
                    header("Location: exams.php?msg=deleted");
                }
                exit;
            }
        }

        /* REASSIGN EXAM */
        if (isset($_POST['reassign_exam'])) {
            $exam_id = (int)($_POST['exam_id'] ?? 0);
            if ($exam_id > 0) {
                $conn->query("DELETE FROM student_answers WHERE exam_id=$exam_id");
                $conn->query("DELETE FROM results WHERE exam_id=$exam_id");
                $conn->query("DELETE FROM violations WHERE exam_id=$exam_id");
                $conn->query("DELETE FROM violation_report WHERE exam_id=$exam_id");
                $conn->query("DELETE FROM exam_violations WHERE exam_id=$exam_id");

                if (!headers_sent()) {
                    header("Location: exams.php?msg=reassigned");
                }
                exit;
            }
        }

        /* ADD EXAM */
        if (isset($_POST['add_exam'])) {
            $title = trim($_POST['title']);
            $duration = (int)$_POST['duration'];
            $marks = (int)$_POST['marks'];

            $check = $conn->prepare("SELECT id FROM exams WHERE LOWER(title)=LOWER(?)");
            $check->bind_param("s", $title);
            $check->execute();
            $res = $check->get_result();

            if ($res->num_rows > 0) {
                $message = "Exam already exists";
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO exams(title,duration,marks_per_question,total_marks)
                     VALUES(?,?,?,0)"
                );
                $stmt->bind_param("sii", $title, $duration, $marks);
                if ($stmt->execute()) {
                    if (!headers_sent()) {
                        header("Location: exams.php?msg=added");
                    }
                    exit;
                } else {
                    $message = "Failed to add exam: " . ($stmt->error ?: $conn->error);
                }
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
            if ($stmt->execute()) {
                if (!headers_sent()) {
                    header("Location: exams.php?msg=updated");
                }
                exit;
            } else {
                $message = "Failed to update exam: " . ($stmt->error ?: $conn->error);
            }
        }
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

.inline-form{
display:inline-block;
margin:0;
padding:0;
}

.btn-action-link{
background:none;
border:none;
padding:0;
font:inherit;
cursor:pointer;
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
    <?php if($_GET['msg'] === 'deleted'): ?>
        <p class="success">Exam deleted successfully</p>
    <?php elseif($_GET['msg'] === 'reassigned'): ?>
        <p class="success">Exam reassigned successfully</p>
    <?php elseif($_GET['msg'] === 'added'): ?>
        <p class="success">Exam created successfully</p>
    <?php elseif($_GET['msg'] === 'updated'): ?>
        <p class="success">Exam updated successfully</p>
    <?php endif; ?>
<?php endif; ?>

<?php if($message): ?>
<p class="error"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<form method="post" class="exam-form">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

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

<form method="post" action="exams.php" class="inline-form">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
<input type="hidden" name="exam_id" value="<?= $row['id'] ?>">
<button type="submit" name="delete_exam" class="btn-action-link delete-link">Delete</button>
</form>

|

<form method="post" action="exams.php" class="inline-form">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
<input type="hidden" name="exam_id" value="<?= $row['id'] ?>">
<button type="submit" name="reassign_exam" class="btn-action-link reassign-link">Reassign Exam</button>
</form>

</td>

</tr>

<?php endwhile; ?>

</table>

</div>
</div>

</body>
</html>
