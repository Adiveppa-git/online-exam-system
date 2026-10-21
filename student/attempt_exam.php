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

<?= render_exam_question($q['question'], $index + 1) ?>

<?php if (!empty($q['programming_language'])): ?>
    <div class="coding-section">
        <span class="coding-lang-badge">
            💻 Programming Language: <?= htmlspecialchars($q['programming_language']) ?>
        </span>
    </div>
<?php endif; ?>

<?php if (!empty($q['input_format']) || !empty($q['output_format'])): ?>
    <div class="io-grid">
        <?php if (!empty($q['input_format'])): ?>
            <div class="io-card">
                <div class="coding-section-label">📥 Input Format</div>
                <div class="io-content"><?= nl2br(htmlspecialchars($q['input_format'])) ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($q['output_format'])): ?>
            <div class="io-card">
                <div class="coding-section-label">📤 Output Format</div>
                <div class="io-content"><?= nl2br(htmlspecialchars($q['output_format'])) ?></div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($q['constraints'])): ?>
    <div class="coding-section">
        <?= render_coding_constraints($q['constraints']) ?>
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
    <div class="coding-section">
        <div class="coding-section-label">🧪 Sample Test Cases</div>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php foreach ($sampleCases as $sIdx => $stc): ?>
                <div class="sample-case-card">
                    <div class="sample-case-grid">
                        <div>
                            <span class="sample-case-label">Sample Input:</span>
                            <pre class="sample-case-code sample-input-code"><?= htmlspecialchars(is_scalar($stc['input'] ?? '') ? (string)$stc['input'] : json_encode($stc['input'])) ?></pre>
                        </div>
                        <div>
                            <span class="sample-case-label">Sample Output:</span>
                            <pre class="sample-case-code sample-output-code"><?= htmlspecialchars(is_scalar($stc['output'] ?? '') ? (string)$stc['output'] : json_encode($stc['output'])) ?></pre>
                        </div>
                    </div>
                    <?php if (!empty($stc['explanation'])): ?>
                        <div class="sample-case-explanation">
                            <strong>Explanation:</strong> <?= htmlspecialchars($stc['explanation']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
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
<?php
$existingAnswer = $saved_answers[(int)$q['id']] ?? null;
if ($existingAnswer !== null && $existingAnswer !== '') {
    $editorInitialCode = $existingAnswer;
} else {
    $editorInitialCode = get_coding_starter_code($q);
}
?>
<div class="coding-answer-box">
    <div class="coding-answer-header">
        <label class="coding-answer-label">💻 Your Solution / Answer Editor:</label>
        <?php if (!empty($q['programming_language'])): ?>
            <span class="coding-lang-badge-sm">
                <?= htmlspecialchars($q['programming_language']) ?>
            </span>
        <?php endif; ?>
    </div>
    
    <textarea name="answer[<?= $q['id'] ?>]"
        id="ans_<?= $q['id'] ?>"
        data-qid="<?= $q['id'] ?>"
        data-idx="<?= $index ?>"
        data-lang="<?= htmlspecialchars($q['programming_language'] ?? 'python') ?>"
        rows="18"
        class="coding-answer-textarea"
        placeholder="Enter your code solution or answer here..."
        spellcheck="false"
        oninput="handleAnswerInput(<?= $index ?>, <?= $q['id'] ?>, this.value)"
        onchange="markAnswered(<?= $index ?>, <?= $q['id'] ?>, this.value)"><?= htmlspecialchars($editorInitialCode) ?></textarea>

    <!-- Coding Execution Toolbar -->
    <div class="coding-toolbar">
        <div class="coding-toolbar-left">
            <button type="button" class="btn-code-action btn-run-code" id="btnRun_<?= $q['id'] ?>" onclick="runStudentCode(<?= $index ?>, <?= $q['id'] ?>)">
                ▶ Run Code
            </button>
            <?php if (!empty($sampleCases)): ?>
                <button type="button" class="btn-code-action btn-run-check" id="btnCheck_<?= $q['id'] ?>" onclick="runAndCheckSampleCases(<?= $index ?>, <?= $q['id'] ?>)">
                    ✓ Run &amp; Check (<?= count($sampleCases) ?> Sample <?= count($sampleCases) === 1 ? 'Case' : 'Cases' ?>)
                </button>
            <?php endif; ?>
            <button type="button" class="btn-code-action btn-toggle-input" id="btnToggleInput_<?= $q['id'] ?>" onclick="toggleCustomInput(<?= $q['id'] ?>)">
                📥 Custom Input
            </button>
        </div>
        <div class="coding-toolbar-right">
            <span>Press <kbd style="background:#e2e8f0;padding:1px 5px;border-radius:3px;font-size:11px;color:#1e293b;">Tab</kbd> to indent</span>
            <span id="saveStatus_<?= $q['id'] ?>" style="min-width: 55px; text-align: right;"></span>
        </div>
    </div>

    <!-- Collapsible Custom Input Card -->
    <div id="customInputCard_<?= $q['id'] ?>" class="custom-input-card" style="display: none;">
        <label class="custom-input-label">Standard Input (stdin):</label>
        <textarea id="customInput_<?= $q['id'] ?>" class="custom-input-textarea" placeholder="Enter input here (lines passed to input() / Scanner)..."><?= htmlspecialchars(is_scalar($sampleCases[0]['input'] ?? '') ? (string)$sampleCases[0]['input'] : '') ?></textarea>
    </div>

    <!-- Dedicated Output Panel -->
    <div id="outputPanel_<?= $q['id'] ?>" class="execution-output-panel" style="display: none;">
        <div class="execution-output-header">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span id="outputBadge_<?= $q['id'] ?>" class="execution-status-badge">Console Output</span>
                <span id="outputMeta_<?= $q['id'] ?>" style="color: #94a3b8; font-size: 11px;"></span>
            </div>
            <button type="button" class="btn-close-output" onclick="closeOutputPanel(<?= $q['id'] ?>)" title="Close Output">✕</button>
        </div>
        <div id="outputContent_<?= $q['id'] ?>"></div>
    </div>
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


/* QUESTION NAV & ANSWER PERSISTENCE */
let current=0;
let saveDebounceTimer=null;

function saveCurrentAnswerIfTextarea() {
    let currentBox = document.getElementById("q" + current);
    if (!currentBox) return;
    let ta = currentBox.querySelector('textarea.coding-answer-textarea');
    if (ta && ta.dataset.qid) {
        markAnswered(current, parseInt(ta.dataset.qid), ta.value);
    }
}

function showQuestion(i){
    // Save current textarea answer immediately before navigating
    saveCurrentAnswerIfTextarea();

    let currentBtn=document.getElementById("nav"+current);
    if (currentBtn) {
        currentBtn.classList.remove("active");
        if(!currentBtn.classList.contains("answered")){
            currentBtn.classList.remove("not-visited");
            currentBtn.classList.add("visited");
        }
    }

    document.getElementById("q"+current).style.display="none";
    document.getElementById("q"+i).style.display="block";

    let newBtn=document.getElementById("nav"+i);
    if (newBtn) {
        newBtn.classList.remove("not-visited","visited");
        newBtn.classList.add("active");
    }

    current=i;
}

function handleAnswerInput(i, qid, val) {
    let btn = document.getElementById("nav" + i);
    if (btn) {
        if (val.trim() !== "") {
            btn.classList.remove("not-visited", "visited");
            btn.classList.add("answered");
        }
    }
    clearTimeout(saveDebounceTimer);
    saveDebounceTimer = setTimeout(() => {
        markAnswered(i, qid, val);
    }, 800);
}

function markAnswered(i, qid, optVal){
    let btn=document.getElementById("nav"+i);
    if(btn){
        if (optVal !== undefined && optVal !== null && optVal.trim() !== '') {
            btn.classList.remove("not-visited","visited");
            btn.classList.add("answered");
        }
    }

    if(qid !== undefined && qid !== null && optVal !== undefined && optVal !== null){
        let statusEl = document.getElementById("saveStatus_" + qid);
        if (statusEl) {
            statusEl.innerText = "Saving...";
            statusEl.style.color = "#64748b";
        }

        fetch("save_answer.php",{
            method:"POST",
            headers:{"Content-Type":"application/x-www-form-urlencoded"},
            body:"exam_id=<?= $exam_id ?>&csrf_token=<?= urlencode($csrf_token) ?>&question_id="+encodeURIComponent(qid)+"&answer="+encodeURIComponent(optVal)
        })
        .then(res => res.json())
        .then(data => {
            if(data.status !== "success"){
                showSaveError();
                if (statusEl) {
                    statusEl.innerText = "Save failed";
                    statusEl.style.color = "#dc2626";
                }
            } else {
                if (statusEl) {
                    statusEl.innerText = "Saved";
                    statusEl.style.color = "#16a34a";
                    setTimeout(() => {
                        if (statusEl.innerText === "Saved") {
                            statusEl.innerText = "";
                        }
                    }, 2500);
                }
            }
        })
        .catch(err => {
            showSaveError();
            if (statusEl) {
                statusEl.innerText = "Save error";
                statusEl.style.color = "#dc2626";
            }
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

// Enable Tab key indentation inside coding answer textareas
document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll('.coding-answer-textarea').forEach(ta => {
        ta.addEventListener('keydown', function(e) {
            if (e.key === 'Tab') {
                e.preventDefault();
                const start = this.selectionStart;
                const end = this.selectionEnd;
                this.value = this.value.substring(0, start) + '    ' + this.value.substring(end);
                this.selectionStart = this.selectionEnd = start + 4;
                this.dispatchEvent(new Event('input'));
            }
        });
    });
});

/* CODE EXECUTION & TEST CASES CLIENT LOGIC */
function escapeHtml(text) {
    if (text === null || text === undefined) return "";
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function toggleCustomInput(qid) {
    const card = document.getElementById("customInputCard_" + qid);
    const btn = document.getElementById("btnToggleInput_" + qid);
    if (!card) return;
    if (card.style.display === "none" || card.style.display === "") {
        card.style.display = "block";
        if (btn) btn.classList.add("active");
        const ta = document.getElementById("customInput_" + qid);
        if (ta) ta.focus();
    } else {
        card.style.display = "none";
        if (btn) btn.classList.remove("active");
    }
}

function closeOutputPanel(qid) {
    const panel = document.getElementById("outputPanel_" + qid);
    if (panel) panel.style.display = "none";
}

function copyStarterCode(qid) {
    // Deprecated: Starter code is now automatically initialized inside the answer editor
}

function runStudentCode(index, qid) {
    const editor = document.getElementById("ans_" + qid);
    if (!editor) return;
    const code = editor.value;
    if (code.trim() === "") {
        alert("Please enter your code solution in the editor before running.");
        editor.focus();
        return;
    }

    // Persist current code before execution
    markAnswered(index, qid, code);

    const panel = document.getElementById("outputPanel_" + qid);
    const badge = document.getElementById("outputBadge_" + qid);
    const meta = document.getElementById("outputMeta_" + qid);
    const content = document.getElementById("outputContent_" + qid);
    const btnRun = document.getElementById("btnRun_" + qid);
    const btnCheck = document.getElementById("btnCheck_" + qid);

    // Get custom input if visible
    let customInput = "";
    const customCard = document.getElementById("customInputCard_" + qid);
    if (customCard && customCard.style.display !== "none") {
        const customTa = document.getElementById("customInput_" + qid);
        if (customTa) customInput = customTa.value;
    }

    // Set loading state
    if (panel) panel.style.display = "block";
    if (badge) {
        badge.className = "execution-status-badge status-badge-running";
        badge.innerText = "⏳ Running...";
    }
    if (meta) meta.innerText = "";
    if (content) {
        content.innerHTML = '<div style="padding: 16px; color: #93c5fd; font-family: monospace; font-size: 13px;">Executing program securely in sandbox...</div>';
    }
    if (btnRun) btnRun.disabled = true;
    if (btnCheck) btnCheck.disabled = true;

    fetch("run_code.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: "csrf_token=<?= urlencode($csrf_token) ?>"
            + "&exam_id=<?= $exam_id ?>"
            + "&question_id=" + encodeURIComponent(qid)
            + "&action=run"
            + "&code=" + encodeURIComponent(code)
            + "&custom_input=" + encodeURIComponent(customInput)
    })
    .then(res => res.json())
    .then(data => {
        if (btnRun) btnRun.disabled = false;
        if (btnCheck) btnCheck.disabled = false;

        if (data.status === "error") {
            if (badge) {
                badge.className = "execution-status-badge status-badge-error";
                badge.innerText = "Execution Error";
            }
            if (content) {
                content.innerHTML = '<div style="padding: 14px; color: #f87171; font-family: monospace; font-size: 13px;">' + escapeHtml(data.message || "Execution failed.") + '</div>';
            }
            return;
        }

        const runData = data.data || {};
        const execStatus = runData.status || "error";
        const execTime = runData.execution_time_ms ? runData.execution_time_ms + " ms" : "";

        if (meta) meta.innerText = execTime ? "Runtime: " + execTime : "";

        if (execStatus === "success") {
            if (badge) {
                badge.className = "execution-status-badge status-badge-success";
                badge.innerText = "✓ Success";
            }
            let outHtml = '<pre class="console-output-box">' + (escapeHtml(runData.stdout) || '<span style="color:#64748b; font-style:italic;">(Program completed successfully with no output to stdout)</span>');
            if (runData.stderr) {
                outHtml += '<div class="console-stderr"><strong>Standard Error:</strong><br>' + escapeHtml(runData.stderr) + '</div>';
            }
            outHtml += '</pre>';
            if (content) content.innerHTML = outHtml;

        } else if (execStatus === "compilation_error") {
            if (badge) {
                badge.className = "execution-status-badge status-badge-error";
                badge.innerText = "✗ Compilation Error";
            }
            if (content) {
                content.innerHTML = '<pre class="console-output-box"><span class="console-stderr"><strong>Compilation Error:</strong><br>' + escapeHtml(runData.stderr || runData.stdout || "Compilation failed.") + '</span></pre>';
            }

        } else if (execStatus === "timeout") {
            if (badge) {
                badge.className = "execution-status-badge status-badge-timeout";
                badge.innerText = "⏱ Time Limit Exceeded";
            }
            if (content) {
                content.innerHTML = '<div class="console-stderr" style="padding: 14px; font-family: monospace; font-size: 13px;"><strong>Time Limit Exceeded:</strong><br>' + escapeHtml(runData.stderr || "Program execution timed out.") + '</div>';
            }

        } else {
            // Runtime error
            if (badge) {
                badge.className = "execution-status-badge status-badge-error";
                badge.innerText = "✗ Runtime Error" + (runData.exit_code ? " (Exit " + runData.exit_code + ")" : "");
            }
            let outHtml = '<pre class="console-output-box">';
            if (runData.stdout) {
                outHtml += escapeHtml(runData.stdout) + '<br>';
            }
            outHtml += '<span class="console-stderr"><strong>Runtime Error:</strong><br>' + escapeHtml(runData.stderr || "Process exited with non-zero status.") + '</span></pre>';
            if (content) content.innerHTML = outHtml;
        }
    })
    .catch(err => {
        if (btnRun) btnRun.disabled = false;
        if (btnCheck) btnCheck.disabled = false;
        if (badge) {
            badge.className = "execution-status-badge status-badge-error";
            badge.innerText = "Network Error";
        }
        if (content) {
            content.innerHTML = '<div style="padding: 14px; color: #f87171; font-family: monospace; font-size: 13px;">Network request failed. Please check backend server status.</div>';
        }
    });
}

function runAndCheckSampleCases(index, qid) {
    const editor = document.getElementById("ans_" + qid);
    if (!editor) return;
    const code = editor.value;
    if (code.trim() === "") {
        alert("Please enter your code solution in the editor before checking.");
        editor.focus();
        return;
    }

    // Persist current code before execution
    markAnswered(index, qid, code);

    const panel = document.getElementById("outputPanel_" + qid);
    const badge = document.getElementById("outputBadge_" + qid);
    const meta = document.getElementById("outputMeta_" + qid);
    const content = document.getElementById("outputContent_" + qid);
    const btnRun = document.getElementById("btnRun_" + qid);
    const btnCheck = document.getElementById("btnCheck_" + qid);

    // Set loading state
    if (panel) panel.style.display = "block";
    if (badge) {
        badge.className = "execution-status-badge status-badge-running";
        badge.innerText = "⏳ Checking Test Cases...";
    }
    if (meta) meta.innerText = "";
    if (content) {
        content.innerHTML = '<div style="padding: 16px; color: #93c5fd; font-family: monospace; font-size: 13px;">Running code against sample test cases...</div>';
    }
    if (btnRun) btnRun.disabled = true;
    if (btnCheck) btnCheck.disabled = true;

    fetch("run_code.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: "csrf_token=<?= urlencode($csrf_token) ?>"
            + "&exam_id=<?= $exam_id ?>"
            + "&question_id=" + encodeURIComponent(qid)
            + "&action=check"
            + "&code=" + encodeURIComponent(code)
    })
    .then(res => res.json())
    .then(data => {
        if (btnRun) btnRun.disabled = false;
        if (btnCheck) btnCheck.disabled = false;

        if (data.status === "error") {
            if (badge) {
                badge.className = "execution-status-badge status-badge-error";
                badge.innerText = "Check Error";
            }
            if (content) {
                content.innerHTML = '<div style="padding: 14px; color: #f87171; font-family: monospace; font-size: 13px;">' + escapeHtml(data.message || "Failed to check test cases.") + '</div>';
            }
            return;
        }

        const checkData = data.data || {};
        const results = checkData.results || [];
        const passedCount = checkData.passed_cases || 0;
        const totalCount = checkData.total_cases || 0;
        const allPassed = checkData.all_passed || false;

        if (badge) {
            if (allPassed) {
                badge.className = "execution-status-badge status-badge-success";
                badge.innerText = "✓ Passed All Cases (" + passedCount + "/" + totalCount + ")";
            } else if (checkData.status === "compilation_error") {
                badge.className = "execution-status-badge status-badge-error";
                badge.innerText = "✗ Compilation Error";
            } else {
                badge.className = "execution-status-badge status-badge-error";
                badge.innerText = "✗ " + passedCount + "/" + totalCount + " Passed";
            }
        }

        if (meta) {
            meta.innerText = allPassed ? "All sample cases verified" : "Some test cases failed";
        }

        if (checkData.status === "compilation_error") {
            if (content) {
                content.innerHTML = '<pre class="console-output-box"><span class="console-stderr"><strong>Compilation Error:</strong><br>' + escapeHtml(checkData.error_summary || "Compilation failed.") + '</span></pre>';
            }
            return;
        }

        let html = '<div class="test-cases-output-grid">';
        results.forEach(tc => {
            html += '<div class="tc-card ' + (tc.passed ? 'tc-passed' : 'tc-failed') + '">';
            html += '  <div class="tc-card-header">';
            html += '    <span>Sample Test Case #' + tc.case_index + '</span>';
            html += '    <span style="color:' + (tc.passed ? '#34d399' : '#f87171') + '; font-weight:700;">' + (tc.passed ? '✓ Passed' : '✗ Failed') + '</span>';
            html += '  </div>';
            html += '  <div class="tc-grid">';
            html += '    <div><span class="tc-label">Input</span><pre class="tc-code">' + (escapeHtml(tc.input) || '<span style="color:#64748b;">(Empty)</span>') + '</pre></div>';
            html += '    <div><span class="tc-label">Expected Output</span><pre class="tc-code tc-code-expected">' + (escapeHtml(tc.expected_output) || '<span style="color:#64748b;">(Empty)</span>') + '</pre></div>';
            html += '  </div>';
            html += '  <div style="margin-top:6px;">';
            html += '    <span class="tc-label">Your Output</span>';
            html += '    <pre class="tc-code ' + (tc.passed ? 'tc-code-actual-pass' : 'tc-code-actual-fail') + '">' + (escapeHtml(tc.actual_output) || '<span style="color:#64748b;">(No output)</span>') + '</pre>';
            html += '  </div>';
            if (tc.error && !tc.passed) {
                html += '  <div class="console-stderr" style="margin-top:6px; font-size:12px;"><strong>Error:</strong><br>' + escapeHtml(tc.error) + '</div>';
            }
            html += '</div>';
        });
        html += '</div>';

        if (content) content.innerHTML = html;
    })
    .catch(err => {
        if (btnRun) btnRun.disabled = false;
        if (btnCheck) btnCheck.disabled = false;
        if (badge) {
            badge.className = "execution-status-badge status-badge-error";
            badge.innerText = "Network Error";
        }
        if (content) {
            content.innerHTML = '<div style="padding: 14px; color: #f87171; font-family: monospace; font-size: 13px;">Network request failed. Please check backend server status.</div>';
        }
    });
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
