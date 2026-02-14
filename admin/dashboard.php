<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="wrapper">

    <!-- ✅ COMMON SIDEBAR (DO NOT CHANGE) -->
    <?php include "sidebar.php"; ?>

    <!-- ✅ MAIN CONTENT -->
    <div class="content">
        <h1>Welcome Admin</h1>
        <p>Use the sidebar to manage the Online Examination System.</p>

        <!-- Optional dashboard cards (future ready) -->
        <!--
        <div class="dashboard-cards">
            <div class="card">Total Exams</div>
            <div class="card">Total Questions</div>
            <div class="card">Total Students</div>
            <div class="card">Violations</div>
        </div>
        -->
    </div>

</div>

</body>
</html>
