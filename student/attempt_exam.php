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

/* ===== FETCH EXISTING VIOLATION COUNT ===== */
$current_violation_count = 0;
$stmtViolations = $conn->prepare("SELECT COUNT(*) AS cnt FROM violations WHERE user_id = ? AND exam_id = ?");
if ($stmtViolations) {
    $stmtViolations->bind_param("ii", $user_id, $exam_id);
    $stmtViolations->execute();
    $resV = $stmtViolations->get_result();
    if ($rowV = $resV->fetch_assoc()) {
        $current_violation_count = (int)$rowV['cnt'];
    }
}
?>

<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Attempt Exam</title>

<link rel="stylesheet" href="../assets/css/style.css?v=<?= file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time() ?>">
<link rel="stylesheet" href="../assets/css/attempt_exam.css?v=<?= file_exists(__DIR__ . '/../assets/css/attempt_exam.css') ? filemtime(__DIR__ . '/../assets/css/attempt_exam.css') : time() ?>">

<style>
/* GUARANTEED FULL-WIDTH CODING ANSWER EDITOR & CODE DISPLAY */
.code-display,
.code-display pre,
.code-display code,
.question-code,
.question-code pre,
.question-code code,
.starter-code-block,
.coding-editor,
.coding-answer-editor,
.coding-answer-textarea,
textarea.coding-editor,
textarea.coding-answer-editor,
textarea.coding-answer-textarea {
    font-family: Consolas, Monaco, "Courier New", monospace !important;
    white-space: pre !important;
    overflow-x: auto !important;
    tab-size: 4 !important;
    -moz-tab-size: 4 !important;
    line-height: 1.7 !important;
    word-break: normal !important;
    word-wrap: normal !important;
}

.coding-editor,
.coding-answer-editor,
.coding-answer-textarea,
textarea.coding-editor,
textarea.coding-answer-editor,
textarea.coding-answer-textarea {
    display: block !important;
    width: 100% !important;
    min-height: 350px !important;
    height: 420px !important;
    box-sizing: border-box !important;
    font-size: 14.5px !important;
    padding: 16px 18px !important;
    background: #0f172a !important;
    color: #f8fafc !important;
    caret-color: #38bdf8 !important;
    border: 1px solid #334155 !important;
    border-radius: 8px !important;
    resize: vertical !important;
    overflow-y: auto !important;
}

.coding-answer-editor:focus,
.coding-answer-textarea:focus {
    outline: none !important;
    border-color: #38bdf8 !important;
    box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.25) !important;
}

.coding-answer-box {
    width: 100% !important;
    box-sizing: border-box !important;
    margin-top: 20px !important;
    padding-top: 16px !important;
    border-top: 1px solid #e2e8f0 !important;
}

.question-box {
    max-width: 980px !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

/* ===================================== */
/* MCQ OPTIONS ALIGNMENT & STYLING */
/* ===================================== */
.options {
    display: flex !important;
    flex-direction: column !important;
    gap: 12px !important;
    margin-top: 18px !important;
    margin-bottom: 24px !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

.options label,
.options .option-label {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 14px 18px !important;
    margin: 0 !important;
    font-size: 15.5px !important;
    line-height: 1.5 !important;
    color: #1e293b !important;
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 8px !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    width: 100% !important;
    transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease !important;
    user-select: none;
    -webkit-user-select: none;
}

.options label:hover,
.options .option-label:hover {
    background: #f8fafc !important;
    border-color: #94a3b8 !important;
}

/* Selected option row styling */
.options label.selected,
.options .option-label.selected,
.options label:has(input[type="radio"]:checked),
.options .option-label:has(input[type="radio"]:checked) {
    background: #eff6ff !important;
    border-color: #3b82f6 !important;
    color: #1d4ed8 !important;
    box-shadow: 0 1px 4px rgba(59, 130, 246, 0.15) !important;
}

/* Radio button styling */
.options input[type="radio"],
.options .option-radio {
    margin: 0 !important;
    padding: 0 !important;
    width: 20px !important;
    height: 20px !important;
    min-width: 20px !important;
    min-height: 20px !important;
    max-width: 20px !important;
    max-height: 20px !important;
    flex: 0 0 20px !important;
    flex-shrink: 0 !important;
    cursor: pointer !important;
    accent-color: #2563eb !important;
    vertical-align: middle !important;
}

/* Option text container */
.options .option-text {
    flex: 1 1 auto !important;
    min-width: 0 !important;
    white-space: pre-wrap !important;
    word-break: break-word !important;
    line-height: 1.5 !important;
    font-size: 15.5px !important;
    color: inherit !important;
}

/* Coding Question Readability Improvements */
.question-title {
    font-size: 20px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    margin: 0 0 14px 0 !important;
    line-height: 1.4 !important;
}
.question-description {
    font-size: 15px !important;
    color: #334155 !important;
    line-height: 1.65 !important;
    margin-bottom: 20px !important;
}
.question-p {
    margin: 0 0 12px 0 !important;
    line-height: 1.65 !important;
}
.question-list {
    margin: 8px 0 14px 24px !important;
    padding-left: 8px !important;
    color: #334155 !important;
    line-height: 1.65 !important;
    font-size: 15px !important;
}
.question-ol {
    list-style-type: decimal !important;
}
.question-ul {
    list-style-type: disc !important;
}
.question-li {
    margin-bottom: 6px !important;
    padding-left: 4px !important;
}
.code-block-wrapper {
    margin: 14px 0 18px 0 !important;
    border-radius: 8px !important;
    overflow: hidden !important;
    border: 1px solid #334155 !important;
    background: #0f172a !important;
}
.code-block-header {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    background: #1e293b !important;
    padding: 6px 14px !important;
    border-bottom: 1px solid #334155 !important;
}
.code-block-badge {
    font-family: 'Consolas', monospace !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    color: #94a3b8 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}
.code-block-dark {
    background: #0f172a !important;
    color: #e2e8f0 !important;
    padding: 14px 16px !important;
    margin: 0 !important;
    font-family: Consolas, Monaco, "Courier New", monospace !important;
    font-size: 13.5px !important;
    line-height: 1.7 !important;
    overflow-x: auto !important;
    white-space: pre !important;
    tab-size: 4 !important;
    -moz-tab-size: 4 !important;
    word-break: normal !important;
    word-wrap: normal !important;
}
.question-code code,
.code-block-dark code {
    font-family: inherit !important;
    white-space: pre !important;
    line-height: inherit !important;
}
.io-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)) !important;
    gap: 14px !important;
    margin-bottom: 18px !important;
    font-size: 13.5px !important;
}
.io-card {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 8px !important;
    padding: 12px 16px !important;
}
.io-content {
    margin-top: 6px !important;
    color: #1e293b !important;
    line-height: 1.6 !important;
}
.io-line {
    margin-bottom: 4px !important;
}
.io-line:last-child {
    margin-bottom: 0 !important;
}
.sample-case-card {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 8px !important;
    padding: 14px 16px !important;
    margin-bottom: 12px !important;
}
.sample-case-index {
    font-size: 12px !important;
    font-weight: 700 !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin-bottom: 8px !important;
}
.sample-case-grid {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 12px !important;
}
.sample-case-col {
    display: flex !important;
    flex-direction: column !important;
}
.sample-case-label {
    color: #64748b !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    display: block !important;
    margin-bottom: 4px !important;
}
.sample-case-code {
    padding: 10px 12px !important;
    border-radius: 6px !important;
    font-family: Consolas, Monaco, "Courier New", monospace !important;
    font-size: 13px !important;
    line-height: 1.6 !important;
    margin: 0 !important;
    white-space: pre !important;
    word-break: normal !important;
    word-wrap: normal !important;
    overflow-x: auto !important;
}
.sample-input-code {
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    color: #1e293b !important;
}
.sample-output-code {
    background: #f0fdf4 !important;
    border: 1.5px solid #86efac !important;
    color: #14532d !important;
    font-weight: 600 !important;
}
.sample-case-explanation {
    margin-top: 10px !important;
    padding: 10px 14px !important;
    background: #eff6ff !important;
    border-left: 3.5px solid #3b82f6 !important;
    border-radius: 4px !important;
    font-size: 13.5px !important;
    color: #1e3a8a !important;
    line-height: 1.55 !important;
    font-style: normal !important;
}
.sample-case-explanation strong,
.sample-case-explanation .explanation-tag {
    color: #1d4ed8 !important;
    font-weight: 700 !important;
    margin-right: 4px !important;
}

@media (max-width: 768px) {
    .coding-answer-editor,
    .coding-answer-textarea,
    textarea.coding-answer-editor,
    textarea.coding-answer-textarea {
        height: 360px !important;
        min-height: 300px !important;
        font-size: 13.5px !important;
        padding: 12px 14px !important;
    }
    .question-box {
        padding: 18px !important;
    }
    .exam-content {
        padding: 15px !important;
    }
    .sample-case-grid,
    .io-grid {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
    }
    .options {
        gap: 10px !important;
        margin-top: 14px !important;
        margin-bottom: 20px !important;
    }
    .options label,
    .options .option-label {
        padding: 12px 14px !important;
        font-size: 14.5px !important;
        gap: 10px !important;
    }
    .options input[type="radio"],
    .options .option-radio {
        width: 18px !important;
        height: 18px !important;
        min-width: 18px !important;
        min-height: 18px !important;
        max-width: 18px !important;
        max-height: 18px !important;
        flex: 0 0 18px !important;
    }
    .options .option-text {
        font-size: 14.5px !important;
    }
}

/* TAB VIOLATION TRACKER STYLES */
.violation-tracker {
    position: fixed;
    top: 20px;
    right: 220px;
    background: #ffffff;
    color: #b91c1c;
    border: 1.5px solid #ef4444;
    padding: 6px 14px;
    border-radius: 6px;
    font-weight: bold;
    font-size: 14px;
    z-index: 999;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}
@media (max-width: 768px) {
    .violation-tracker {
        top: 42px;
        right: 10px;
        font-size: 12px;
        padding: 4px 8px;
    }
}
</style>

</head>

<body>

<!-- AUDIO -->
<audio id="beepSound" preload="auto">
<source src="../assets/beep.mp3" type="audio/mpeg">
</audio>

<div class="timer" id="timer"></div>
<div class="violation-tracker" id="violationTracker">Tab-Switch Violations: <span id="violationCountDisplay"><?= $current_violation_count ?></span></div>
<div class="warning-box" id="warningBox"></div>

<div class="exam-wrapper">

<!-- LEFT PANEL -->
<div class="question-panel">

<h3>Questions</h3>

<div class="violation-count-badge" id="panelViolationBadge" style="margin-bottom:15px;padding:8px 12px;background:#fff1f2;border:1px solid #fecdd3;border-radius:6px;font-size:13px;font-weight:600;color:#be123c;">
    Tab-Switch Violations: <span id="panelViolationCount"><?= $current_violation_count ?></span>
</div>

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

<?php
$qType = strtolower(trim($q['question_type'] ?? ''));
$has_mcq_options = (!empty($q['option_a']) || !empty($q['option_b']));
$is_coding = ($qType === 'coding') || (!empty($q['programming_language']) && $qType !== 'mcq' && $qType !== 'short_answer' && $qType !== 'descriptive');
?>

<?= render_exam_question($q['question'], $index + 1) ?>

<?php if ($is_coding && !empty($q['programming_language'])): ?>
    <div class="coding-section">
        <span class="coding-lang-badge">
            💻 Programming Language: <?= htmlspecialchars($q['programming_language']) ?>
        </span>
    </div>
<?php endif; ?>

<?php if ($is_coding && (!empty($q['input_format']) || !empty($q['output_format']))): ?>
    <div class="io-grid">
        <?php if (!empty($q['input_format'])): ?>
            <div class="io-card">
                <div class="coding-section-label">📥 Input Format</div>
                <div class="io-content"><?= format_io_format_lines($q['input_format']) ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($q['output_format'])): ?>
            <div class="io-card">
                <div class="coding-section-label">📤 Output Format</div>
                <div class="io-content"><?= format_io_format_lines($q['output_format']) ?></div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($is_coding && !empty($q['constraints'])): ?>
    <div class="coding-section">
        <?= render_coding_constraints($q['constraints']) ?>
    </div>
<?php endif; ?>

<?php
$sampleCases = [];
if ($is_coding && !empty($q['sample_test_cases'])) {
    $decodedSamples = is_string($q['sample_test_cases']) ? json_decode($q['sample_test_cases'], true) : $q['sample_test_cases'];
    if (is_array($decodedSamples)) {
        $sampleCases = $decodedSamples;
    }
}
$hasSampleExplanations = false;
foreach ($sampleCases as $stc) {
    if (!empty($stc['explanation'])) {
        $hasSampleExplanations = true;
        break;
    }
}
$generalExplanation = trim($q['explanation'] ?? '');
$cleanGeneralExplanation = preg_replace('/\s*\(Evaluated for [^)]+\)\.?$/i', '', $generalExplanation);
?>
<?php if ($is_coding && !empty($sampleCases)): ?>
    <div class="coding-section">
        <div class="coding-section-label">🧪 Sample Test Cases</div>
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <?php foreach ($sampleCases as $sIdx => $stc): ?>
                <div class="sample-case-card">
                    <?php if (count($sampleCases) > 1): ?>
                        <div class="sample-case-index">Sample Case #<?= $sIdx + 1 ?></div>
                    <?php endif; ?>
                    <div class="sample-case-grid">
                        <div class="sample-case-col">
                            <span class="sample-case-label">Sample Input:</span>
                            <pre class="sample-case-code sample-input-code code-display"><?= htmlspecialchars(is_scalar($stc['input'] ?? '') ? (string)$stc['input'] : json_encode($stc['input'])) ?></pre>
                        </div>
                        <div class="sample-case-col">
                            <span class="sample-case-label">Sample Output:</span>
                            <pre class="sample-case-code sample-output-code code-display"><?= htmlspecialchars(is_scalar($stc['output'] ?? '') ? (string)$stc['output'] : json_encode($stc['output'])) ?></pre>
                        </div>
                    </div>
                    <?php if (!empty($stc['explanation'])): ?>
                        <div class="sample-case-explanation">
                            <strong class="explanation-tag">💡 Explanation:</strong> <span class="explanation-text"><?= htmlspecialchars($stc['explanation']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($is_coding && !$hasSampleExplanations && !empty($cleanGeneralExplanation)): ?>
    <div class="coding-section coding-explanation-section">
        <div class="coding-section-label">💡 Explanation</div>
        <div class="sample-case-explanation" style="margin-top: 0;">
            <span class="explanation-text"><?= htmlspecialchars($cleanGeneralExplanation) ?></span>
        </div>
    </div>
<?php endif; ?>

<?php
if ($is_coding):
    $existingAnswer = $saved_answers[(int)$q['id']] ?? null;
    if ($existingAnswer !== null && $existingAnswer !== '') {
        $editorInitialCode = $existingAnswer;
    } else {
        $editorInitialCode = get_coding_starter_code($q);
    }
    $editorInitialCode = safe_unescape_exam_code($editorInitialCode);
?>
<div class="coding-answer-box" style="width: 100% !important; box-sizing: border-box !important;">
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
        class="coding-answer-editor coding-answer-textarea"
        style="display: block !important; width: 100% !important; min-height: 350px !important; height: 420px; box-sizing: border-box !important; font-family: Consolas, Monaco, 'Courier New', monospace !important; font-size: 14.5px !important; line-height: 1.7 !important; padding: 16px 18px !important; background: #0f172a !important; color: #f8fafc !important; caret-color: #38bdf8 !important; border: 1px solid #334155 !important; border-radius: 8px !important; tab-size: 4 !important; -moz-tab-size: 4 !important; resize: vertical !important; white-space: pre !important; overflow-x: auto !important; overflow-y: auto !important; word-wrap: normal !important; word-break: normal !important;"
        placeholder="Enter your code solution or answer here..."
        spellcheck="false"
        oninput="handleAnswerInput(<?= $index ?>, <?= $q['id'] ?>, this.value)"
        onchange="markAnswered(<?= $index ?>, <?= $q['id'] ?>, this.value)"><?= htmlspecialchars($editorInitialCode, ENT_QUOTES, 'UTF-8') ?></textarea>

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
<?php elseif ($qType === 'short_answer'): ?>
<div class="short-answer-box" style="margin-top: 20px; width: 100%;">
    <label style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 8px; color: #0f172a;">Your Short Answer:</label>
    <textarea name="answer[<?= $q['id'] ?>]"
        id="ans_<?= $q['id'] ?>"
        data-qid="<?= $q['id'] ?>"
        data-idx="<?= $index ?>"
        rows="4"
        class="short-answer-textarea"
        style="width: 100%; min-height: 110px; padding: 12px; font-size: 15px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-family: inherit;"
        placeholder="Enter your concise answer here..."
        oninput="handleAnswerInput(<?= $index ?>, <?= $q['id'] ?>, this.value)"
        onchange="markAnswered(<?= $index ?>, <?= $q['id'] ?>, this.value)"><?= htmlspecialchars($saved_answers[(int)$q['id']] ?? '') ?></textarea>
</div>
<?php elseif ($qType === 'descriptive'): ?>
<div class="descriptive-answer-box" style="margin-top: 20px; width: 100%;">
    <label style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 8px; color: #0f172a;">Your Descriptive Answer:</label>
    <textarea name="answer[<?= $q['id'] ?>]"
        id="ans_<?= $q['id'] ?>"
        data-qid="<?= $q['id'] ?>"
        data-idx="<?= $index ?>"
        rows="8"
        class="descriptive-answer-textarea"
        style="width: 100%; min-height: 200px; padding: 12px; font-size: 15px; line-height: 1.5; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-family: inherit;"
        placeholder="Enter your detailed answer / explanation here..."
        oninput="handleAnswerInput(<?= $index ?>, <?= $q['id'] ?>, this.value)"
        onchange="markAnswered(<?= $index ?>, <?= $q['id'] ?>, this.value)"><?= htmlspecialchars($saved_answers[(int)$q['id']] ?? '') ?></textarea>
</div>
<?php else: ?>
<div class="options">
<?php foreach(['A','B','C','D'] as $opt):
    $qid = (int)$q['id'];
    $isChecked = (isset($saved_answers[$qid]) && $saved_answers[$qid] === $opt) ? 'checked' : '';
    $optText = $q['option_'.strtolower($opt)] ?? '';
    if ($optText === '') continue;
?>
<label class="option-label <?= $isChecked ? 'selected' : '' ?>">
<input type="radio"
class="option-radio"
name="answer[<?= $q['id'] ?>]"
value="<?= $opt ?>"
<?= $isChecked ?>
onchange="handleOptionSelect(this); markAnswered(<?= $index ?>, <?= $q['id'] ?>, '<?= $opt ?>')"><span class="option-text"><?= htmlspecialchars($optText) ?></span></label>
<?php endforeach; ?>
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
    let ta = currentBox.querySelector('textarea[name^="answer"]');
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
    document.querySelectorAll('.coding-answer-editor, .coding-answer-textarea').forEach(ta => {
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


/* FORM SUBMISSION TRACKING */
const examForm = document.getElementById("examForm");
if (examForm) {
    examForm.addEventListener("submit", () => {
        submitted = true;
    });
}

/* TAB VIOLATION TRACKING - DETECTION AND DISPLAY ONLY (NO AUTO-SUBMISSION) */
let violationCount = <?= $current_violation_count ?>;
let isTabHidden = false;
let lastViolationTime = 0;

const warningBox = document.getElementById("warningBox");
const violationCountDisplay = document.getElementById("violationCountDisplay");
const panelViolationCount = document.getElementById("panelViolationCount");

function updateViolationDisplays() {
    if (violationCountDisplay) {
        violationCountDisplay.innerText = violationCount;
    }
    if (panelViolationCount) {
        panelViolationCount.innerText = violationCount;
    }
}

document.addEventListener("visibilitychange", () => {
    if (submitted) return;

    if (document.hidden) {
        // Prevent duplicate counting for a single tab switch event
        const now = Date.now();
        if (isTabHidden || (now - lastViolationTime < 800)) {
            return;
        }
        isTabHidden = true;
        lastViolationTime = now;
        violationCount++;
        updateViolationDisplays();

        fetch("report_violation.php", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "exam_id=<?= $exam_id ?>&csrf_token=<?= urlencode($csrf_token) ?>"
        })
        .then(res => res.json())
        .then(data => {
            if (data && typeof data.violations === "number") {
                violationCount = data.violations;
                updateViolationDisplays();
            }
        })
        .catch(() => {
            // Retain locally incremented count if network is slow/offline
        });
    } else {
        if (!isTabHidden) return;
        isTabHidden = false;

        // Display non-destructive warning popup when returning to tab
        if (warningBox) {
            warningBox.innerText = "WARNING: Tab switching detected! Tab-Switch Violations: " + violationCount;
            warningBox.style.display = "block";
            setTimeout(() => {
                warningBox.style.display = "none";
            }, 3500);
        }
    }
});

/* OPTION SELECTION SYNC */
function handleOptionSelect(radio) {
    if (!radio) return;
    const container = radio.closest('.options');
    if (container) {
        container.querySelectorAll('label').forEach(lbl => {
            const r = lbl.querySelector('input[type="radio"]');
            if (r && r.checked) {
                lbl.classList.add('selected');
            } else {
                lbl.classList.remove('selected');
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.options input[type="radio"]:checked').forEach(r => {
        const lbl = r.closest('label');
        if (lbl) lbl.classList.add('selected');
    });
});

</script>

</body>
</html>
