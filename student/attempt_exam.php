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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

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
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

<input type="hidden" name="exam_id" value="<?= $exam_id ?>">

<?php foreach($questions as $index=>$q): ?>

<div class="question-box"
id="q<?= $index ?>"
style="<?= $index!==0?'display:none':'' ?>">

<h2>Q<?= $index+1 ?>. <?= htmlspecialchars(clean_question_title($q['question'])) ?></h2>

<?php if (!empty($q['programming_language'])): ?>
    <div style="margin-bottom: 12px;">
        <span style="background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 4px; font-size: 13px; font-weight: 600;">
            💻 Language: <?= htmlspecialchars($q['programming_language']) ?>
        </span>
    </div>
<?php endif; ?>

<?php if (!empty($q['input_format']) || !empty($q['output_format'])): ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 10px; margin-bottom: 12px; font-size: 13px;">
        <?php if (!empty($q['input_format'])): ?>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
                <strong style="color: #475569; text-transform: uppercase; font-size: 11px;">📥 Input Format</strong>
                <div style="margin-top: 4px; color: #1e293b;"><?= nl2br(htmlspecialchars($q['input_format'])) ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($q['output_format'])): ?>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
                <strong style="color: #475569; text-transform: uppercase; font-size: 11px;">📤 Output Format</strong>
                <div style="margin-top: 4px; color: #1e293b;"><?= nl2br(htmlspecialchars($q['output_format'])) ?></div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($q['constraints'])): ?>
    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; font-size: 13px; color: #92400e;">
        ⚠️ <strong>Constraints:</strong> <code><?= htmlspecialchars($q['constraints']) ?></code>
    </div>
<?php endif; ?>

<?php
$sampleCases = [];
if (!empty($q['sample_test_cases'])) {
    $decodedSamples = is_string($q['sample_test_cases']) ? json_decode($q['sample_test_cases'], true) : $q['sample_test_cases'];
    if (is_array($decodedSamples)) {
        $sampleCases = $decodedSamples;
    }
}
?>
<?php if (!empty($sampleCases)): ?>
    <div style="margin-bottom: 14px;">
        <strong style="font-size: 13px; color: #334155;">🧪 Sample Test Cases:</strong>
        <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 6px;">
            <?php foreach ($sampleCases as $sIdx => $stc): ?>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; font-size: 13px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <div>
                            <span style="color: #64748b; font-size: 11px; text-transform: uppercase;">Sample Input:</span>
                            <pre style="background: white; border: 1px solid #cbd5e1; padding: 6px; border-radius: 4px; margin: 2px 0 0 0; font-family: monospace; font-size: 12px; white-space: pre-wrap;"><?= htmlspecialchars(is_scalar($stc['input'] ?? '') ? (string)$stc['input'] : json_encode($stc['input'])) ?></pre>
                        </div>
                        <div>
                            <span style="color: #64748b; font-size: 11px; text-transform: uppercase;">Sample Output:</span>
                            <pre style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 6px; border-radius: 4px; margin: 2px 0 0 0; font-family: monospace; font-weight: bold; font-size: 12px; white-space: pre-wrap;"><?= htmlspecialchars(is_scalar($stc['output'] ?? '') ? (string)$stc['output'] : json_encode($stc['output'])) ?></pre>
                        </div>
                    </div>
                    <?php if (!empty($stc['explanation'])): ?>
                        <div style="font-size: 12px; color: #64748b; margin-top: 4px; font-style: italic;">
                            Explanation: <?= htmlspecialchars($stc['explanation']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($q['starter_code'])): ?>
    <div style="margin-bottom: 14px;">
        <strong style="font-size: 13px; color: #334155;">💻 Starter Code:</strong>
        <pre style="background: #1e293b; color: #e2e8f0; padding: 10px; border-radius: 6px; font-family: monospace; font-size: 12px; overflow-x: auto; margin-top: 6px; white-space: pre-wrap;"><code><?= htmlspecialchars($q['starter_code']) ?></code></pre>
    </div>
<?php endif; ?>

<?php
$has_mcq_options = (!empty($q['option_a']) || !empty($q['option_b']));
if ($has_mcq_options):
?>
<div class="options">
<?php foreach(['A','B','C','D'] as $opt): 
    $qid = (int)$q['id'];
    $isChecked = (isset($saved_answers[$qid]) && $saved_answers[$qid] === $opt) ? 'checked' : '';
    $optText = $q['option_'.strtolower($opt)] ?? '';
    if ($optText === '') continue;
?>
<label>
<input type="radio"
name="answer[<?= $q['id'] ?>]"
value="<?= $opt ?>"
<?= $isChecked ?>
onchange="markAnswered(<?= $index ?>, <?= $q['id'] ?>, '<?= $opt ?>')">
<?= htmlspecialchars($optText) ?>
</label>
<?php endforeach; ?>
</div>
<?php else: ?>
<div style="margin-top: 15px;">
    <label style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px; color: #1e293b;">Your Solution / Answer:</label>
    <textarea name="answer[<?= $q['id'] ?>]" rows="8" style="width: 100%; font-family: 'Consolas', 'Courier New', monospace; font-size: 13px; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; line-height: 1.5;" placeholder="Enter your code solution or answer here..." onchange="markAnswered(<?= $index ?>, <?= $q['id'] ?>, this.value)"><?= htmlspecialchars($saved_answers[(int)$q['id']] ?? '') ?></textarea>
</div>
<?php endif; ?>

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
body:"exam_id=<?= $exam_id ?>&csrf_token=<?= urlencode($csrf_token) ?>&question_id="+encodeURIComponent(qid)+"&answer="+encodeURIComponent(optVal)
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
body:"exam_id=<?= $exam_id ?>&csrf_token=<?= urlencode($csrf_token) ?>"
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
