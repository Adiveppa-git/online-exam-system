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
    overflow-y: auto;
    z-index: 1000;
}

.sidebar.closed {
    width: 60px;
    background: transparent;
}

.sidebar-header {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 15px;
    padding: 15px;
}

.sidebar.closed .sidebar-header h2 {
    display: none;
}
.sidebar-header h2 {
    font-size: 20px;
    margin: 0;
    white-space: nowrap;
}

.hamburger {
    font-size: 22px;
    cursor: pointer;
    padding: 8px 12px;
    background: rgba(255,255,255,0.1);
    border-radius: 4px;
    margin-left: auto;
}

.sidebar a {
    display: block;
    padding: 12px 20px;
    color: white;
    text-decoration: none;
    white-space: nowrap;
}

.sidebar.closed a {
    display: none;
}

.sidebar.closed .sidebar-header {
    justify-content: center;
}

.sidebar a.active {
    background: #2176ff;
    border-radius: 6px;
}
.sidebar.closed .hamburger {
    margin: auto;
}

.sidebar-section-title {
    font-size: 11px;
    text-transform: uppercase;
    color: #95a5a6;
    padding: 10px 20px 5px 20px;
    letter-spacing: 1px;
}
.sidebar.closed .sidebar-section-title {
    display: none;
}
</style>

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">
        <h2>Admin Panel</h2>
       <div class="hamburger" id="hamburger" onclick="toggleSidebar()">?</div>
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

    <div class="sidebar-section-title">AI System</div>

    <a href="ai_question_generator.php"
       class="<?= $current_page === 'ai_question_generator.php' ? 'active' : '' ?>">
        ? AI Question Gen
    </a>

    <a href="review_ai_questions.php"
       class="<?= $current_page === 'review_ai_questions.php' ? 'active' : '' ?>">
        ?? Review AI Questions
    </a>

    <a href="ai_difficulty_analytics.php"
       class="<?= $current_page === 'ai_difficulty_analytics.php' ? 'active' : '' ?>">
        ?? ML Difficulty Analytics
    </a>

    <a href="manage_course_materials.php"
       class="<?= $current_page === 'manage_course_materials.php' ? 'active' : '' ?>">
        ?? Course Materials (RAG)
    </a>

    <div class="sidebar-section-title">Reports</div>

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
const sidebar = document.getElementById("sidebar");
const hamburger = document.getElementById("hamburger");

document.addEventListener("DOMContentLoaded", function(){
    const state = localStorage.getItem("sidebar-state");
    if(state === "closed"){
        sidebar.classList.add("closed");
        if(hamburger) hamburger.style.color="black";
    }
});

function toggleSidebar(){
    sidebar.classList.toggle("closed");
    if(sidebar.classList.contains("closed")){
        if(hamburger) hamburger.style.color="black";
        localStorage.setItem("sidebar-state","closed");
    }else{
        if(hamburger) hamburger.style.color="white";
        localStorage.setItem("sidebar-state","open");
    }
}

document.querySelectorAll("#sidebar a").forEach(function(link){
    link.addEventListener("click",function(){
        sidebar.classList.add("closed");
        if(hamburger) hamburger.style.color="black";
        localStorage.setItem("sidebar-state","closed");
    });
});
</script>
