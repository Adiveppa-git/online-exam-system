<?php 
$current_page = basename($_SERVER['PHP_SELF']); 
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
    z-index: 1000; /* IMPORTANT FIX */
}

/* Closed sidebar */
.sidebar.closed {
    width: 70px;
}

/* Header */
.sidebar-header {
    display: flex;
    align-items: center;
    justify-content: flex-start; /* keep them together */
    gap: 15px; /* THIS creates proper gap */
    padding: 15px;
}


/* Student Panel text */
.sidebar-header h2 {
    font-size: 20px;
    margin: 0;
    white-space: nowrap;
}

/* Hide panel text when closed */
.sidebar.closed .sidebar-header h2 {
    display: none;
}

/* Hamburger */
.hamburger {
    font-size: 22px;
    cursor: pointer;
    padding: 8px 12px;
    background: rgba(255,255,255,0.15);
    border-radius: 6px;
    margin-left: auto; /* pushes it slightly right cleanly */
}


/* Links */
.sidebar a {
    display: block;
    padding: 12px 20px;
    color: white;
    text-decoration: none;
    white-space: nowrap;
}

/* Hide links when closed */
.sidebar.closed a {
    display: none;
}

/* Center hamburger when closed */
.sidebar.closed .sidebar-header {
    justify-content: center;
}

/* Active link */
.sidebar a.active {
    background: #2176ff;
    border-radius: 6px;
}
</style>

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">
        <h2>Student Panel</h2>
        <div class="hamburger" onclick="toggleSidebar()">☰</div>
    </div>

    <a href="../student/dashboard.php" class="<?= $current_page=='dashboard.php'?'active':'' ?>">
        Dashboard
    </a>

    <a href="../student/exams.php" class="<?= $current_page=='exams.php'?'active':'' ?>">
        Available Exams
    </a>

    <a href="../student/result.php" class="<?= $current_page=='result.php'?'active':'' ?>">
        My Results
    </a>

    <a href="../auth/change_password.php" class="<?= $current_page=='change_password.php'?'active':'' ?>">
        Change Password
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
