<?php
session_start();
require_once "../config/db.php";

/* ===== STUDENT AUTH CHECK ===== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$current_page = basename($_SERVER['PHP_SELF']);

/* ===== FLASH SUBMISSION CONFIRMATION ===== */
$submission_success = $_SESSION['submission_success'] ?? null;
unset($_SESSION['submission_success']);

/* ===== FETCH STUDENT RESULTS ===== */
$results = $conn->query("
    SELECT 
        r.id AS result_id,
        r.score AS correct_answers,
        r.tab_switch_count,
        e.id AS exam_id,
        e.title AS exam_title,
        e.marks_per_question,
        (
            SELECT COUNT(*) 
            FROM questions q 
            WHERE q.exam_id = e.id
        ) AS total_questions,
        (
            SELECT COUNT(*)
            FROM violations v
            WHERE v.user_id = r.user_id AND v.exam_id = r.exam_id
        ) AS recorded_violations
    FROM results r
    JOIN exams e ON r.exam_id = e.id
    WHERE r.user_id = $user_id
    ORDER BY r.id ASC
");
?>
<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>My Results</title>

<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/student_mobile.css">

</head>
<body>

<div class="wrapper">

    <!-- ===== STUDENT SIDEBAR ===== -->
    <?php include "sidebar.php"; ?>


    <!-- ===== CONTENT ===== -->
    <div class="content">

        <h1>My Results</h1>

        <?php if ($submission_success): ?>
        <div class="submission-confirmation" style="background:#edf7ed;border:1.5px solid #a5d6a7;border-radius:8px;padding:16px 20px;margin-bottom:24px;color:#1e4620;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size:16px;font-weight:bold;margin-bottom:6px;display:flex;align-items:center;gap:8px;">
                <span style="display:inline-block;width:22px;height:22px;line-height:22px;text-align:center;border-radius:50%;background:#2e7d32;color:#fff;font-size:13px;">✓</span>
                Exam submitted successfully.
            </div>
            <div style="font-size:15px;color:#2e7d32;">
                Tab-switch violations recorded: <strong><?= (int)$submission_success['violations'] ?></strong>.
            </div>
        </div>
        <?php endif; ?>

        <table>
            <tr>
                <th>SL No</th>
                <th>Exam</th>
                <th>Total Marks</th>
                <th>Marks Obtained</th>
                <th>Percentage</th>
                <th>Tab-Switch Violations</th>
                <th>Status</th>
            </tr>

            <?php
            $i = 1;
            if ($results && $results->num_rows > 0):
                while ($row = $results->fetch_assoc()):

                    $total_marks = $row['total_questions'] * $row['marks_per_question'];
                    $obtained_marks = $row['correct_answers'] * $row['marks_per_question'];

                    $percentage = $total_marks > 0
                        ? round(($obtained_marks / $total_marks) * 100, 2)
                        : 0;

                    $status = $percentage >= 40 ? "PASS" : "FAIL";
                    $tab_violations = max((int)($row['tab_switch_count'] ?? 0), (int)($row['recorded_violations'] ?? 0));
            ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['exam_title']) ?></td>
                <td><?= $total_marks ?></td>
                <td><?= $obtained_marks ?></td>
                <td><?= $percentage ?>%</td>
                <td style="font-weight:<?= $tab_violations > 0 ? 'bold' : 'normal' ?>;color:<?= $tab_violations > 0 ? '#b71c1c' : '#4b5563' ?>">
                    <?= $tab_violations ?>
                </td>
                <td style="font-weight:bold;color:<?= $status=='PASS' ? 'green' : 'red' ?>">
                    <?= $status ?>
                </td>
            </tr>
            <?php endwhile; else: ?>
            <tr>
                <td colspan="7" style="text-align:center;font-weight:bold">
                    No results available
                </td>
            </tr>
            <?php endif; ?>
        </table>

    </div>
</div>

</body>
</html>
