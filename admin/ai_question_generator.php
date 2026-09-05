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

/* ===== PROCESS GENERATION REQUEST ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_questions'])) {
    // CSRF Validation
    $post_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $post_token)) {
        $error = "Invalid CSRF security token.";
    } else {
        $subject = trim($_POST['subject'] ?? '');
        $topic = trim($_POST['topic'] ?? '');
        $difficulty = $_POST['difficulty'] ?? 'medium';
        $question_type = $_POST['question_type'] ?? 'mcq';
        $num_questions = (int)($_POST['number_of_questions'] ?? 5);
        $context = trim($_POST['additional_context'] ?? '');

        if (empty($subject) || empty($topic)) {
            $error = "Subject and Topic fields are required.";
        } elseif ($num_questions < 1 || $num_questions > 20) {
            $error = "Number of questions must be between 1 and 20.";
        } else {
            $aiClient = new AiClient();
            $response = $aiClient->generateQuestions($subject, $topic, $difficulty, $num_questions, $context);

            if (!$response['success']) {
                $error = "AI Generation Failed: " . htmlspecialchars($response['error']);
                
                // Log failed request
                $req_id = "req_fail_" . uniqid();
                $stmt = $conn->prepare("INSERT INTO ai_generation_requests (request_id, admin_id, subject, topic, difficulty, question_type, number_requested, additional_context, model_used, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'failed', ?)");
                $model = 'fastapi-ai-service';
                $errMsg = $response['error'];
                $stmt->bind_param("sissssiss", $req_id, $admin_id, $subject, $topic, $difficulty, $question_type, $num_questions, $context, $model, $errMsg);
                $stmt->execute();
            } else {
                $data = $response['data'];
                $request_id = $data['request_id'] ?? ("req_" . uniqid());
                $model_used = $data['model_used'] ?? 'gpt-4o-mini';
                $generated_questions = $data['questions'] ?? [];

                // 1. Log generation request
                $stmtReq = $conn->prepare("INSERT INTO ai_generation_requests (request_id, admin_id, subject, topic, difficulty, question_type, number_requested, additional_context, model_used, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'success')");
                $stmtReq->bind_param("sissssiss", $request_id, $admin_id, $subject, $topic, $difficulty, $question_type, $num_questions, $context, $model_used);
                $stmtReq->execute();

                // 2. Insert questions into staging table (status = pending)
                $inserted_count = 0;
                $stmtIns = $conn->prepare("INSERT INTO ai_generated_questions (request_id, admin_id, subject, topic, difficulty, question_type, question, option_a, option_b, option_c, option_d, correct_option, explanation, generation_model, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");

                foreach ($generated_questions as $gq) {
                    $qText = $gq['question'];
                    $optA = $gq['options']['A'] ?? '';
                    $optB = $gq['options']['B'] ?? '';
                    $optC = $gq['options']['C'] ?? '';
                    $optD = $gq['options']['D'] ?? '';
                    $correct = $gq['correct_answer'] ?? 'A';
                    $explanation = $gq['explanation'] ?? '';

                    $stmtIns->bind_param("sissssssssssss", 
                        $request_id, $admin_id, $subject, $topic, $difficulty, $question_type,
                        $qText, $optA, $optB, $optC, $optD, $correct, $explanation, $model_used
                    );
                    
                    if ($stmtIns->execute()) {
                        $inserted_count++;
                    }
                }

                $_SESSION['success'] = "Generated {$inserted_count} AI questions successfully! Review and approve them below.";
                header("Location: review_ai_questions.php?request_id=" . urlencode($request_id));
                exit;
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
        <h1>✨ AI Question Generator</h1>
        <p style="color: #666; margin-bottom: 20px;">Use the AI Microservice to generate structured MCQs. Generated questions will enter the <strong>Pending Review</strong> queue and must be approved before becoming active exam questions.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="info-card">
            💡 <strong>Admin Review Protection:</strong> Questions generated by AI do not automatically appear in student exams. You can review, edit, approve, or reject them.
        </div>

        <div class="gen-container">
            <form method="POST" action="ai_question_generator.php" onsubmit="showLoading()">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label>Subject *</label>
                        <input type="text" name="subject" required placeholder="e.g. Computer Science, Python, Physics" value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Topic *</label>
                        <input type="text" name="topic" required placeholder="e.g. Data Structures, OOP, SQL Queries" value="<?= htmlspecialchars($_POST['topic'] ?? '') ?>">
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
function showLoading() {
    document.getElementById("btnSubmit").style.opacity = "0.6";
    document.getElementById("btnSubmit").innerText = "Generating...";
    document.getElementById("loadingSpinner").style.display = "block";
}
</script>

</body>
</html>
