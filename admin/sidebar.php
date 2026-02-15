<?php
$current_page = basename($_SERVER['PHP_SELF']);
$mode = $_GET['mode'] ?? '';
?>

<style>

/* ================= SIDEBAR BASE ================= */

.sidebar {
    width: 250px;
    height: 100vh;
    background: #2c3e50;
    color: white;
    position: fixed;
    top: 0;
    left: 0;
    transition: all 0.3s ease;
    overflow-x: hidden;
    z-index: 2000;
}

/* CLOSED STATE (DESKTOP COLLAPSE) */
.sidebar.closed {
    width: 70px;
}

/* MOBILE HIDDEN STATE */
@media (max-width: 768px) {

    .sidebar {
        transform: translateX(-100%);
    }

    .sidebar.active {
        transform: translateX(0);
    }

}

/* ================= HEADER ================= */

.sidebar-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px;
}

.sidebar-header h2 {
    font-size: 20px;
    margin: 0;
    white-space: nowrap;
}

/* Hide text when collapsed */
.sidebar.closed .sidebar-header h2 {
    display: none;
}

/* ================= HAMBURGER ================= */

.hamburger {
    font-size: 22px;
    cursor: pointer;
    padding: 8px 12px;
    background: rgba(255,255,255,0.15);
    border-radius: 6px;
    margin-left: auto;
}

/* ================= LINKS ================= */

.sidebar a {
    display: block;
    padding: 12px 20px;
    color: white;
    text-decoration: none;
    white-space: nowrap;
}

.sidebar a:hover {
    background: #1b2838;
}

.sidebar.closed a {
    display: none;
}

.sidebar a.active {
    background: #2176ff;
    border-radius: 6px;
}

/* ================= MOBILE TOGGLE BUTTON ================= */

.mobile-toggle {
    display: none;
    position: fixed;
    top: 15px;
    left: 15px;
    font-size: 22px;
    background: #2c3e50;
    color: white;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    z-index: 3000;
}

@media (max-width: 768px) {

    .mobile-toggle {
        display: block;
    }

}

</style>


<!-- MOBILE TOGGLE BUTTON -->
<div class="mobile-toggle" onclick="toggleSidebar()">☰</div>


<!-- SIDEBAR -->
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


<!-- STEP 1 FIX: JAVASCRIPT MUST BE INSIDE SCRIPT TAG -->
<script>

function toggleSidebar()
{
    const sidebar = document.getElementById("sidebar");

    if(window.innerWidth <= 768)
    {
        sidebar.classList.toggle("active");
    }
    else
    {
        sidebar.classList.toggle("closed");
    }
}

</script>
