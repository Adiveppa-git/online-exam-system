<?php
session_start();
require_once "../config/db.php";

/* ===== STUDENT AUTH ===== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$exam_id = (int)($_GET['exam_id'] ?? 0);

if (!$exam_id) die("Invalid Exam");

/* ===== PREVENT ATTEMPT IF ALREADY COMPLETED ===== */
$chkRes = $conn->prepare("SELECT id FROM results WHERE user_id = ? AND exam_id = ?");
$chkRes->bind_param("ii", $user_id, $exam_id);
$chkRes->execute();
if ($chkRes->get_result()->num_rows > 0) {
    header("Location: result.php");
    exit;
}

/* ===== FETCH EXAM ===== */
$stmtExam = $conn->prepare("SELECT * FROM exams WHERE id = ?");
$stmtExam->bind_param("i", $exam_id);
$stmtExam->execute();
$exam = $stmtExam->get_result()->fetch_assoc();
if (!$exam) die("Exam not found");

/* ===== FETCH QUESTIONS ===== */
$questions = [];
$stmtQ = $conn->prepare("SELECT * FROM questions WHERE exam_id = ? ORDER BY id ASC");
$stmtQ->bind_param("i", $exam_id);
$stmtQ->execute();
$resQ = $stmtQ->get_result();

while ($row = $resQ->fetch_assoc()) {
    $questions[] = $row;
}

if (count($questions) === 0) die("No questions added.");

/* ===== FETCH SAVED ANSWERS FOR THIS STUDENT & EXAM ===== */
$saved_answers = [];
$stmtAns = $conn->prepare("SELECT question_id, answer FROM student_answers WHERE student_id = ? AND exam_id = ?");
$stmtAns->bind_param("ii", $user_id, $exam_id);
$stmtAns->execute();
$resAns = $stmtAns->get_result();
while ($rowAns = $resAns->fetch_assoc()) {
    $saved_answers[(int)$rowAns['question_id']] = $rowAns['answer'];
}

$total_questions = count($questions);
$duration = $exam['duration'] * 60;
?>

<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Attempt Exam</title>

<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/attempt_exam.css">

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

<?php for($i=0;$i<$total_questions;$i++): 
    $qid = (int)$questions[$i]['id'];
    $is_answered = isset($saved_answers[$qid]) && $saved_answers[$qid] !== '';
    $classes = [];
    if ($i === 0) {
        $classes[] = 'active';
    }
    if ($is_answered) {
        $classes[] = 'answered';
    } else if ($i !== 0) {
        $classes[] = 'not-visited';
    }
    $class_attr = implode(' ', $classes);
?>
<button type="button"
class="q-btn <?= $class_attr ?>"
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

<?php foreach(['A','B','C','D'] as $opt): 
    $qid = (int)$q['id'];
    $isChecked = (isset($saved_answers[$qid]) && $saved_answers[$qid] === $opt) ? 'checked' : '';
?>

<label>
<input type="radio"
name="answer[<?= $q['id'] ?>]"
value="<?= $opt ?>"
<?= $isChecked ?>
onchange="markAnswered(<?= $index ?>, <?= $q['id'] ?>, '<?= $opt ?>')">
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

function markAnswered(i, qid, optVal){

let btn=document.getElementById("nav"+i);

if(btn){
btn.classList.remove("not-visited","visited");
btn.classList.add("answered");
}

if(qid && optVal){
fetch("save_answer.php",{
method:"POST",
headers:{"Content-Type":"application/x-www-form-urlencoded"},
body:"exam_id=<?= $exam_id ?>&question_id="+encodeURIComponent(qid)+"&answer="+encodeURIComponent(optVal)
})
.then(res => res.json())
.then(data => {
if(data.status !== "success"){
showSaveError();
}
})
.catch(err => {
showSaveError();
});
}

}

function showSaveError(){
let warningBox=document.getElementById("warningBox");
if(warningBox){
warningBox.innerText="Answer could not be saved. Please try again.";
warningBox.style.display="block";
setTimeout(()=>{
warningBox.style.display="none";
},3000);
}
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
