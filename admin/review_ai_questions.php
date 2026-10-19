<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
require_once __DIR__ . "/../config/db.php";

/* ===== ADMIN AUTH GUARD ===== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$admin_id = $_SESSION['user_id'] ?? 1;
$status_filter = $_GET['status'] ?? 'pending';
$request_id_filter = $_GET['request_id'] ?? '';
$exam_id_filter = (int)($_GET['exam_id'] ?? 0);
$redirect_url = "review_ai_questions.php?status=" . urlencode($status_filter) . ($request_id_filter ? "&request_id=" . urlencode($request_id_filter) : "") . ($exam_id_filter ? "&exam_id=" . $exam_id_filter : "");

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

/* ===== AUTO RECOVERY HELPER ===== */
if (!function_exists('auto_recover_missing_exam_ids')) {
    function auto_recover_missing_exam_ids($conn) {
        $sql = "UPDATE ai_generated_questions q
                JOIN ai_generation_requests r ON q.request_id = r.request_id
                JOIN exams e ON r.exam_id = e.id
                SET q.exam_id = r.exam_id
                WHERE q.status = 'pending'
                  AND (q.exam_id IS NULL OR q.exam_id = 0)
                  AND r.exam_id IS NOT NULL
                  AND r.exam_id > 0";
        $conn->query($sql);
    }
}

// Auto-recover any recoverable exam_ids on request load
auto_recover_missing_exam_ids($conn);

// Fetch all active exams for Target Exam selectors
$all_exams_res = $conn->query("SELECT id, title FROM exams ORDER BY title ASC");
$all_exams_list = [];
if ($all_exams_res) {
    while ($exRow = $all_exams_res->fetch_assoc()) {
        $all_exams_list[] = $exRow;
    }
}

/* ===== HANDLE POST ACTIONS (APPROVE / EDIT / REJECT) ===== */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // CSRF Validation
    $post_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $post_token)) {
        $_SESSION['error'] = "Invalid CSRF security token.";
        header("Location: " . $redirect_url);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $qid = (int)($_POST['question_id'] ?? 0);

    if ($action === 'bulk_approve') {
        $conn->begin_transaction();
        try {
            // 1. Auto-recover recoverable exam_ids from ai_generation_requests
            auto_recover_missing_exam_ids($conn);

            $targetExamId = (int)($_POST['exam_id'] ?? ($_GET['exam_id'] ?? 0));

            // 2. Fetch pending questions with row lock (scoped by targetExamId if filtered)
            if ($targetExamId > 0) {
                $pendingStmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE status = 'pending' AND exam_id = ? FOR UPDATE");
                $pendingStmt->bind_param("i", $targetExamId);
            } else {
                $pendingStmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE status = 'pending' FOR UPDATE");
            }
            $pendingStmt->execute();
            $pendingRes = $pendingStmt->get_result();
            $pending_questions = [];
            while ($row = $pendingRes->fetch_assoc()) {
                $pending_questions[] = $row;
            }
            $pendingStmt->close();

            if (empty($pending_questions)) {
                throw new Exception("No pending questions found to approve.");
            }

            // 3. Pre-validate ALL pending questions BEFORE making any insertions
            // Note: Only questions whose persisted status is 'pending' are considered.
            // Rejected or approved records never block bulk approval.
            $invalidQuestionIds = [];
            $examCheckCache = [];

            foreach ($pending_questions as $gq) {
                if ($gq['status'] !== 'pending') {
                    continue;
                }
                $qIdCheck = (int)$gq['id'];
                $eIdCheck = (int)($gq['exam_id'] ?? 0);

                if ($eIdCheck <= 0) {
                    $invalidQuestionIds[] = $qIdCheck;
                    continue;
                }

                if (!isset($examCheckCache[$eIdCheck])) {
                    $chkStmt = $conn->prepare("SELECT id FROM exams WHERE id = ?");
                    $chkStmt->bind_param("i", $eIdCheck);
                    $chkStmt->execute();
                    $examCheckCache[$eIdCheck] = ($chkStmt->get_result()->num_rows > 0);
                    $chkStmt->close();
                }

                if (!$examCheckCache[$eIdCheck]) {
                    $invalidQuestionIds[] = $qIdCheck;
                }
            }

            if (!empty($invalidQuestionIds)) {
                $affectedList = "#" . implode(", #", $invalidQuestionIds);
                throw new Exception("Bulk approval aborted: Pending question(s) {$affectedList} do not have a valid assigned target exam ID. Please assign a valid target exam or reject these questions before bulk publishing.");
            }

            // 4. Execution phase: All questions are pre-validated, insert into active questions table
            $insStmt = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option, subject, topic, difficulty, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $updStmt = $conn->prepare("UPDATE ai_generated_questions SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");

            $count = 0;

            foreach ($pending_questions as $gq) {
                $eId = (int)$gq['exam_id'];
                $diffToUse = strtolower(trim($gq['difficulty'] ?? 'medium'));
                if (!in_array($diffToUse, ['easy', 'medium', 'hard'], true)) {
                    $diffToUse = 'medium';
                }
                $subjToUse = trim($gq['subject'] ?? '') ?: 'General';
                $topicToUse = trim($gq['topic'] ?? '');
                $expToUse = trim($gq['explanation'] ?? '');

                $cleanQ = clean_question_title($gq['question']);
                $insStmt->bind_param("issssssssss",
                    $eId, $cleanQ, $gq['option_a'], $gq['option_b'], $gq['option_c'], $gq['option_d'],
                    $gq['correct_option'], $subjToUse, $topicToUse, $diffToUse, $expToUse
                );
                if (!$insStmt->execute()) {
                    throw new Exception("Failed to insert question #" . $gq['id'] . ": " . ($insStmt->error ?: $conn->error));
                }

                $updStmt->bind_param("ii", $admin_id, $gq['id']);
                if (!$updStmt->execute()) {
                    throw new Exception("Failed to update status for question #" . $gq['id']);
                }

                $count++;
            }

            $insStmt->close();
            $updStmt->close();

            $conn->commit();
            $_SESSION['success'] = "Successfully approved and published {$count} pending question(s) into their assigned exams!";
        } catch (Exception $ex) {
            $conn->rollback();
            $_SESSION['error'] = "Bulk Approval Transaction Failed: " . $ex->getMessage();
        }
        if (!headers_sent()) {
            header("Location: " . $redirect_url);
        }
        exit;
    }

    if ($action === 'bulk_reject') {
        $conn->begin_transaction();
        try {
            $targetExamId = (int)($_POST['exam_id'] ?? ($_GET['exam_id'] ?? 0));

            if ($targetExamId > 0) {
                $pendingStmt = $conn->prepare("SELECT id FROM ai_generated_questions WHERE status = 'pending' AND exam_id = ? FOR UPDATE");
                $pendingStmt->bind_param("i", $targetExamId);
            } else {
                $pendingStmt = $conn->prepare("SELECT id FROM ai_generated_questions WHERE status = 'pending' FOR UPDATE");
            }
            $pendingStmt->execute();
            $pendingRes = $pendingStmt->get_result();
            $pendingCount = $pendingRes->num_rows;
            $pendingStmt->close();

            if ($pendingCount === 0) {
                throw new Exception("No pending questions found to reject.");
            }

            if ($targetExamId > 0) {
                $updStmt = $conn->prepare("UPDATE ai_generated_questions SET status = 'rejected', rejection_reason = 'Bulk rejected by admin', reviewed_by = ?, reviewed_at = NOW() WHERE status = 'pending' AND exam_id = ?");
                $updStmt->bind_param("ii", $admin_id, $targetExamId);
            } else {
                $updStmt = $conn->prepare("UPDATE ai_generated_questions SET status = 'rejected', rejection_reason = 'Bulk rejected by admin', reviewed_by = ?, reviewed_at = NOW() WHERE status = 'pending'");
                $updStmt->bind_param("i", $admin_id);
            }
            $updStmt->execute();
            $affected = $updStmt->affected_rows;
            $updStmt->close();

            $conn->commit();
            $_SESSION['success'] = "Successfully marked {$affected} pending question(s) as rejected!";
        } catch (Exception $ex) {
            $conn->rollback();
            $_SESSION['error'] = "Bulk Rejection Failed: " . $ex->getMessage();
        }
        if (!headers_sent()) {
            header("Location: " . $redirect_url);
        }
        exit;
    }

    if ($action === 'approve') {
        $conn->begin_transaction();
        try {
            auto_recover_missing_exam_ids($conn);

            // Fetch pending question with row lock
            $qStmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE id = ? AND status = 'pending' FOR UPDATE");
            $qStmt->bind_param("i", $qid);
            $qStmt->execute();
            $gq = $qStmt->get_result()->fetch_assoc();
            $qStmt->close();

            if (!$gq) {
                throw new Exception("Question not found or already reviewed.");
            }

            $eId = (int)($gq['exam_id'] ?? 0);
            $overrideExamId = (int)($_POST['exam_id'] ?? 0);
            if ($overrideExamId > 0) {
                $eId = $overrideExamId;
            }

            if (!$eId) {
                throw new Exception("Pending question #" . $qid . " does not have a valid assigned target exam ID. Please select a valid target exam or reject this question.");
            }

            $chkExamStmt = $conn->prepare("SELECT id FROM exams WHERE id = ?");
            $chkExamStmt->bind_param("i", $eId);
            $chkExamStmt->execute();
            $examExists = ($chkExamStmt->get_result()->num_rows > 0);
            $chkExamStmt->close();

            if (!$examExists) {
                throw new Exception("Pending question #" . $qid . " references exam ID #" . $eId . ", which no longer exists.");
            }

            $diffInput = strtolower(trim($_POST['difficulty'] ?? ''));
            $difficultyToUse = in_array($diffInput, ['easy', 'medium', 'hard'], true) ? $diffInput : strtolower(trim($gq['difficulty'] ?? 'medium'));
            if (!in_array($difficultyToUse, ['easy', 'medium', 'hard'], true)) {
                $difficultyToUse = 'medium';
            }
            $subjToUse = trim($gq['subject'] ?? '') ?: 'General';
            $topicToUse = trim($gq['topic'] ?? '');
            $expToUse = trim($gq['explanation'] ?? '');

            // 1. Insert into active questions table
            $insStmt = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option, subject, topic, difficulty, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $cleanQ = clean_question_title($gq['question']);
            $insStmt->bind_param("issssssssss",
                $eId, $cleanQ, $gq['option_a'], $gq['option_b'], $gq['option_c'], $gq['option_d'],
                $gq['correct_option'], $subjToUse, $topicToUse, $difficultyToUse, $expToUse
            );

            if (!$insStmt->execute()) {
                throw new Exception("Failed to insert question: " . ($insStmt->error ?: $conn->error));
            }
            $insStmt->close();

            // 2. Mark as approved, update exam_id and difficulty if modified, set reviewed_by
            $updStmt = $conn->prepare("UPDATE ai_generated_questions SET exam_id = ?, status = 'approved', difficulty = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
            $updStmt->bind_param("isii", $eId, $difficultyToUse, $admin_id, $qid);

            if (!$updStmt->execute() || $updStmt->affected_rows === 0) {
                throw new Exception("Failed to update status to approved.");
            }
            $updStmt->close();

            $conn->commit();
            $_SESSION['success'] = "Question approved and published to active exam question bank!";
        } catch (Exception $ex) {
            $conn->rollback();
            $_SESSION['error'] = "Approval Transaction Failed: " . $ex->getMessage();
        }
        if (!headers_sent()) {
            header("Location: " . $redirect_url);
        }
        exit;
    }

    if ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($reason === '') {
            $reason = 'Rejected by admin';
        }
        $updStmt = $conn->prepare("UPDATE ai_generated_questions SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
        $updStmt->bind_param("sii", $reason, $admin_id, $qid);
        $updStmt->execute();

        if ($updStmt->affected_rows > 0) {
            $_SESSION['success'] = "Question marked as rejected.";
        } else {
            $_SESSION['error'] = "Question could not be rejected (already reviewed).";
        }
        if (!headers_sent()) {
            header("Location: " . $redirect_url);
        }
        exit;
    }

    if ($action === 'generate_notes') {
        require_once "../config/ai_client.php";
        require_once "../config/study_material_service.php";

        // Deduplication & DB Caching Check
        $cachedNotes = null;
        try {
            $stmtChk = $conn->prepare("SELECT * FROM question_study_notes WHERE ai_question_id = ? ORDER BY id DESC LIMIT 1");
            if ($stmtChk) {
                $stmtChk->bind_param("i", $qid);
                $stmtChk->execute();
                $cachedNotes = $stmtChk->get_result()->fetch_assoc();
            }
        } catch (Exception $ex) {
            // Table may not be migrated yet
        }

        if ($cachedNotes) {
            $_SESSION['success'] = "Loaded cached focused study notes for question #" . $qid . ".";
            $_SESSION['generated_notes_' . $qid] = [
                'title' => $cachedNotes['concept_title'],
                'content' => $cachedNotes['notes_content'],
                'grounded' => (bool)$cachedNotes['is_grounded']
            ];
        } else {
            $aiClient = new AiClient();
            $stmtQ = $conn->prepare("SELECT * FROM ai_generated_questions WHERE id = ?");
            $stmtQ->bind_param("i", $qid);
            $stmtQ->execute();
            $gq = $stmtQ->get_result()->fetch_assoc();

            if ($gq) {
                $notesRes = $aiClient->generateFocusedStudyNotes(
                    clean_question_title($gq['question']),
                    $gq['subject'],
                    $gq['topic'],
                    $gq['correct_option'],
                    $gq['explanation'] ?? '',
                    null,
                    $gq['id'],
                    null
                );

                if (!empty($notesRes['success']) || ($notesRes['data']['status'] ?? '') === 'success') {
                    $notesData = $notesRes['data'] ?? $notesRes;
                    $conceptTitle = $notesData['concept_title'] ?? ($gq['topic'] . ' Concept');
                    $notesContent = $notesData['notes_content'] ?? '';
                    $ragSources = json_encode($notesData['rag_sources'] ?? []);
                    $isGrounded = !empty($notesData['is_grounded']) ? 1 : 0;
                    $sourceCount = (int)($notesData['source_count'] ?? 0);

                    try {
                        $insNotes = $conn->prepare("INSERT INTO question_study_notes (ai_question_id, subject, topic, concept_title, notes_content, rag_sources, is_grounded, source_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        if ($insNotes) {
                            $insNotes->bind_param("isssssii", $qid, $gq['subject'], $gq['topic'], $conceptTitle, $notesContent, $ragSources, $isGrounded, $sourceCount);
                            $insNotes->execute();
                        }
                    } catch (Exception $ex) {
                        // Migration fallback
                    }

                    // Also index into course materials if exam is assigned
                    if (!empty($gq['exam_id']) && (int)$gq['exam_id'] > 0) {
                        try {
                            StudyMaterialService::generateAndIndex($conn, (int)$gq['exam_id'], $gq['subject'], $gq['topic'], $gq['status'], (int)($_SESSION['user_id'] ?? 1), true, [$qid]);
                        } catch (Throwable $matEx) {
                            // Non-critical
                        }
                    }

                    $_SESSION['success'] = "Generated study material for question #" . $qid . " and indexed into RAG course materials successfully!";
                    $_SESSION['generated_notes_' . $qid] = [
                        'title' => $conceptTitle,
                        'content' => $notesContent,
                        'grounded' => $isGrounded
                    ];
                } else {
                    $_SESSION['error'] = "Failed to generate study notes: " . ($notesRes['message'] ?? 'AI Service Error');
                }
            }
        }
        if (!headers_sent()) {
            header("Location: " . $redirect_url);
        }
        exit;
    }

    if ($action === 'edit') {
        $exam_id = (int)($_POST['exam_id'] ?? 0);
        $question = clean_question_title(trim($_POST['question'] ?? ''));
        $a = trim($_POST['option_a'] ?? '');
        $b = trim($_POST['option_b'] ?? '');
        $c = trim($_POST['option_c'] ?? '');
        $d = trim($_POST['option_d'] ?? '');
        $correct = $_POST['correct_option'] ?? 'A';
        $subject = trim($_POST['subject'] ?? 'General');
        $topic = trim($_POST['topic'] ?? 'General');
        $difficulty = $_POST['difficulty'] ?? 'medium';
        $explanation = trim($_POST['explanation'] ?? '');

        if (empty($question) || empty($a) || empty($b) || empty($c) || empty($d)) {
            $_SESSION['error'] = "All question and option fields are required.";
        } elseif ($exam_id <= 0) {
            $_SESSION['error'] = "Please select a valid target exam.";
        } else {
            // Verify exam exists in exams table
            $chkExam = $conn->prepare("SELECT id FROM exams WHERE id = ?");
            $chkExam->bind_param("i", $exam_id);
            $chkExam->execute();
            $examExists = ($chkExam->get_result()->num_rows > 0);
            $chkExam->close();

            if (!$examExists) {
                $_SESSION['error'] = "Selected target exam does not exist.";
            } else {
                $updStmt = $conn->prepare("UPDATE ai_generated_questions SET exam_id = ?, question = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_option = ?, subject = ?, topic = ?, difficulty = ?, explanation = ? WHERE id = ? AND status = 'pending'");
                $updStmt->bind_param("issssssssssi", $exam_id, $question, $a, $b, $c, $d, $correct, $subject, $topic, $difficulty, $explanation, $qid);
                $updStmt->execute();
                $updStmt->close();

                $_SESSION['success'] = "AI Question details updated successfully.";
            }
        }
        if (!headers_sent()) {
            header("Location: " . $redirect_url);
        }
        exit;
    }

    if ($action === 'assign_exam') {
        $target_exam_id = (int)($_POST['exam_id'] ?? 0);

        if ($qid <= 0) {
            $_SESSION['error'] = "Invalid question ID specified.";
        } elseif ($target_exam_id <= 0) {
            $_SESSION['error'] = "Please select a valid target exam.";
        } else {
            // Verify target exam exists in database
            $chkExam = $conn->prepare("SELECT id, title FROM exams WHERE id = ?");
            $chkExam->bind_param("i", $target_exam_id);
            $chkExam->execute();
            $examRow = $chkExam->get_result()->fetch_assoc();
            $chkExam->close();

            if (!$examRow) {
                $_SESSION['error'] = "Selected target exam does not exist.";
            } else {
                // Verify question exists and is in pending status
                $chkQ = $conn->prepare("SELECT id, status FROM ai_generated_questions WHERE id = ?");
                $chkQ->bind_param("i", $qid);
                $chkQ->execute();
                $qRow = $chkQ->get_result()->fetch_assoc();
                $chkQ->close();

                if (!$qRow) {
                    $_SESSION['error'] = "Question #{$qid} not found.";
                } elseif ($qRow['status'] !== 'pending') {
                    $_SESSION['error'] = "Question #{$qid} is not pending (status: {$qRow['status']}).";
                } else {
                    $updStmt = $conn->prepare("UPDATE ai_generated_questions SET exam_id = ? WHERE id = ? AND status = 'pending'");
                    $updStmt->bind_param("ii", $target_exam_id, $qid);
                    $updStmt->execute();
                    $updStmt->close();

                    $_SESSION['success'] = "Question #{$qid} assigned to target exam '" . htmlspecialchars($examRow['title']) . "' successfully.";
                }
            }
        }
        if (!headers_sent()) {
            header("Location: " . $redirect_url);
        }
        exit;
    }
}

/* ===== FETCH AI GENERATED QUESTIONS ===== */
$query = "SELECT gq.*, e.title AS exam_title
          FROM ai_generated_questions gq
          LEFT JOIN exams e ON gq.exam_id = e.id
          WHERE 1=1";
$params = [];
$types = "";

if (!empty($status_filter) && $status_filter !== 'all') {
    $query .= " AND gq.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($request_id_filter)) {
    $query .= " AND gq.request_id = ?";
    $params[] = $request_id_filter;
    $types .= "s";
}

if ($exam_id_filter > 0) {
    $query .= " AND gq.exam_id = ?";
    $params[] = $exam_id_filter;
    $types .= "i";
}

$query .= " ORDER BY gq.id DESC";

$stmt = $conn->prepare($query);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$ai_questions = $stmt->get_result();

// Counts for filter tabs and warnings (consistently derived with status and exam filter)
$examWhere = ($exam_id_filter > 0) ? " AND exam_id = " . (int)$exam_id_filter : "";
$cnt_pending = (int)$conn->query("SELECT COUNT(*) AS c FROM ai_generated_questions WHERE status = 'pending'{$examWhere}")->fetch_assoc()['c'];
$cnt_approved = (int)$conn->query("SELECT COUNT(*) AS c FROM ai_generated_questions WHERE status = 'approved'{$examWhere}")->fetch_assoc()['c'];
$cnt_rejected = (int)$conn->query("SELECT COUNT(*) AS c FROM ai_generated_questions WHERE status = 'rejected'{$examWhere}")->fetch_assoc()['c'];
$cnt_all = (int)$conn->query("SELECT COUNT(*) AS c FROM ai_generated_questions WHERE 1=1{$examWhere}")->fetch_assoc()['c'];
$cnt_active = (int)$conn->query("SELECT COUNT(*) AS c FROM questions" . (($exam_id_filter > 0) ? " WHERE exam_id = " . (int)$exam_id_filter : ""))->fetch_assoc()['c'];

// List of unassigned or invalid pending questions for administrator warnings
// Strictly checks ONLY records whose persisted status is 'pending'.
// Rejected or approved records are never included in the warning.
$unassigned_pending_ids = [];
$unassigned_sql = "
    SELECT q.id
    FROM ai_generated_questions q
    LEFT JOIN exams e ON q.exam_id = e.id
    WHERE q.status = 'pending' AND (q.exam_id IS NULL OR q.exam_id = 0 OR e.id IS NULL)
";
if ($exam_id_filter > 0) {
    $unassigned_sql .= " AND q.exam_id = " . (int)$exam_id_filter;
}
$unassigned_sql .= " ORDER BY q.id ASC";

$unassigned_pending_res = $conn->query($unassigned_sql);
if ($unassigned_pending_res) {
    while ($uRow = $unassigned_pending_res->fetch_assoc()) {
        $unassigned_pending_ids[] = (int)$uRow['id'];
    }
}
$cnt_unassigned_pending = count($unassigned_pending_ids);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Review AI Questions</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <style>
        .filter-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
        }
        .filter-btn {
            padding: 10px 20px;
            border-radius: 20px;
            text-decoration: none;
            color: #4b5563;
            background: #e5e7eb;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
        }
        .filter-btn.active {
            background: #1e88e5;
            color: white;
        }
        .q-card {
            background: #ffffff;
            border-radius: 8px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border-left: 5px solid #cbd5e1;
        }
        .q-card.status-pending { border-left-color: #f59e0b; }
        .q-card.status-approved { border-left-color: #10b981; }
        .q-card.status-rejected { border-left-color: #ef4444; }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin-right: 8px;
            text-transform: uppercase;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #d1fae5; color: #065f46; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .badge-subject { background: #e0e7ff; color: #3730a3; }
        .badge-diff { background: #f3f4f6; color: #374151; }

        .q-title {
            font-size: 18px;
            font-weight: bold;
            margin: 15px 0 10px 0;
            color: #1f2937;
        }
        .options-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }
        .opt-item {
            padding: 10px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            background: #f9fafb;
        }
        .opt-item.is-correct {
            background: #ecfdf5;
            border-color: #10b981;
            font-weight: bold;
            color: #047857;
        }
        .exp-box {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            padding: 12px;
            border-radius: 6px;
            font-size: 13px;
            color: #0369a1;
            margin-bottom: 18px;
        }
        .action-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
            padding-top: 15px;
            border-top: 1px solid #f3f4f6;
        }
        .select-exam {
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            font-size: 14px;
        }
        .btn-action {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: bold;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-approve { background: #10b981; color: white; }
        .btn-edit { background: #3b82f6; color: white; }
        .btn-reject { background: #ef4444; color: white; }

        .edit-form {
            display: none;
            background: #f8fafc;
            padding: 20px;
            border-radius: 6px;
            margin-top: 15px;
            border: 1px solid #e2e8f0;
        }
        .edit-form input, .edit-form select, .edit-form textarea {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .alert-error { background: #fde8e8; color: #9b1c1c; padding: 12px; border-radius: 6px; margin-bottom: 15px; }
        .alert-success { background: #def7ec; color: #03543f; padding: 12px; border-radius: 6px; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="wrapper">
    <?php include "sidebar.php"; ?>

    <div class="content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
            <h1 style="margin: 0;">📋 AI Question Review Queue</h1>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <a href="manage_course_materials.php<?= $exam_id_filter > 0 ? '?exam_id=' . (int)$exam_id_filter : '' ?>" style="background: #0284c7; color: white; padding: 10px 18px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; display: inline-block;">📚 Generate Study Materials</a>
                <a href="manage_questions.php" style="background: #0d6efd; color: white; padding: 10px 18px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; display: inline-block;">&larr; Manage Questions</a>
            </div>
        </div>
        <p style="color: #666; margin-bottom: 20px;">Review AI-generated questions. Edit details if needed, adjust difficulty, then approve or reject.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- BULK ACTION BAR (Top of Pending Questions) -->
        <?php if ($cnt_pending > 0 && ($status_filter === 'pending' || $status_filter === 'all' || empty($status_filter))): ?>
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 15px 20px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                <div style="font-weight: 600; color: #166534; font-size: 15px;">
                    ⚡ <strong>Bulk Actions:</strong> Process all <strong><?= $cnt_pending ?></strong> pending question(s).
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="bulk_approve">
                        <?php if ($exam_id_filter > 0): ?>
                            <input type="hidden" name="exam_id" value="<?= (int)$exam_id_filter ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn-action btn-approve">
                            ✓ Approve & Publish All
                        </button>
                    </form>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="bulk_reject">
                        <?php if ($exam_id_filter > 0): ?>
                            <input type="hidden" name="exam_id" value="<?= (int)$exam_id_filter ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn-action btn-reject">
                            ✖ Reject All
                        </button>
                    </form>
                </div>
                <?php if ($cnt_unassigned_pending > 0): ?>
                    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 10px 14px; font-size: 13px; color: #92400e; width: 100%; margin-top: 5px;">
                        ⚠️ <strong>Action Required:</strong> <?= $cnt_unassigned_pending ?> pending question(s) (#<?= implode(', #', $unassigned_pending_ids) ?>) do not have a valid assigned target exam. Please assign a valid target exam or reject these questions before clicking <em>Approve & Publish All</em>.
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- EXAM FILTER & TABS -->
        <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div class="filter-bar" style="margin-bottom: 0;">
                <a href="review_ai_questions.php?status=pending<?= $exam_id_filter ? '&exam_id=' . $exam_id_filter : '' ?>" class="filter-btn <?= $status_filter==='pending'?'active':'' ?>">
                    Pending (<?= $cnt_pending ?>)
                </a>
                <a href="review_ai_questions.php?status=approved<?= $exam_id_filter ? '&exam_id=' . $exam_id_filter : '' ?>" class="filter-btn <?= $status_filter==='approved'?'active':'' ?>">
                    Approved (<?= $cnt_approved ?>)
                </a>
                <a href="review_ai_questions.php?status=rejected<?= $exam_id_filter ? '&exam_id=' . $exam_id_filter : '' ?>" class="filter-btn <?= $status_filter==='rejected'?'active':'' ?>">
                    Rejected (<?= $cnt_rejected ?>)
                </a>
                <a href="review_ai_questions.php?status=all<?= $exam_id_filter ? '&exam_id=' . $exam_id_filter : '' ?>" class="filter-btn <?= $status_filter==='all'?'active':'' ?>">
                    All (<?= $cnt_all ?>)
                </a>
            </div>

            <form method="GET" action="review_ai_questions.php" style="display: flex; gap: 8px; align-items: center; margin: 0;">
                <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                <label for="review_exam_id" style="font-weight: 600; color: #4b5563; font-size: 13px;">Filter Exam:</label>
                <select name="exam_id" id="review_exam_id" onchange="this.form.submit()" style="padding: 7px 12px; border-radius: 6px; border: 1px solid #d1d5db; font-size: 13px; background: white;">
                    <option value="0">-- All Exams --</option>
                    <?php foreach ($all_exams_list as $exOpt): ?>
                        <option value="<?= $exOpt['id'] ?>" <?= $exam_id_filter === (int)$exOpt['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($exOpt['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($exam_id_filter > 0): ?>
                    <a href="review_ai_questions.php?status=<?= urlencode($status_filter) ?>" style="font-size: 12px; color: #ef4444; text-decoration: none;">✕ Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 15px; margin-bottom: 20px; font-size: 13px; color: #475569;">
            💡 <strong>Historical Audit Record:</strong> The Approved count (<?= $cnt_approved ?>) reflects all historical AI review decisions. Active questions in the current exam bank (<?= $cnt_active ?> total) are listed under <a href="manage_questions.php" style="color: #0d6efd; font-weight: 600;">Manage Questions</a>.
        </div>

        <?php if ($ai_questions->num_rows === 0): ?>
            <div class="card" style="padding: 40px; text-align: center; color: #6b7280;">
                <h3>No questions found in this category.</h3>
                <p><a href="ai_question_generator.php" style="color: #1e88e5; font-weight: bold;">Click here to generate new AI questions.</a></p>
            </div>
        <?php else: ?>
            <?php $sl_no = 1; while($q = $ai_questions->fetch_assoc()): ?>
                <?php
                    $hasExam = !empty($q['exam_id']);
                    $hasValidExam = $hasExam && !empty($q['exam_title']);
                    $isPending = ($q['status'] === 'pending');
                ?>
                <div class="q-card status-<?= $q['status'] ?>">
                    <div>
                        <span class="badge" style="background: #1e293b; color: white;">#<?= $sl_no++ ?> (ID: #<?= (int)$q['id'] ?>)</span>
                        <span class="badge badge-<?= $q['status'] ?>"><?= strtoupper($q['status']) ?></span>
                        <?php if ($hasValidExam): ?>
                            <span class="badge badge-subject" style="background: #fef3c7; color: #92400e;">EXAM: <?= htmlspecialchars($q['exam_title']) ?></span>
                        <?php elseif ($isPending && $hasExam): ?>
                            <span class="badge" style="background: #fee2e2; color: #991b1b; border: 1px solid #f87171;">⚠️ Target Exam #<?= (int)$q['exam_id'] ?> (Nonexistent)</span>
                        <?php elseif ($isPending): ?>
                            <span class="badge" style="background: #fee2e2; color: #991b1b; border: 1px solid #f87171;">⚠️ Target Exam Missing (Unassigned)</span>
                        <?php elseif ($hasExam): ?>
                            <span class="badge badge-diff">Target Exam #<?= (int)$q['exam_id'] ?></span>
                        <?php else: ?>
                            <span class="badge badge-diff">Target Exam: Unassigned</span>
                        <?php endif; ?>
                        <span class="badge badge-subject"><?= htmlspecialchars($q['subject']) ?></span>
                        <span class="badge badge-subject"><?= htmlspecialchars($q['topic']) ?></span>
                        <span class="badge badge-diff"><?= strtoupper($q['difficulty']) ?></span>
                        <span style="font-size: 12px; color: #9ca3af; float: right;">Req ID: <?= htmlspecialchars($q['request_id']) ?></span>
                    </div>

                    <?php if ($isPending && !$hasValidExam): ?>
                        <!-- DIRECT TARGET EXAM ASSIGNMENT CONTROL -->
                        <div style="background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; border-radius: 6px; padding: 12px 16px; margin: 14px 0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                            <div style="color: #92400e; font-size: 13px; font-weight: 500;">
                                ⚠️ <strong>Target Exam Assignment Required:</strong> This pending question does not have a valid assigned target exam. Select an active exam to assign it.
                            </div>
                            <form method="POST" style="display: flex; gap: 8px; align-items: center; margin: 0; flex-wrap: wrap;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="action" value="assign_exam">
                                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                <select name="exam_id" class="select-exam" required style="padding: 7px 10px; font-size: 13px; border-color: #d97706; background: #fff; min-width: 180px;">
                                    <option value="">-- Select Target Exam --</option>
                                    <?php foreach ($all_exams_list as $exOpt): ?>
                                        <option value="<?= $exOpt['id'] ?>" <?= ((int)($q['exam_id'] ?? 0) === (int)$exOpt['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($exOpt['title']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn-action" style="background: #d97706; color: white;">💾 Save Target Exam</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="q-title"><?= htmlspecialchars(clean_question_title($q['question'])) ?></div>

                    <div class="options-grid">
                        <div class="opt-item <?= $q['correct_option']==='A'?'is-correct':'' ?>">
                            <strong>A:</strong> <?= htmlspecialchars($q['option_a']) ?>
                        </div>
                        <div class="opt-item <?= $q['correct_option']==='B'?'is-correct':'' ?>">
                            <strong>B:</strong> <?= htmlspecialchars($q['option_b']) ?>
                        </div>
                        <div class="opt-item <?= $q['correct_option']==='C'?'is-correct':'' ?>">
                            <strong>C:</strong> <?= htmlspecialchars($q['option_c']) ?>
                        </div>
                        <div class="opt-item <?= $q['correct_option']==='D'?'is-correct':'' ?>">
                            <strong>D:</strong> <?= htmlspecialchars($q['option_d']) ?>
                        </div>
                    </div>

                    <?php if (!empty($q['explanation'])): ?>
                        <div class="exp-box">
                            💡 <strong>Explanation:</strong> <?= htmlspecialchars($q['explanation']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($q['rejection_reason'])): ?>
                        <div style="background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 6px; font-size: 13px; margin-bottom: 15px;">
                            ❌ <strong>Rejection Reason:</strong> <?= htmlspecialchars($q['rejection_reason']) ?>
                        </div>
                    <?php endif; ?>

                    <!-- ACTIONS ROW -->
                    <div class="action-row">
                        <?php if ($isPending): ?>
                            <!-- APPROVE FORM -->
                            <form method="POST" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">

                                <?php if (!$hasValidExam): ?>
                                    <label style="font-size: 13px; font-weight: 600; color: #991b1b;">Assign Exam *:</label>
                                    <select name="exam_id" class="select-exam" style="padding: 6px 10px; font-size: 13px; border-color: #ef4444;" required>
                                        <option value="">-- Select Target Exam --</option>
                                        <?php foreach ($all_exams_list as $exOpt): ?>
                                            <option value="<?= $exOpt['id'] ?>"><?= htmlspecialchars($exOpt['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                
                                <label style="font-size: 13px; font-weight: 600; color: #374151;">Difficulty:</label>
                                <select name="difficulty" class="select-exam" style="padding: 6px 10px; font-size: 13px;">
                                    <option value="easy" <?= strtolower($q['difficulty'])==='easy'?'selected':'' ?>>Easy</option>
                                    <option value="medium" <?= strtolower($q['difficulty'])==='medium'?'selected':'' ?>>Medium</option>
                                    <option value="hard" <?= strtolower($q['difficulty'])==='hard'?'selected':'' ?>>Hard</option>
                                </select>
                                <button type="submit" class="btn-action btn-approve">✔ Approve & Publish</button>
                            </form>

                            <!-- EDIT TOGGLE BUTTON -->
                            <button type="button" class="btn-action btn-edit" onclick="toggleEdit(<?= $q['id'] ?>)">✏ Edit</button>

                            <!-- REJECT FORM -->
                            <form method="POST" onsubmit="const r = prompt('Reason for rejection (optional):'); if (r === null) return false; document.getElementById('rej_reason_<?= $q['id'] ?>').value = r; return true;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                <input type="hidden" name="rejection_reason" id="rej_reason_<?= $q['id'] ?>">
                                <button type="submit" class="btn-action btn-reject">✖ Reject</button>
                            </form>

                            <!-- GENERATE NOTES FORM -->
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="action" value="generate_notes">
                                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                <button type="submit" class="btn-action" style="background: #0284c7; color: white; border: none;">📘 Generate Study Materials</button>
                            </form>
                        <?php else: ?>
                            <!-- GENERATE NOTES FORM FOR APPROVED/REJECTED AS WELL -->
                            <form method="POST" style="display: inline-block; margin-right: 10px;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="action" value="generate_notes">
                                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                <button type="submit" class="btn-action" style="background: #0284c7; color: white; border: none;">📘 Generate Study Materials</button>
                            </form>
                            <span style="font-size: 13px; color: #6b7280;">
                                Reviewed at: <?= htmlspecialchars($q['reviewed_at'] ?? 'N/A') ?>
                            </span>
                            <?php if ($q['status'] === 'rejected' && !empty($q['rejection_reason'])): ?>
                                <span style="font-size: 13px; color: #dc2626; margin-left: 10px;">
                                    Reason: <?= htmlspecialchars($q['rejection_reason']) ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- INLINE EDIT FORM -->
                    <div class="edit-form" id="edit_form_<?= $q['id'] ?>">
                        <h4 style="margin-top: 0;">Edit AI Question Details</h4>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="question_id" value="<?= $q['id'] ?>">

                            <div style="margin-bottom: 12px;">
                                <label style="font-weight: 600; font-size: 13px;">Target Exam *</label>
                                <select name="exam_id" required style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                    <option value="">-- Select Target Exam --</option>
                                    <?php foreach ($all_exams_list as $exOpt): ?>
                                        <option value="<?= $exOpt['id'] ?>" <?= (int)($q['exam_id'] ?? 0) === (int)$exOpt['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($exOpt['title']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <label>Question Text</label>
                            <textarea name="question" required><?= htmlspecialchars(clean_question_title($q['question'])) ?></textarea>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div>
                                    <label>Option A</label>
                                    <input type="text" name="option_a" value="<?= htmlspecialchars($q['option_a']) ?>" required>
                                </div>
                                <div>
                                    <label>Option B</label>
                                    <input type="text" name="option_b" value="<?= htmlspecialchars($q['option_b']) ?>" required>
                                </div>
                                <div>
                                    <label>Option C</label>
                                    <input type="text" name="option_c" value="<?= htmlspecialchars($q['option_c']) ?>" required>
                                </div>
                                <div>
                                    <label>Option D</label>
                                    <input type="text" name="option_d" value="<?= htmlspecialchars($q['option_d']) ?>" required>
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-top: 10px;">
                                <div>
                                    <label>Correct Choice</label>
                                    <select name="correct_option" required>
                                        <option value="A" <?= $q['correct_option']==='A'?'selected':'' ?>>Option A</option>
                                        <option value="B" <?= $q['correct_option']==='B'?'selected':'' ?>>Option B</option>
                                        <option value="C" <?= $q['correct_option']==='C'?'selected':'' ?>>Option C</option>
                                        <option value="D" <?= $q['correct_option']==='D'?'selected':'' ?>>Option D</option>
                                    </select>
                                </div>
                                <div>
                                    <label>Difficulty</label>
                                    <select name="difficulty" required>
                                        <option value="easy" <?= strtolower($q['difficulty'])==='easy'?'selected':'' ?>>Easy</option>
                                        <option value="medium" <?= strtolower($q['difficulty'])==='medium'?'selected':'' ?>>Medium</option>
                                        <option value="hard" <?= strtolower($q['difficulty'])==='hard'?'selected':'' ?>>Hard</option>
                                    </select>
                                </div>
                                <div>
                                    <label>Subject</label>
                                    <input type="text" name="subject" value="<?= htmlspecialchars($q['subject']) ?>" required>
                                </div>
                            </div>

                            <div style="margin-top: 10px;">
                                <label>Topic</label>
                                <input type="text" name="topic" value="<?= htmlspecialchars($q['topic']) ?>" required>
                            </div>

                            <label style="margin-top: 10px;">Explanation</label>
                            <textarea name="explanation"><?= htmlspecialchars($q['explanation']) ?></textarea>

                            <div style="margin-top: 10px;">
                                <button type="submit" class="btn-action btn-approve">Save Changes</button>
                                <button type="button" class="btn-action" style="background:#9ca3af; color:white;" onclick="toggleEdit(<?= $q['id'] ?>)">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleEdit(id) {
    const el = document.getElementById("edit_form_" + id);
    if (el.style.display === "block") {
        el.style.display = "none";
    } else {
        el.style.display = "block";
    }
}
</script>

</body>
</html>
