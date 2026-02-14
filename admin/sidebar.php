<?php
$current_page = basename($_SERVER['PHP_SELF']);
$mode = $_GET['mode'] ?? '';
?>

<div class="sidebar">
    <h2>Admin Panel</h2>

    <a href="dashboard.php"
       class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
        Dashboard
    </a>

    <a href="exams.php"
       class="<?= $current_page === 'exams.php' ? 'active' : '' ?>">
        Manage Exams
    </a>

    <a href="questions.php?mode=add"
       class="<?= ($current_page === 'questions.php' && $mode === 'add') ? 'active' : '' ?>">
        Add Questions
    </a>

    <a href="questions.php?mode=manage"
       class="<?= ($current_page === 'questions.php' && $mode === 'manage') ? 'active' : '' ?>">
        Manage Questions
    </a>

    <a href="results.php"
       class="<?= $current_page === 'results.php' ? 'active' : '' ?>">
        View Results
    </a>

    <a href="violation_report.php"
       class="<?= $current_page === 'violation_report.php' ? 'active' : '' ?>">
        Violation Report
    </a>

    <a href="../auth/logout.php">
        Logout
    </a>
</div>
