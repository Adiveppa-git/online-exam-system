<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/ai_client.php";

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

/* ===== FETCH EXAMS AND THEIR ATTEMPT LOCK STATUS ===== */
if (!function_exists('is_exam_locked_for_ai')) {
    function is_exam_locked_for_ai($conn, $exam_id) {
        $exam_id = (int)$exam_id;
        if ($exam_id <= 0) return false;

        $stmt = $conn->prepare("
            SELECT 1 FROM (
                SELECT id FROM results WHERE exam_id = ?
                UNION ALL
                SELECT id FROM student_answers WHERE exam_id = ?
                UNION ALL
                SELECT id FROM violations WHERE exam_id = ?
                UNION ALL
                SELECT id FROM violation_report WHERE exam_id = ?
                UNION ALL
                SELECT id FROM exam_violations WHERE exam_id = ?
            ) AS attempts LIMIT 1
        ");
        $stmt->bind_param("iiiii", $exam_id, $exam_id, $exam_id, $exam_id, $exam_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $is_locked = ($res && $res->fetch_assoc());
        $stmt->close();
        return (bool)$is_locked;
    }
}

$exams_res = $conn->query("SELECT id, title FROM exams ORDER BY title ASC");
$exams_list = [];
while ($row = $exams_res->fetch_assoc()) {
    $e_id = (int)$row['id'];
    $is_locked = is_exam_locked_for_ai($conn, $e_id);
    $row['ai_status'] = $is_locked ? 'locked' : 'available';
    $exams_list[] = $row;
}

/* ===== AI SERVICE HEALTH CHECK ===== */
$aiClient = new AiClient();
$aiHealth = $aiClient->checkHealth(2);
$is_ai_service_online = !empty($aiHealth['online']) && !empty($aiHealth['success']);

/* ===== HELPER FOR PAGINATION URL WITH FILTER PRESERVATION ===== */
if (!function_exists('get_history_page_url')) {
    function get_history_page_url($page, $exam_id, $subject = '', $status = 'all', $search = '') {
        $params = [
            'history_exam_id' => (int)$exam_id,
            'page' => (int)$page,
        ];
        if (!empty($subject)) {
            $params['history_subject'] = $subject;
        }
        if (!empty($status) && $status !== 'all') {
            $params['history_status'] = $status;
        }
        if (!empty($search)) {
            $params['history_search'] = $search;
        }
        return 'ai_question_generator.php?' . http_build_query($params) . '#history-section';
    }
}

/* ===== FETCH GENERATED QUESTIONS FOR SELECTED EXAM (PAGINATED HISTORY) ===== */
$history_exam_id = (int)($_GET['history_exam_id'] ?? ($_GET['exam_id'] ?? ($_POST['exam_id'] ?? 0)));
$history_subject = trim((string)($_GET['history_subject'] ?? ($_GET['subject'] ?? '')));
$history_status = strtolower(trim((string)($_GET['history_status'] ?? ($_GET['status'] ?? 'all'))));
if (!in_array($history_status, ['all', 'pending', 'approved', 'rejected'], true)) {
    $history_status = 'all';
}
$history_search = trim((string)($_GET['history_search'] ?? ($_GET['search'] ?? '')));
$raw_page = (int)($_GET['page'] ?? 1);
$history_limit = 10;

$history_total_count = 0;
$history_total_pages = 0;
$history_page = 1;
$history_offset = 0;
$history_questions = [];

if ($history_exam_id > 0) {
    // Shared dynamic WHERE builder for count and paginated list queries
    $whereClauses = ["gq.exam_id = ?"];
    $whereParams = [$history_exam_id];
    $whereTypes = "i";

    if ($history_subject !== '') {
        $whereClauses[] = "gq.subject = ?";
        $whereParams[] = $history_subject;
        $whereTypes .= "s";
    }

    if ($history_status !== 'all') {
        $whereClauses[] = "gq.status = ?";
        $whereParams[] = $history_status;
        $whereTypes .= "s";
    }

    if ($history_search !== '') {
        $whereClauses[] = "(gq.question LIKE ? OR gq.topic LIKE ?)";
        $searchWild = '%' . $history_search . '%';
        $whereParams[] = $searchWild;
        $whereParams[] = $searchWild;
        $whereTypes .= "ss";
    }

    $whereSql = "WHERE " . implode(" AND ", $whereClauses);

    // 1. Total matching count query
    $cntStmt = $conn->prepare("SELECT COUNT(*) AS total FROM ai_generated_questions gq {$whereSql}");
    if ($cntStmt) {
        $cntStmt->bind_param($whereTypes, ...$whereParams);
        $cntStmt->execute();
        $cntRes = $cntStmt->get_result();
        $history_total_count = (int)($cntRes->fetch_assoc()['total'] ?? 0);
        $cntStmt->close();
    }

    // 2. Bound clamping and pagination calculations
    if ($history_total_count > 0) {
        $history_total_pages = (int)ceil($history_total_count / $history_limit);
        $history_page = max(1, min($history_total_pages, $raw_page));
        $history_offset = (int)(($history_page - 1) * $history_limit);

        // 3. Paginated list query
        $listSql = "
            SELECT gq.*, e.title AS exam_title
            FROM ai_generated_questions gq
            LEFT JOIN exams e ON gq.exam_id = e.id
            {$whereSql}
            ORDER BY gq.id DESC
            LIMIT ? OFFSET ?
        ";
        $histStmt = $conn->prepare($listSql);
        if ($histStmt) {
            $listTypes = $whereTypes . "ii";
            $listParams = array_merge($whereParams, [$history_limit, $history_offset]);
            $histStmt->bind_param($listTypes, ...$listParams);
            $histStmt->execute();
            $resH = $histStmt->get_result();
            while ($hRow = $resH->fetch_assoc()) {
                $history_questions[] = $hRow;
            }
            $histStmt->close();
        }
    } else {
        $history_total_pages = 0;
        $history_page = 1;
        $history_offset = 0;
    }
}

/* ===== PROCESS GENERATION REQUEST ===== */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['generate_questions'])) {
    // CSRF Validation
    $post_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $post_token)) {
        $error = "Invalid CSRF security token.";
    } else {
        $exam_id = (int)($_POST['exam_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $topic = trim($_POST['topic'] ?? '');
        $difficulty = trim($_POST['difficulty'] ?? '');
        $question_type = 'mcq';
        $num_questions_raw = trim($_POST['number_of_questions'] ?? '');
        $num_questions = is_numeric($num_questions_raw) ? (int)$num_questions_raw : 0;
        $context = trim($_POST['additional_context'] ?? '');

        if ($exam_id <= 0) {
            $error = "Please select a valid exam.";
        } elseif (empty($subject)) {
            $error = "Subject field is required.";
        } elseif (empty($difficulty) || !in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            $error = "Please select a valid difficulty level.";
        } elseif ($num_questions_raw === '' || $num_questions < 1 || $num_questions > 100) {
            $error = "Number of questions must be between 1 and 100.";
        } else {
            // ATOMIC RACE-CONDITION PROOF STUDENT-ATTEMPT LOCK GUARD
            $conn->begin_transaction();
            try {
                // 1. Lock exam row for atomic evaluation
                $lockStmt = $conn->prepare("SELECT id, title FROM exams WHERE id = ? FOR UPDATE");
                $lockStmt->bind_param("i", $exam_id);
                $lockStmt->execute();
                $examData = $lockStmt->get_result()->fetch_assoc();
                $lockStmt->close();

                if (!$examData) {
                    throw new Exception("Selected exam does not exist.");
                }

                if (is_exam_locked_for_ai($conn, $exam_id)) {
                    throw new Exception("AI question generation and updates are locked because a student has started attempting this exam.");
                }

                // 2. Retrieve existing questions for target exam to prevent duplicates
                $existingNormalized = [];

                // 2a. From active question bank for this exam
                $stmtExQ = $conn->prepare("SELECT question FROM questions WHERE exam_id = ?");
                $stmtExQ->bind_param("i", $exam_id);
                $stmtExQ->execute();
                $resExQ = $stmtExQ->get_result();
                while ($row = $resExQ->fetch_assoc()) {
                    $norm = normalize_question_text($row['question']);
                    if ($norm !== '') {
                        $existingNormalized[] = $norm;
                    }
                }
                $stmtExQ->close();

                // 2b. From AI generated review records for this exam
                $stmtExAI = $conn->prepare("SELECT question FROM ai_generated_questions WHERE exam_id = ?");
                $stmtExAI->bind_param("i", $exam_id);
                $stmtExAI->execute();
                $resExAI = $stmtExAI->get_result();
                while ($row = $resExAI->fetch_assoc()) {
                    $norm = normalize_question_text($row['question']);
                    if ($norm !== '') {
                        $existingNormalized[] = $norm;
                    }
                }
                $stmtExAI->close();

                $existingNormalized = array_values(array_unique($existingNormalized));

                $conn->commit();

                // 3. Call AI Microservice outside lock
                $response = $aiClient->generateQuestions($subject, $topic, $difficulty, $num_questions, $context);

                if (!$response['success']) {
                    $req_id = "req_fail_" . uniqid();
                    $conn->begin_transaction();
                    $stmt = $conn->prepare("INSERT INTO ai_generation_requests (request_id, admin_id, exam_id, subject, topic, difficulty, question_type, number_requested, additional_context, model_used, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'failed', ?)");
                    $model = 'fastapi-ai-service';
                    $errMsg = (string)($response['error'] ?? ($response['message'] ?? 'AI Service request failed.'));
                    $stmt->bind_param("siisssissss", $req_id, $admin_id, $exam_id, $subject, $topic, $difficulty, $question_type, $num_questions, $context, $model, $errMsg);
                    $stmt->execute();
                    $stmt->close();
                    $conn->commit();

                    $error = "AI Generation Failed: " . htmlspecialchars($errMsg);
                } else {
                    $data = $response['data'];
                    $request_id = $data['request_id'] ?? ("req_" . uniqid());
                    $model_used = $data['model_used'] ?? 'gpt-4o-mini';
                    $generated_questions = $data['questions'] ?? [];

                    $conn->begin_transaction();

                    // Log generation request
                    $stmtReq = $conn->prepare("INSERT INTO ai_generation_requests (request_id, admin_id, exam_id, subject, topic, difficulty, question_type, number_requested, additional_context, model_used, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'success')");
                    $stmtReq->bind_param("siisssisss", $request_id, $admin_id, $exam_id, $subject, $topic, $difficulty, $question_type, $num_questions, $context, $model_used);
                    $stmtReq->execute();
                    $stmtReq->close();

                    // Insert unique questions into staging table (status = pending)
                    $inserted_count = 0;
                    $skipped_duplicate_count = 0;
                    $stmtIns = $conn->prepare("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, difficulty, question_type, question, option_a, option_b, option_c, option_d, correct_option, explanation, generation_model, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");

                    foreach ($generated_questions as $gq) {
                        $qRaw = $gq['question'] ?? '';
                        $qText = clean_question_title($qRaw);
                        if ($qText === '') {
                            continue;
                        }

                        // Duplicate check scoped to target exam
                        if (is_question_duplicate($qText, $existingNormalized)) {
                            $skipped_duplicate_count++;
                            continue;
                        }

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
                            // Add into normalized pool to avoid intra-batch duplicates
                            $existingNormalized[] = normalize_question_text($qText);
                        }
                    }
                    $stmtIns->close();

                    $conn->commit();

                    if ($inserted_count > 0) {
                        if ($skipped_duplicate_count > 0) {
                            $_SESSION['success'] = "Generated {$inserted_count} unique AI question(s) successfully ({$skipped_duplicate_count} duplicate(s) skipped for this exam). Review and approve them below.";
                        } else {
                            $_SESSION['success'] = "Generated {$inserted_count} AI questions successfully! Review and approve them below.";
                        }
                        header("Location: review_ai_questions.php?request_id=" . urlencode($request_id) . "&exam_id=" . $exam_id);
                        exit;
                    } else {
                        if ($skipped_duplicate_count > 0) {
                            $error = "No new unique questions could be generated. All {$skipped_duplicate_count} question(s) were skipped as duplicates for this exam.";
                        } else {
                            $error = "No valid questions were returned by the AI service.";
                        }
                    }
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

        <?php if (!$is_ai_service_online): ?>
            <div style="background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; padding: 14px 18px; border-radius: 6px; margin-bottom: 20px; color: #92400e;">
                ⚠️ <strong>AI Microservice Offline:</strong> The FastAPI AI service is currently not responding at <code><?= htmlspecialchars($aiClient->getBaseUrl()) ?></code>.
                To start it, open a terminal in <code>ai-service</code> and run:
                <code>.\.venv\Scripts\uvicorn.exe app.main:app --host 127.0.0.1 --port 8001</code>
            </div>
        <?php endif; ?>

        <!-- DYNAMIC EXAM AI STATUS EXPLANATION BOX -->
        <div id="aiStatusBox" style="display: none; padding: 18px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; font-size: 15px; line-height: 1.5;">
            <!-- JS Populated -->
        </div>

        <div class="info-card">
            💡 <strong>Admin Review Protection & Attempt Lock Rule:</strong> Questions generated by AI do not automatically appear in student exams. You can review, edit, approve, or reject them. AI question generation and updates are available <strong>until a student starts this exam</strong>.
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
                                    <?= (int)$history_exam_id === (int)$ex['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ex['title']) ?>
                                <?= $ex['ai_status'] === 'locked' ? ' (Locked - Student Attempt In Progress)' : '' ?>
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
                        <label>Topic (Optional)</label>
                        <input type="text" name="topic" id="topic" placeholder="e.g. Data Structures, OOP, SQL Queries" value="<?= htmlspecialchars($_POST['topic'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Difficulty *</label>
                        <select name="difficulty" id="difficulty" required>
                            <option value="" <?= empty($_POST['difficulty']) ? 'selected' : '' ?>>Choose Difficulty</option>
                            <option value="easy" <?= ($_POST['difficulty'] ?? '') === 'easy' ? 'selected' : '' ?>>Easy</option>
                            <option value="medium" <?= ($_POST['difficulty'] ?? '') === 'medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="hard" <?= ($_POST['difficulty'] ?? '') === 'hard' ? 'selected' : '' ?>>Hard</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Number of Questions (1-100) *</label>
                        <input type="number" name="number_of_questions" id="number_of_questions" min="1" max="100" placeholder="Enter number of questions (1-100)" value="<?= htmlspecialchars($_POST['number_of_questions'] ?? '') ?>" required>
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

        <!-- PREVIOUSLY GENERATED QUESTIONS SECTION -->
        <div class="gen-container" id="history-section" style="margin-top: 35px; border-top: 3px solid #1e88e5;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h2 style="margin: 0; color: #1e293b; font-size: 20px;">📋 Previously Generated Questions</h2>
                    <p style="margin: 5px 0 0 0; color: #64748b; font-size: 14px;">Browse previously generated AI questions filtered by exam. Review status and answers are preserved.</p>
                </div>
                <?php if ($history_exam_id > 0): ?>
                    <a href="review_ai_questions.php?status=pending&exam_id=<?= $history_exam_id ?>" style="background: #0d6efd; color: white; padding: 8px 16px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px;">📋 Open Review Queue</a>
                <?php endif; ?>
            </div>

            <!-- EXAM & CRITERIA FILTER BAR FOR HISTORY -->
            <form method="GET" action="ai_question_generator.php" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; margin-bottom: 22px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; align-items: flex-end;">
                    <div>
                        <label for="history_exam_id" style="display: block; font-weight: 600; color: #1e293b; font-size: 13px; margin-bottom: 6px;">Select Exam *</label>
                        <select name="history_exam_id" id="history_exam_id" onchange="this.form.submit()" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; background: white; box-sizing: border-box;">
                            <option value="0">-- Select an Exam --</option>
                            <?php foreach ($exams_list as $ex): ?>
                                <option value="<?= $ex['id'] ?>" <?= (int)$history_exam_id === (int)$ex['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ex['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="history_subject" style="display: block; font-weight: 600; color: #1e293b; font-size: 13px; margin-bottom: 6px;">Subject Filter</label>
                        <input type="text" name="history_subject" id="history_subject" value="<?= htmlspecialchars($history_subject) ?>" placeholder="e.g. English, Maths" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; background: white; box-sizing: border-box;">
                    </div>

                    <div>
                        <label for="history_status" style="display: block; font-weight: 600; color: #1e293b; font-size: 13px; margin-bottom: 6px;">Review Status</label>
                        <select name="history_status" id="history_status" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; background: white; box-sizing: border-box;">
                            <option value="all" <?= $history_status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="pending" <?= $history_status === 'pending' ? 'selected' : '' ?>>⏳ Pending</option>
                            <option value="approved" <?= $history_status === 'approved' ? 'selected' : '' ?>>✅ Approved</option>
                            <option value="rejected" <?= $history_status === 'rejected' ? 'selected' : '' ?>>❌ Rejected</option>
                        </select>
                    </div>

                    <div>
                        <label for="history_search" style="display: block; font-weight: 600; color: #1e293b; font-size: 13px; margin-bottom: 6px;">Search Questions / Topic</label>
                        <input type="text" name="history_search" id="history_search" value="<?= htmlspecialchars($history_search) ?>" placeholder="Keywords..." style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; background: white; box-sizing: border-box;">
                    </div>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 14px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">
                    <?php if (!empty($history_subject) || (!empty($history_status) && $history_status !== 'all') || !empty($history_search)): ?>
                        <a href="ai_question_generator.php?history_exam_id=<?= (int)$history_exam_id ?>#history-section" style="padding: 9px 16px; background: #e2e8f0; color: #334155; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600;">🔄 Reset Filters</a>
                    <?php endif; ?>
                    <button type="submit" style="padding: 9px 20px; background: #1e88e5; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">🔍 Filter Questions</button>
                </div>
            </form>

            <?php if ($history_exam_id <= 0): ?>
                <div style="text-align: center; padding: 40px 20px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; color: #64748b;">
                    <p style="font-size: 16px; margin: 0 0 8px 0; font-weight: 500;">Please select an exam from the dropdown above to display its previously generated questions.</p>
                    <p style="font-size: 13px; margin: 0; color: #94a3b8;">Questions will show serial numbers, subject, topic, difficulty, options, correct answer, and review status.</p>
                </div>
            <?php elseif ($history_total_count === 0 || empty($history_questions)): ?>
                <div style="text-align: center; padding: 35px 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; color: #64748b;">
                    <p style="font-size: 15px; margin: 0; font-style: italic; color: #475569;">No questions found for this exam.<?= (!empty($history_subject) || $history_status !== 'all' || !empty($history_search)) ? ' (Matching filter criteria)' : '' ?></p>
                    <?php if (!empty($history_subject) || $history_status !== 'all' || !empty($history_search)): ?>
                        <p style="font-size: 13px; margin: 8px 0 0 0; color: #94a3b8;">
                            <a href="<?= htmlspecialchars(get_history_page_url(1, $history_exam_id, '', 'all', '')) ?>" style="color: #1e88e5; text-decoration: none; font-weight: 600;">Clear filters</a> to view all questions for this exam.
                        </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; font-size: 13px; color: #64748b; flex-wrap: wrap; gap: 10px;">
                    <span>Showing <strong><?= $history_offset + 1 ?></strong> to <strong><?= min($history_offset + $history_limit, $history_total_count) ?></strong> of <strong><?= $history_total_count ?></strong> question(s)</span>
                    <span>Page <strong><?= $history_page ?></strong> of <strong><?= $history_total_pages ?></strong></span>
                </div>

                <?php foreach ($history_questions as $idx => $vq): ?>
                    <?php $sl_no = $history_offset + $idx + 1; ?>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; margin-bottom: 18px; border-left: 5px solid <?= $vq['status'] === 'approved' ? '#10b981' : ($vq['status'] === 'rejected' ? '#ef4444' : '#f59e0b') ?>;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <span style="background: #1e293b; color: white; padding: 3px 8px; border-radius: 6px; font-size: 12px; font-weight: bold; margin-right: 6px;">#<?= $sl_no ?></span>

                                <?php if ($vq['status'] === 'pending'): ?>
                                    <span style="background: #fef3c7; color: #92400e; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; text-transform: uppercase;">⏳ PENDING</span>
                                <?php elseif ($vq['status'] === 'approved'): ?>
                                    <span style="background: #d1fae5; color: #065f46; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; text-transform: uppercase;">✅ APPROVED</span>
                                <?php else: ?>
                                    <span style="background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; text-transform: uppercase;">❌ REJECTED</span>
                                <?php endif; ?>

                                <span style="background: #e0e7ff; color: #3730a3; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; margin-left: 6px;"><?= htmlspecialchars($vq['subject']) ?></span>
                                <span style="background: #f3f4f6; color: #374151; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; margin-left: 6px;"><?= htmlspecialchars($vq['topic']) ?></span>
                                <span style="background: #f1f5f9; color: #475569; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; margin-left: 6px;"><?= strtoupper(htmlspecialchars($vq['difficulty'])) ?></span>
                            </div>
                            <span style="font-size: 12px; color: #94a3b8;">
                                <?= !empty($vq['created_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime($vq['created_at']))) : 'N/A' ?>
                            </span>
                        </div>

                        <div style="font-weight: 600; font-size: 16px; margin-bottom: 12px; color: #1e293b; line-height: 1.4;">
                            <?= htmlspecialchars(clean_question_title($vq['question'])) ?>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 14px; margin-bottom: 12px;">
                            <div style="padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 4px; <?= $vq['correct_option']==='A' ? 'background:#ecfdf5; border-color:#10b981; font-weight:bold; color:#047857;' : 'background:#ffffff;' ?>">
                                <strong>A:</strong> <?= htmlspecialchars($vq['option_a']) ?> <?= $vq['correct_option']==='A' ? ' <span style="font-size:12px; color:#047857;">(Correct)</span>' : '' ?>
                            </div>
                            <div style="padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 4px; <?= $vq['correct_option']==='B' ? 'background:#ecfdf5; border-color:#10b981; font-weight:bold; color:#047857;' : 'background:#ffffff;' ?>">
                                <strong>B:</strong> <?= htmlspecialchars($vq['option_b']) ?> <?= $vq['correct_option']==='B' ? ' <span style="font-size:12px; color:#047857;">(Correct)</span>' : '' ?>
                            </div>
                            <div style="padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 4px; <?= $vq['correct_option']==='C' ? 'background:#ecfdf5; border-color:#10b981; font-weight:bold; color:#047857;' : 'background:#ffffff;' ?>">
                                <strong>C:</strong> <?= htmlspecialchars($vq['option_c']) ?> <?= $vq['correct_option']==='C' ? ' <span style="font-size:12px; color:#047857;">(Correct)</span>' : '' ?>
                            </div>
                            <div style="padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 4px; <?= $vq['correct_option']==='D' ? 'background:#ecfdf5; border-color:#10b981; font-weight:bold; color:#047857;' : 'background:#ffffff;' ?>">
                                <strong>D:</strong> <?= htmlspecialchars($vq['option_d']) ?> <?= $vq['correct_option']==='D' ? ' <span style="font-size:12px; color:#047857;">(Correct)</span>' : '' ?>
                            </div>
                        </div>

                        <?php if (!empty($vq['explanation'])): ?>
                            <div style="background: #f0f9ff; border: 1px solid #bae6fd; padding: 10px 14px; border-radius: 6px; font-size: 13px; color: #0369a1;">
                                💡 <strong>Explanation:</strong> <?= htmlspecialchars($vq['explanation']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <!-- PAGINATION CONTROLS -->
                <?php if ($history_total_pages > 1 && $history_total_count > 0): ?>
                    <div style="display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 25px; flex-wrap: wrap;">
                        <?php if ($history_page > 1): ?>
                            <a href="<?= htmlspecialchars(get_history_page_url($history_page - 1, $history_exam_id, $history_subject, $history_status, $history_search)) ?>" style="padding: 8px 14px; background: #e2e8f0; color: #1e293b; text-decoration: none; border-radius: 4px; font-size: 13px; font-weight: 600;">&laquo; Previous</a>
                        <?php else: ?>
                            <span style="padding: 8px 14px; background: #f1f5f9; color: #94a3b8; border-radius: 4px; font-size: 13px; cursor: not-allowed;">&laquo; Previous</span>
                        <?php endif; ?>

                        <?php
                        $pages_to_show = [];
                        if ($history_total_pages <= 9) {
                            $pages_to_show = range(1, $history_total_pages);
                        } else {
                            $pages_to_show[] = 1;
                            $w_start = max(2, $history_page - 2);
                            $w_end = min($history_total_pages - 1, $history_page + 2);

                            if ($w_start > 2) {
                                $pages_to_show[] = '...';
                            }
                            for ($p = $w_start; $p <= $w_end; $p++) {
                                $pages_to_show[] = $p;
                            }
                            if ($w_end < $history_total_pages - 1) {
                                $pages_to_show[] = '...';
                            }
                            $pages_to_show[] = $history_total_pages;
                        }
                        ?>

                        <?php foreach ($pages_to_show as $pItem): ?>
                            <?php if ($pItem === '...'): ?>
                                <span style="padding: 8px 10px; color: #64748b; font-size: 13px;">...</span>
                            <?php elseif ((int)$pItem === (int)$history_page): ?>
                                <span style="padding: 8px 14px; background: #1e88e5; color: white; border-radius: 4px; font-size: 13px; font-weight: bold;"><?= $pItem ?></span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars(get_history_page_url($pItem, $history_exam_id, $history_subject, $history_status, $history_search)) ?>" style="padding: 8px 14px; background: #f1f5f9; color: #1e293b; text-decoration: none; border-radius: 4px; font-size: 13px; font-weight: 500; border: 1px solid #e2e8f0;"><?= $pItem ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <?php if ($history_page < $history_total_pages): ?>
                            <a href="<?= htmlspecialchars(get_history_page_url($history_page + 1, $history_exam_id, $history_subject, $history_status, $history_search)) ?>" style="padding: 8px 14px; background: #e2e8f0; color: #1e293b; text-decoration: none; border-radius: 4px; font-size: 13px; font-weight: 600;">Next &raquo;</a>
                        <?php else: ?>
                            <span style="padding: 8px 14px; background: #f1f5f9; color: #94a3b8; border-radius: 4px; font-size: 13px; cursor: not-allowed;">Next &raquo;</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
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

    if (title && (!subjectInput.value || subjectInput.value === title)) {
        subjectInput.value = title;
    }

    if (status === "locked") {
        statusBox.style.display = "block";
        statusBox.style.background = "#fee2e2";
        statusBox.style.color = "#991b1b";
        statusBox.style.borderLeft = "5px solid #ef4444";
        statusBox.innerHTML = "🔒 <strong>AI Question Generation Locked:</strong> AI question generation and updates are locked because a student has started attempting this exam.";
        btnSubmit.disabled = true;
    } else if (status === "available") {
        statusBox.style.display = "block";
        statusBox.style.background = "#eef2ff";
        statusBox.style.color = "#3730a3";
        statusBox.style.borderLeft = "5px solid #6366f1";
        statusBox.innerHTML = "✨ <strong>AI Question Generation Available:</strong> AI question generation and updates are available until a student starts this exam.";
        btnSubmit.disabled = false;
    } else {
        statusBox.style.display = "none";
        btnSubmit.disabled = false;
    }
}

function showLoading() {
    const btnSubmit = document.getElementById("btnSubmit");
    if (!btnSubmit || btnSubmit.disabled || btnSubmit.getAttribute("data-submitting") === "true") {
        return false;
    }
    btnSubmit.setAttribute("data-submitting", "true");
    btnSubmit.style.opacity = "0.6";
    btnSubmit.style.pointerEvents = "none";
    btnSubmit.innerText = "⚡ Generating AI Questions... Please wait";
    document.getElementById("loadingSpinner").style.display = "block";
    return true;
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
