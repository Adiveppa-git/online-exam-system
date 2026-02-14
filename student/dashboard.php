<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);
$user_id = $_SESSION['user_id'];

$user = $conn->query("SELECT name FROM users WHERE id=$user_id")->fetch_assoc();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Student Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="wrapper">

    <div class="sidebar">
        <h2>Student Panel</h2>

        <a href="dashboard.php" class="<?= $current_page=='dashboard.php'?'active':'' ?>">Dashboard</a>
        <a href="exams.php">Available Exams</a>
        <a href="result.php">My Results</a>

        <!-- ✅ NEW -->
        <a href="../auth/change_password.php">Change Password</a>

        <a href="../auth/logout.php">Logout</a>
    </div>

    <div class="content">
        <h1>Welcome, <?= htmlspecialchars($user['name']) ?> 👋</h1>
        <p>Online Examination System</p>
    </div>

</div>
</body>
</html>

