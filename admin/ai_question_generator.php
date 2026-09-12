<?php
session_start();
require_once "../config/db.php";
require_once "../config/ai_client.php";

/* ===== ADMIN AUTH GUARD ===== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$admin_id = $_SESSION['user_id'] ?? 1;

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);

/* ===== FETCH EXAMS AND THEIR AI GENERATION STATUS ===== */
$exams_res = $conn->query("SELECT id, title, ai_generated FROM exams ORDER BY title ASC");
$exams_list = [];
while ($row = $exams_res->fetch_assoc()) {
    $e_id = (int)$row['id'];
    $ai_status = 'available';

    if ((int)$row['ai_generated'] === 1) {
        $ai_status = 'completed';
    } else {
        // Check if ai_generated_questions OR ai_generation_requests has records for this exam
        $stmtGen = $conn->prepare("SELECT id FROM ai_generated_questions WHERE exam_id = ? LIMIT 1");
        $stmtGen->bind_param("i", $e_id);
        $stmtGen->execute();
        $hasGen = $stmtGen->get_result()->fetch_assoc();

        $stmtReq = $conn->prepare("SELECT id FROM ai_generation_requests WHERE exam_id = ? AND status = 'success' LIMIT 1");
        $stmtReq->bind_param("i", $e_id);
        $stmtReq->execute();
        $hasReq = $stmtReq->get_result()->fetch_assoc();

        if ($hasGen || $hasReq) {
            $ai_status = 'completed';
            $conn->query("UPDATE exams SET ai_generated = 1 WHERE id = {$e_id}");
        }
    }
    $row['ai_status'] = $ai_status;
    $exams_list[] = $row;
}

/* ===== PROCESS GENERATION REQUEST ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_questions'])) {
    // CSRF Validation
    $post_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $post_token)) {
        $error = "Invalid CSRF security token.";
    } else {
        $exam_id = (int)($_POST['exam_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $topic = trim($_POST['topic'] ?? '');
        $difficulty = $_POST['difficulty'] ?? 'medium';
        $question_type = $_POST['question_type'] ?? 'mcq';
        $num_questions = (int)($_POST['number_of_questions'] ?? 5);
        $context = trim($_POST['additional_context'] ?? '');

        if ($exam_id <= 0) {
            $error = "Please select a valid exam.";
        } elseif (empty($subject) || empty($topic)) {
            $error = "Subject and Topic fields are required.";
        } elseif ($num_questions < 1 || $num_questions > 20) {
            $error = "Number of questions must be between 1 and 20.";
        } else {
            // ATOMIC RACE-CONDITION PROOF ONE-TIME AI GENERATION GUARD
            $conn->begin_transaction();
            try {
                // 1. Lock exam row for atomic evaluation
                $lockStmt = $conn->prepare("SELECT id, title, ai_generated FROM exams WHERE id = ? FOR UPDATE");
                $lockStmt->bind_param("i", $exam_id);
                $lockStmt->execute();
                $examData = $lockStmt->get_result()->fetch_assoc();

                if (!$examData) {
                    throw new Exception("Selected exam does not exist.");
                }

                if ((int)$examData['ai_generated'] === 1) {
                    throw new Exception("AI question generation has already been completed for this exam. Each exam can use AI question generation only once.");
                }

                // 2. Lock ai_generated_questions & ai_generation_requests FOR UPDATE
                $chkGen = $conn->prepare("SELECT id FROM ai_generated_questions WHERE exam_id = ? LIMIT 1 FOR UPDATE");
                $chkGen->bind_param("i", $exam_id);
                $chkGen->execute();
                $hasGen = $chkGen->get_result()->fetch_assoc();

                $chkReq = $conn->prepare("SELECT id FROM ai_generation_requests WHERE exam_id = ? AND status = 'success' LIMIT 1 FOR UPDATE");
                $chkReq->bind_param("i", $exam_id);
                $chkReq->execute();
                $hasReq = $chkReq->get_result()->fetch_assoc();

                if ($hasGen || $hasReq) {
                    $conn->query("UPDATE exams SET ai_generated = 1 WHERE id = {$exam_id}");
                    throw new Exception("AI question generation has already been completed for this exam. Each exam can use AI question generation only once.");
                }

                // 3. ATOMIC CLAIM: Mark ai_generated = 1 in database immediately inside transaction
                // Concurrent threads waiting on row lock will read ai_generated = 1 and be rejected!
                $conn->query("UPDATE exams SET ai_generated = 1 WHERE id = {$exam_id}");
                $conn->commit();

                // 4. Proceed with AI Microservice call outside lock
                $aiClient = new AiClient();
                $response = $aiClient->generateQuestions($subject, $topic, $difficulty, $num_questions, $context);

                if (!$response['success']) {
                    // Revert atomic claim if AI microservice failed to connect
                    $conn->begin_transaction();
                    $conn->query("UPDATE exams SET ai_generated = 0 WHERE id = {$exam_id}");

                    $req_id = "req_fail_" . uniqid();
                    $stmt = $conn->prepare("INSERT INTO ai_generation_requests (request_id, admin_id, exam_id, subject, topic, difficulty, question_type, number_requested, additional_context, model_used, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'failed', ?)");
                    $model = 'fastapi-ai-service';
                    $errMsg = $response['error'];
                    $stmt->bind_param("siisssissss", $req_id, $admin_id, $exam_id, $subject, $topic, $difficulty, $question_type, $num_questions, $context, $model, $errMsg);
                    $stmt->execute();
                    $conn->commit();

                    $error = "AI Generation Failed: " . htmlspecialchars($response['error']);
                } else {
                    $data = $response['data'];
                    $request_id = $data['request_id'] ?? ("req_" . uniqid());
                    $model_used = $data['model_used'] ?? 'gpt-4o-mini';
                    $generated_questions = $data['questions'] ?? [];

                    $conn->begin_transaction();

                    // 1. Log generation request
                    $stmtReq = $conn->prepare("INSERT INTO ai_generation_requests (request_id, admin_id, exam_id, subject, topic, difficulty, question_type, number_requested, additional_context, model_used, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'success')");
                    $stmtReq->bind_param("siisssisss", $request_id, $admin_id, $exam_id, $subject, $topic, $difficulty, $question_type, $num_questions, $context, $model_used);
                    $stmtReq->execute();

                    // 2. Insert questions into staging table (status = pending)
                    $inserted_count = 0;
                    $stmtIns = $conn->prepare("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question_type, question, option_a, option_b, option_c, option_d, correct_option, explanation, generation_model, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");

                    foreach ($generated_questions as $gq) {
                        $qText = $gq['question'];
                        $optA = $gq['options']['A'] ?? '';
                        $optB = $gq['options']['B'] ?? '';
                        $optC = $gq['options']['C'] ?? '';
                        $optD = $gq['options']['D'] ?? '';
                        $correct = $gq['correct_answer'] ?? 'A';
                        $explanation = $gq['explanation'] ?? '';

                        $stmtIns->bind_param("siissssssssssss",
                            $request_id, $admin_id, $exam_id, $subject, $topic, $difficulty, $question_type,
                            $qText, $optA, $optB, $optC, $optD, $correct, $explanation, $model_used
                        );

                        if ($stmtIns->execute()) {
                            $inserted_count++;
                        }
                    }

                    $conn->commit();

                    $_SESSION['success'] = "Generated {$inserted_count} AI questions successfully! Review and approve them below.";
                    header("Location: review_ai_questions.php?request_id=" . urlencode($request_id) . "&exam_id=" . $exam_id);
                    exit;
                }
            } catch (Exception $ex) {
                if ($conn->connect_errno === 0) {
                    @$conn->rollback();
                }
                $error = $ex->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>AI Question Generator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <style>
        .gen-container {
            background: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            max-width: 800px;
            margin-top: 20px;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            flex: 1;
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            font-size: 15px;
            border: 1px solid #dcdfe6;
            border-radius: 6px;
            box-sizing: border-box;
        }
        .form-group textarea {
            height: 90px;
            resize: vertical;
        }
        .btn-submit {
            background: linear-gradient(135deg, #1e88e5, #1565c0);
            color: white;
            padding: 14px 28px;
            font-size: 16px;
            font-weight: bold;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            width: 100%;
            transition: background 0.3s;
        }
        .btn-submit:hover {
            background: linear-gradient(135deg, #1565c0, #0d47a1);
        }
        .btn-submit:disabled {
            background: #9ca3af !important;
            cursor: not-allowed !important;
            opacity: 0.7;
        }
        .alert-error {
            background: #fde8e8;
            color: #9b1c1c;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #f05252;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #def7ec;
            color: #03543f;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #31c48d;
            margin-bottom: 20px;
        }
        .info-card {
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 25px;
            color: #3730a3;
            font-size: 14px;
        }
        .loading-spinner {
            display: none;
            text-align: center;
            margin-top: 15px;
            font-weight: bold;
            color: #1e88e5;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <?php include "sidebar.php"; ?>

    <div class="content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
            <h1 style="margin: 0;">✨ AI Question Generator</h1>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <a href="manage_questions.php" style="background: #0d6efd; color: white; padding: 10px 18px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; display: inline-block;">&larr; Manage Questions</a>
                <a href="add_question.php" style="background: #0d6efd; color: white; padding: 10px 18px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; display: inline-block;">➕ Add Question</a>
            </div>
        </div>
        <p style="color: #666; margin-bottom: 20px;">Use the AI Microservice to generate structured MCQs. Generated questions will enter the <strong>Pending Review</strong> queue and must be approved before becoming active exam questions.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- DYNAMIC EXAM AI STATUS EXPLANATION BOX -->
        <div id="aiStatusBox" style="display: none; padding: 18px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; font-size: 15px; line-height: 1.5;">
            <!-- JS Populated -->
        </div>

        <div class="info-card">
            💡 <strong>Admin Review Protection & One-Time Rule:</strong> Questions generated by AI do not automatically appear in student exams. You can review, edit, approve, or reject them. AI generation is allowed <strong>only once per exam</strong>.
        </div>

        <div class="gen-container">
            <form method="POST" action="ai_question_generator.php" onsubmit="showLoading()">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <div class="form-group">
                    <label>Select Target Exam *</label>
                    <select name="exam_id" id="exam_id" required onchange="handleExamSelect(this)">
                        <option value="">-- Select Exam --</option>
                        <?php foreach ($exams_list as $ex): ?>
                            <option value="<?= $ex['id'] ?>"
                                    data-status="<?= $ex['ai_status'] ?>"
                                    data-title="<?= htmlspecialchars($ex['title']) ?>"
                                    <?= (int)($_POST['exam_id'] ?? 0) === (int)$ex['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ex['title']) ?>
                                <?= $ex['ai_status'] === 'completed' ? ' (AI Generation Completed)' : ($ex['ai_status'] === 'pending' ? ' (AI Questions Pending)' : '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Subject *</label>
                        <input type="text" name="subject" id="subject" required placeholder="e.g. Computer Science, Python, Physics" value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Topic *</label>
                        <input type="text" name="topic" id="topic" required placeholder="e.g. Data Structures, OOP, SQL Queries" value="<?= htmlspecialchars($_POST['topic'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Difficulty</label>
                        <select name="difficulty">
                            <option value="easy" <?= ($_POST['difficulty'] ?? '') === 'easy' ? 'selected' : '' ?>>Easy</option>
                            <option value="medium" <?= ($_POST['difficulty'] ?? 'medium') === 'medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="hard" <?= ($_POST['difficulty'] ?? '') === 'hard' ? 'selected' : '' ?>>Hard</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Question Type</label>
                        <select name="question_type">
                            <option value="mcq">Multiple Choice Question (MCQ)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Number of Questions (1-20)</label>
                        <input type="number" name="number_of_questions" min="1" max="20" value="<?= (int)($_POST['number_of_questions'] ?? 5) ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Additional Learning Material / Context (Optional)</label>
                    <textarea name="additional_context" placeholder="Paste optional lecture notes, key terms, or specific constraints for the AI..."><?= htmlspecialchars($_POST['additional_context'] ?? '') ?></textarea>
                </div>

                <button type="submit" name="generate_questions" class="btn-submit" id="btnSubmit">
                    ⚡ Generate Questions with AI
                </button>

                <div class="loading-spinner" id="loadingSpinner">
                    ⏳ Contacting AI Service... Generating structured questions. Please wait.
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function handleExamSelect(selectEl) {
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    const status = selectedOption ? selectedOption.getAttribute("data-status") : "";
    const title = selectedOption ? selectedOption.getAttribute("data-title") : "";
    const statusBox = document.getElementById("aiStatusBox");
    const btnSubmit = document.getElementById("btnSubmit");
    const subjectInput = document.getElementById("subject");
    const topicInput = document.getElementById("topic");

    if (title && (!subjectInput.value || subjectInput.value === title)) {
        subjectInput.value = title;
        if (!topicInput.value) topicInput.value = "General";
    }

    if (status === "completed") {
        statusBox.style.display = "block";
        statusBox.style.background = "#fee2e2";
        statusBox.style.color = "#991b1b";
        statusBox.style.borderLeft = "5px solid #ef4444";
        statusBox.innerHTML = "🔒 <strong>AI Generation Completed:</strong> This exam has already received its AI-generated question set. AI generation can only be performed once per exam.";
        btnSubmit.disabled = true;
    } else if (status === "pending") {
        statusBox.style.display = "block";
        statusBox.style.background = "#fef3c7";
        statusBox.style.color = "#92400e";
        statusBox.style.borderLeft = "5px solid #f59e0b";
        statusBox.innerHTML = "⏳ <strong>AI Questions Pending Review:</strong> AI questions have already been generated for this exam and are currently awaiting admin review. AI generation is allowed only once per exam.";
        btnSubmit.disabled = true;
    } else {
        statusBox.style.display = "none";
        btnSubmit.disabled = false;
    }
}

function showLoading() {
    const btnSubmit = document.getElementById("btnSubmit");
    if (btnSubmit.disabled) return false;
    btnSubmit.style.opacity = "0.6";
    btnSubmit.innerText = "Generating...";
    document.getElementById("loadingSpinner").style.display = "block";
}

document.addEventListener("DOMContentLoaded", function() {
    const selectEl = document.getElementById("exam_id");
    if (selectEl && selectEl.value) {
        handleExamSelect(selectEl);
    }
});
</script>

</body>
</html>
