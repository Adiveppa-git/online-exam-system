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

.sidebar-header h2 {
    font-size: 20px;
    margin: 0;
    white-space: nowrap;
}

.sidebar.closed .sidebar-header h2 {
    display: none;
}

.hamburger {
    font-size: 22px;
    cursor: pointer;
    padding: 8px 12px;
    background: rgba(255,255,255,0.15);
    border-radius: 6px;
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
</style>

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">
        <h2>Student Panel</h2>
        <div class="hamburger" id="hamburger" onclick="toggleSidebar()">?</div>
    </div>

    <a href="../student/dashboard.php" class="<?= $current_page=='dashboard.php'?'active':'' ?>">
        Dashboard
    </a>

    <a href="../student/exams.php" class="<?= $current_page=='exams.php'?'active':'' ?>">
        Available Exams
    </a>

    <a href="../student/personalized_learning.php" class="<?= ($current_page=='personalized_learning.php'||$current_page=='practice_session.php')?'active':'' ?>">
        ?? Personalized Learning
    </a>

    <a href="../student/ai_performance.php" class="<?= $current_page=='ai_performance.php'?'active':'' ?>">
        ?? Performance Analytics
    </a>

    <a href="../student/study_assistant.php" class="<?= $current_page=='study_assistant.php'?'active':'' ?>">
        ?? AI Study Assistant
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
