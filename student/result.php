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

/* ===== FETCH STUDENT RESULTS ===== */
$results = $conn->query("
    SELECT 
        r.score AS correct_answers,
        e.title AS exam_title,
        e.marks_per_question,
        (
            SELECT COUNT(*) 
            FROM questions q 
            WHERE q.exam_id = e.id
        ) AS total_questions
    FROM results r
    JOIN exams e ON r.exam_id = e.id
    WHERE r.user_id = $user_id
    ORDER BY r.id ASC
");
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Results</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="wrapper">

    <!-- ===== STUDENT SIDEBAR ===== -->
    <?php include "sidebar.php"; ?>


    <!-- ===== CONTENT ===== -->
    <div class="content">

        <h1>My Results</h1>

        <table>
            <tr>
                <th>SL No</th>
                <th>Exam</th>
                <th>Total Marks</th>
                <th>Marks Obtained</th>
                <th>Percentage</th>
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
            ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['exam_title']) ?></td>
                <td><?= $total_marks ?></td>
                <td><?= $obtained_marks ?></td>
                <td><?= $percentage ?>%</td>
                <td style="font-weight:bold;color:<?= $status=='PASS' ? 'green' : 'red' ?>">
                    <?= $status ?>
                </td>
            </tr>
            <?php endwhile; else: ?>
            <tr>
                <td colspan="6" style="text-align:center;font-weight:bold">
                    No results available
                </td>
            </tr>
            <?php endif; ?>
        </table>

    </div>
</div>

</body>
</html>
