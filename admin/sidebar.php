<?php
$current_page = basename($_SERVER['PHP_SELF']);
$mode = $_GET['mode'] ?? '';
?>

<style>

/* Sidebar base */
.sidebar {
    width: 250px;
    height: 100vh;
    background: #2c3e50;
    color: white;
    position: fixed;
    top: 0;
    left: 0;
    transition: width 0.3s ease;
    overflow: hidden;
}

/* CLOSED SIDEBAR */
.sidebar.closed {
    width: 70px;
}

/* Header */
.sidebar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px;
}

/* Admin Panel text */
.sidebar-header h2 {
    font-size: 20px;
    margin: 0;
    white-space: nowrap;
}

/* Hide Admin Panel text when closed */
.sidebar.closed .sidebar-header h2 {
    display: none;
}

/* Hamburger */
.hamburger {
    font-size: 22px;
    cursor: pointer;
    padding: 8px 12px;
    background: rgba(255,255,255,0.1);
    border-radius: 5px;
}

/* Links */
.sidebar a {
    display: block;
    padding: 12px 20px;
    color: white;
    text-decoration: none;
    white-space: nowrap;
}

/* Hide ALL links when closed */
.sidebar.closed a {
    display: none;
}

/* Keep hamburger visible always */
.sidebar.closed .sidebar-header {
    justify-content: center;
}

/* Active link (your existing style will override if exists) */
.sidebar a.active {
    background: #2176ff;
    border-radius: 6px;
}

</style>


<div class="sidebar" id="sidebar">

    <div class="sidebar-header">
        <h2>Admin Panel</h2>
        <div class="hamburger" onclick="toggleSidebar()">☰</div>
    </div>

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


<script>

function toggleSidebar() {
    document.getElementById("sidebar").classList.toggle("closed");
}

</script>
