<?php
session_start();
require_once "../config/db.php";

/* ===== STUDENT AUTH ===== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$exam_id = (int)($_GET['exam_id'] ?? 0);

if (!$exam_id) die("Invalid Exam");

/* ===== FETCH EXAM ===== */
$exam = $conn->query("SELECT * FROM exams WHERE id=$exam_id")->fetch_assoc();
if (!$exam) die("Exam not found");

/* ===== FETCH QUESTIONS ===== */
$questions = [];
$q = $conn->query("SELECT * FROM questions WHERE exam_id=$exam_id ORDER BY id ASC");

while ($row = $q->fetch_assoc()) {
    $questions[] = $row;
}

if (count($questions) === 0) die("No questions added.");

$total_questions = count($questions);
$duration = $exam['duration'] * 60;
?>

<!DOCTYPE html>
<html>
<head>

<title>Attempt Exam</title>
<link rel="stylesheet" href="../assets/css/style.css">

<style>

body{
margin:0;
font-family:Arial;
}

.exam-wrapper{
display:flex;
}

.question-panel{
width:230px;
background:#1f2d3d;
color:#fff;
padding:20px;
height:100vh;
}

.q-btn{
width:42px;
height:42px;
border-radius:50%;
border:none;
margin:5px;
cursor:pointer;
font-weight:bold;
}

.not-visited{background:#e53935;color:#fff;}
.visited{background:#fbc02d;color:#000;}
.answered{background:#43a047;color:#fff;}
.active{background:#1e88e5 !important;color:#fff;}

.exam-content{
flex:1;
padding:45px 35px;
background:#f4f6f8;
}

.question-box{
background:#fff;
padding:30px;
border-radius:8px;
margin-top:40px;
}

.options label{
display:block;
margin:12px 0;
}

.actions{
margin-top:30px;
display:flex;
justify-content:space-between;
}

.nav-btn{
background:#1e88e5;
color:#fff;
border:none;
padding:10px 26px;
border-radius:5px;
cursor:pointer;
}

.submit-btn{
background:#e53935;
color:#fff;
padding:14px 32px;
border:none;
border-radius:5px;
font-size:16px;
cursor:pointer;
margin-top:35px;
}

.timer{
position:fixed;
top:20px;
right:30px;
color:red;
font-weight:bold;
font-size:20px;
}

.warning-box{
position:fixed;
top:60px;
right:30px;
background:#ffc107;
color:#000;
padding:12px 20px;
border-radius:6px;
font-weight:bold;
display:none;
z-index:9999;
}

</style>

</head>

<body>

<!-- AUDIO -->
<audio id="beepSound" preload="auto">
<source src="../assets/beep.mp3" type="audio/mpeg">
</audio>

<div class="timer" id="timer"></div>
<div class="warning-box" id="warningBox"></div>

<div class="exam-wrapper">

<!-- LEFT PANEL -->
<div class="question-panel">

<h3>Questions</h3>

<div style="margin-bottom:15px;font-size:13px">
<div><span style="color:#1e88e5">⬤</span> Current</div>
<div><span style="color:#43a047">⬤</span> Answered</div>
<div><span style="color:#fbc02d">⬤</span> Visited</div>
<div><span style="color:#e53935">⬤</span> Not Visited</div>
</div>

<?php for($i=0;$i<$total_questions;$i++): ?>
<button type="button"
class="q-btn <?= $i==0?'active':'not-visited' ?>"
id="nav<?= $i ?>"
onclick="showQuestion(<?= $i ?>)">
<?= $i+1 ?>
</button>
<?php endfor; ?>

</div>

<!-- RIGHT PANEL -->
<div class="exam-content">

<form id="examForm" method="post" action="exam_summary.php">

<input type="hidden" name="exam_id" value="<?= $exam_id ?>">

<?php foreach($questions as $index=>$q): ?>

<div class="question-box"
id="q<?= $index ?>"
style="<?= $index!==0?'display:none':'' ?>">

<h2>Q<?= $index+1 ?>. <?= htmlspecialchars($q['question']) ?></h2>

<div class="options">

<?php foreach(['A','B','C','D'] as $opt): ?>

<label>
<input type="radio"
name="answer[<?= $q['id'] ?>]"
value="<?= $opt ?>"
onchange="markAnswered(<?= $index ?>)">
<?= htmlspecialchars($q['option_'.strtolower($opt)]) ?>
</label>

<?php endforeach; ?>

</div>

<div class="actions">

<?php if($index>0): ?>
<button type="button"
class="nav-btn"
onclick="showQuestion(<?= $index-1 ?>)">Previous</button>
<?php else: ?>
<span></span>
<?php endif; ?>

<?php if($index<$total_questions-1): ?>
<button type="button"
class="nav-btn"
onclick="showQuestion(<?= $index+1 ?>)">Next</button>
<?php endif; ?>

</div>

</div>

<?php endforeach; ?>

<button class="submit-btn" type="submit">Submit Exam</button>

</form>

</div>
</div>

<script>

/* UNLOCK AUDIO */
let audioUnlocked=false;

function unlockAudio(){

if(audioUnlocked) return;

let beep=document.getElementById("beepSound");

beep.play().then(()=>{
beep.pause();
beep.currentTime=0;
audioUnlocked=true;
}).catch(()=>{});

}

document.addEventListener("click",unlockAudio);
document.addEventListener("keydown",unlockAudio);


/* QUESTION NAV */
let current=0;

function showQuestion(i){

let currentBtn=document.getElementById("nav"+current);

currentBtn.classList.remove("active");

if(!currentBtn.classList.contains("answered")){
currentBtn.classList.remove("not-visited");
currentBtn.classList.add("visited");
}

document.getElementById("q"+current).style.display="none";
document.getElementById("q"+i).style.display="block";

let newBtn=document.getElementById("nav"+i);

newBtn.classList.remove("not-visited","visited");
newBtn.classList.add("active");

current=i;

}

function markAnswered(i){

let btn=document.getElementById("nav"+i);

btn.classList.remove("not-visited","visited");
btn.classList.add("answered");

}


/* PERFECT 0.2 SECOND BEEP TIMER */

let time = <?= $duration ?>;
let submitted=false;

const timer=document.getElementById("timer");

const interval=setInterval(()=>{

let m=Math.floor(time/60);
let s=time%60;

timer.innerText="Time Left: "+m+":"+(s<10?"0":"")+s;

/* play beep last 15 sec for 0.2 sec only */
if(time<=15 && time>0){

let beep=new Audio("../assets/beep.mp3");

beep.volume=1;

beep.play().then(()=>{

setTimeout(()=>{
beep.pause();
beep.currentTime=0;
},200);

}).catch(()=>{});

}

/* auto submit */
if(time<=0 && !submitted){

submitted=true;

document.getElementById("examForm").submit();

clearInterval(interval);

}

time--;

},1000);


/* TAB VIOLATION WARNING */

let warningCount=0;
let maxWarnings=3;

const warningBox=document.getElementById("warningBox");

document.addEventListener("visibilitychange",()=>{

if(submitted) return;

if(document.hidden){

warningCount++;

fetch("report_violation.php",{
method:"POST",
headers:{"Content-Type":"application/x-www-form-urlencoded"},
body:"exam_id=<?= $exam_id ?>"
});

}else{

let remaining=maxWarnings-warningCount;

if(warningCount<=maxWarnings){

warningBox.innerText="WARNING: Tab switching detected. Warnings left: "+remaining;

warningBox.style.display="block";

setTimeout(()=>{
warningBox.style.display="none";
},3000);

}

if(warningCount>maxWarnings){

submitted=true;
document.getElementById("examForm").submit();

}

}

});

</script>

</body>
</html>
