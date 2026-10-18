<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../config/db.php";

/* ================= ADMIN AUTH ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    if (!headers_sent()) {
        header("Location: ../index.php");
    }
    exit;
}

/* ================= MODE ================= */
$mode = $_GET['mode'] ?? 'manage';

/* ================= CSRF TOKEN GENERATION ================= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* ================= FLASH MESSAGE ================= */
$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

/* ================= REJECT DESTRUCTIVE GET ACTIONS ================= */
if (isset($_GET['delete'])) {
    $_SESSION['error'] = "Question deletion must use POST with a valid CSRF security token.";
    if (!headers_sent()) {
        header("Location: questions.php?mode=manage");
    }
    exit;
}

/* ================= POST ACTIONS (WITH CSRF VALIDATION) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $post_token)) {
        $_SESSION['error'] = "Invalid CSRF security token.";
        if (!headers_sent()) {
            header("Location: questions.php?mode=" . urlencode($mode));
        }
        exit;
    }

    /* DELETE QUESTION */
    if (isset($_POST['delete_question'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM questions WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $_SESSION['success'] = "Question deleted successfully";
        }
        $redirect_to = "questions.php?mode=manage";
        if (!empty($_POST['return_subject'])) {
            $redirect_to .= "&subject=" . urlencode($_POST['return_subject']);
        }
        if (!empty($_POST['return_page']) && (int)$_POST['return_page'] > 1) {
            $redirect_to .= "&page=" . (int)$_POST['return_page'];
        }
        if (!headers_sent()) {
            header("Location: " . $redirect_to);
        }
        exit;
    }

    /* ADD QUESTION */
    if (isset($_POST['add_question'])) {
        $exam_id  = (int)$_POST['exam_id'];
        $question = clean_question_title(trim($_POST['question']));
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

        if (!headers_sent()) {
            header("Location: questions.php?mode=add");
        }
        exit;
    }

    /* UPDATE QUESTION */
    if (isset($_POST['update_question'])) {
        $id = (int)$_POST['id'];
        $cleanQuestion = clean_question_title(trim($_POST['question']));

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
            $cleanQuestion,
            $_POST['option_a'],
            $_POST['option_b'],
            $_POST['option_c'],
            $_POST['option_d'],
            $_POST['correct_option'],
            $id
        );

        $stmt->execute();

        $_SESSION['success'] = "Question updated successfully";

        if (!headers_sent()) {
            header("Location: questions.php?mode=manage");
        }
        exit;
    }
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

/* ================= QUESTIONS & FILTER LOGIC ================= */
$questions = null;
$total_questions_count = 0;
$filtered_count = 0;
$available_subjects = [];
$selected_subject = '';
$limit = 10;
$total_pages = 0;
$page = 1;
$offset = 0;

if (!function_exists('get_questions_page_url')) {
    function get_questions_page_url($page, $subject = '') {
        $params = ['mode' => 'manage'];
        if ((int)$page > 1) {
            $params['page'] = (int)$page;
        }
        if ($subject !== '' && strtolower($subject) !== 'all') {
            $params['subject'] = $subject;
        }
        return 'questions.php?' . http_build_query($params);
    }
}

if ($mode === 'manage') {
    // 1. Get raw filter parameters
    $subject_param = isset($_GET['subject']) ? trim($_GET['subject']) : '';
    if ($subject_param !== '' && strtolower($subject_param) !== 'all') {
        $selected_subject = $subject_param;
    } else {
        $selected_subject = '';
    }

    // 2. Total active questions in the question bank (unfiltered count)
    $total_bank_res = $conn->query("
        SELECT COUNT(*) AS total
        FROM questions q
        LEFT JOIN exams e ON q.exam_id = e.id
    ");
    $total_questions_count = $total_bank_res ? (int)$total_bank_res->fetch_assoc()['total'] : 0;

    // 3. Dynamic distinct subjects for dropdown from active questions
    $subjects_res = $conn->query("
        SELECT DISTINCT q.subject
        FROM questions q
        WHERE q.subject IS NOT NULL AND TRIM(q.subject) != ''
        ORDER BY q.subject ASC
    ");
    if ($subjects_res) {
        while ($sRow = $subjects_res->fetch_assoc()) {
            $cleanSubj = trim($sRow['subject']);
            if ($cleanSubj !== '' && !in_array($cleanSubj, $available_subjects, true)) {
                $available_subjects[] = $cleanSubj;
            }
        }
    }

    // 4. Calculate filtered count using prepared statements
    if ($selected_subject !== '') {
        $count_stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM questions q
            LEFT JOIN exams e ON q.exam_id = e.id
            WHERE q.subject = ?
        ");
        $count_stmt->bind_param("s", $selected_subject);
        $count_stmt->execute();
        $count_res = $count_stmt->get_result();
        $filtered_count = $count_res ? (int)$count_res->fetch_assoc()['total'] : 0;
        $count_stmt->close();
    } else {
        $filtered_count = $total_questions_count;
    }

    // 5. Pagination calculation
    $raw_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $total_pages = ($filtered_count > 0) ? (int)ceil($filtered_count / $limit) : 0;
    if ($total_pages > 0) {
        $page = max(1, min($total_pages, $raw_page));
        $offset = (int)(($page - 1) * $limit);
    } else {
        $page = 1;
        $offset = 0;
    }

    // 6. Fetch paginated questions with prepared statement
    if ($selected_subject !== '') {
        $fetch_stmt = $conn->prepare("
            SELECT q.*, COALESCE(e.title, 'General Exam') AS exam_title
            FROM questions q
            LEFT JOIN exams e ON q.exam_id = e.id
            WHERE q.subject = ?
            ORDER BY q.id ASC
            LIMIT ? OFFSET ?
        ");
        $fetch_stmt->bind_param("sii", $selected_subject, $limit, $offset);
        $fetch_stmt->execute();
        $questions = $fetch_stmt->get_result();
    } else {
        $fetch_stmt = $conn->prepare("
            SELECT q.*, COALESCE(e.title, 'General Exam') AS exam_title
            FROM questions q
            LEFT JOIN exams e ON q.exam_id = e.id
            ORDER BY q.id ASC
            LIMIT ? OFFSET ?
        ");
        $fetch_stmt->bind_param("ii", $limit, $offset);
        $fetch_stmt->execute();
        $questions = $fetch_stmt->get_result();
    }
}
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

.questions-toolbar {
    background: #ffffff;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    border-left: 5px solid #1976D2;
}

.questions-summary {
    display: flex;
    align-items: baseline;
    gap: 10px;
    flex-wrap: wrap;
}

.total-q-badge {
    font-size: 20px;
    font-weight: 800;
    color: #0f172a;
}

.total-q-badge span {
    color: #1976D2;
}

.questions-filter-form {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    flex-wrap: wrap;
}

.subject-select {
    height: 40px;
    padding: 6px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 14px;
    background: #ffffff;
    color: #0f172a;
    min-width: 170px;
    cursor: pointer;
}

.subject-select:focus {
    border-color: #1976D2;
    outline: none;
    box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.15);
}

.btn-reset-filter {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    height: 38px;
    padding: 0 14px;
    background: #fee2e2;
    color: #dc2626;
    border-radius: 6px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid #fca5a5;
    transition: all 0.2s;
}

.btn-reset-filter:hover {
    background: #fecaca;
    color: #b91c1c;
}

.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 20px;
    flex-wrap: wrap;
    gap: 12px;
    padding-top: 15px;
    border-top: 1px solid #f1f5f9;
}

.pagination-links {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: wrap;
}

.pagination-links a, .pagination-links span {
    padding: 7px 12px;
    border-radius: 5px;
    font-size: 13px;
    text-decoration: none;
}

.pagination-links a {
    background: #f1f5f9;
    color: #1e293b;
    border: 1px solid #e2e8f0;
    font-weight: 500;
}

.pagination-links a:hover {
    background: #e2e8f0;
}

.pagination-links .current-page {
    background: #1976D2;
    color: #ffffff;
    font-weight: 700;
    border: 1px solid #1976D2;
}

.pagination-links .page-ellipsis {
    color: #94a3b8;
    padding: 7px 4px;
}

@media (max-width: 768px) {
    .questions-toolbar {
        flex-direction: column;
        align-items: stretch !important;
        gap: 14px;
    }
    .questions-filter-form {
        width: 100%;
        justify-content: space-between;
    }
    .subject-select {
        flex: 1;
        min-width: 0;
    }
    .pagination-container {
        flex-direction: column;
        align-items: center;
        gap: 14px;
    }
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
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

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
<textarea name="question" required><?= htmlspecialchars(clean_question_title($edit['question']??'')) ?></textarea>
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

<!-- SUMMARY & FILTER SECTION -->
<div class="questions-toolbar">
    <div class="questions-summary">
        <div class="total-q-badge" id="total-questions-count">
            Total Questions: <span><?= $filtered_count ?></span>
        </div>
        <?php if ($selected_subject !== ''): ?>
            <span style="font-size: 13px; font-weight: 600; background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 12px;">
                Filtered by: <?= htmlspecialchars($selected_subject) ?> (<?= $filtered_count ?> of <?= $total_questions_count ?> total)
            </span>
        <?php endif; ?>
    </div>

    <div class="questions-filter-wrapper">
        <form method="GET" action="questions.php" class="questions-filter-form" id="subjectFilterForm">
            <input type="hidden" name="mode" value="manage">
            <label for="filter-subject" style="font-weight: 600; color: #334155; font-size: 14px; margin: 0; white-space: nowrap;">
                Filter by Subject:
            </label>
            <select name="subject" id="filter-subject" class="subject-select" onchange="this.form.submit()">
                <option value="" <?= ($selected_subject === '') ? 'selected' : '' ?>>All Subjects</option>
                <?php foreach ($available_subjects as $subjItem): ?>
                    <option value="<?= htmlspecialchars($subjItem) ?>" <?= ($selected_subject === $subjItem) ? 'selected' : '' ?>><?= htmlspecialchars($subjItem) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($selected_subject !== ''): ?>
                <a href="questions.php?mode=manage" class="btn-reset-filter" title="Reset filter to All Subjects">
                    ✕ Reset
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

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
<?php if ($filtered_count === 0): ?>
<tr>
<td colspan="5" style="padding:32px 16px; text-align:center; color:#64748b; font-size:14px;">
    <?= ($selected_subject !== '') ? 'No questions found for subject "<strong>' . htmlspecialchars($selected_subject) . '</strong>".' : 'No questions found in the question bank.' ?>
    <?php if ($selected_subject !== ''): ?>
        <br><a href="questions.php?mode=manage" style="color:#0d6efd; font-weight:600; display:inline-block; margin-top:8px;">Reset to All Subjects</a>
    <?php endif; ?>
</td>
</tr>
<?php else: ?>
<?php $i = $offset + 1; while($questions && $q=$questions->fetch_assoc()): ?>
<tr>
<td style="padding:12px; border-bottom:1px solid #e2e8f0; vertical-align:middle;"><?= $i++ ?></td>
<td style="padding:12px; border-bottom:1px solid #e2e8f0; vertical-align:middle; font-weight:600;"><?= htmlspecialchars($q['exam_title']) ?></td>
<td style="padding:12px; border-bottom:1px solid #e2e8f0; vertical-align:middle;"><?= htmlspecialchars(clean_question_title($q['question'])) ?></td>
<td style="padding:12px; border-bottom:1px solid #e2e8f0; vertical-align:middle; text-align:center;"><span style="background:#e0e7ff; color:#3730a3; padding:4px 10px; border-radius:12px; font-weight:bold;"><?= htmlspecialchars($q['correct_option']) ?></span></td>
<td style="padding:12px; border-bottom:1px solid #e2e8f0; vertical-align:middle; text-align:center;" class="action-btns">
<a href="questions.php?mode=edit&id=<?= $q['id'] ?>" class="edit-link" style="background:#22d3ee; color:#0f172a; padding:6px 12px; border-radius:6px; border:none; text-decoration:none; font-weight:600; font-size:13px; display:inline-block; vertical-align:middle;">Edit</a>
<form method="post" action="questions.php?mode=manage" style="display:inline-block; margin:0; padding:0; vertical-align:middle;">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
<input type="hidden" name="id" value="<?= $q['id'] ?>">
<?php if ($selected_subject !== ''): ?>
<input type="hidden" name="return_subject" value="<?= htmlspecialchars($selected_subject) ?>">
<?php endif; ?>
<?php if ($page > 1): ?>
<input type="hidden" name="return_page" value="<?= (int)$page ?>">
<?php endif; ?>
<button type="submit" name="delete_question" class="delete-link" style="background:#dc3545; color:white; padding:6px 12px; border-radius:6px; border:none; text-decoration:none; font-weight:600; font-size:13px; display:inline-block; cursor:pointer;">Delete</button>
</form>
</td>
</tr>
<?php endwhile; ?>
<?php endif; ?>
</tbody>
</table>

<!-- PAGINATION CONTROLS -->
<?php if ($total_pages > 1 && $filtered_count > 0): ?>
<div class="pagination-container">
    <div style="color: #64748b; font-size: 14px;">
        Showing <strong><?= $offset + 1 ?></strong> to <strong><?= min($offset + $limit, $filtered_count) ?></strong> of <strong><?= $filtered_count ?></strong> questions
    </div>
    <div class="pagination-links">
        <?php if ($page > 1): ?>
            <a href="<?= htmlspecialchars(get_questions_page_url($page - 1, $selected_subject)) ?>">&laquo; Prev</a>
        <?php endif; ?>

        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <?php if ($p === $page): ?>
                <span class="current-page"><?= $p ?></span>
            <?php elseif ($p === 1 || $p === $total_pages || ($p >= $page - 2 && $p <= $page + 2)): ?>
                <a href="<?= htmlspecialchars(get_questions_page_url($p, $selected_subject)) ?>"><?= $p ?></a>
            <?php elseif ($p === $page - 3 || $p === $page + 3): ?>
                <span class="page-ellipsis">...</span>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
            <a href="<?= htmlspecialchars(get_questions_page_url($page + 1, $selected_subject)) ?>">Next &raquo;</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div>
<?php endif; ?>


</div>
</div>

</body>
</html>
