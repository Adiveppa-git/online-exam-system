<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

$student_id = (int)$_SESSION['user_id'];
$aiClient = new AiClient();

$message = "";
$session_data = null;
$questions_data = [];
$completed = false;

// 1. Handle Starting a New Practice Session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_practice') {
    $subject = trim($_POST['subject'] ?? 'General');
    $topic = trim($_POST['topic'] ?? 'General');
    $difficulty = strtolower(trim($_POST['difficulty'] ?? 'medium'));
    $count = min(10, max(1, (int)($_POST['count'] ?? 5)));

    // Generate Targeted Practice MCQs via AI Client
    $gen_res = $aiClient->generateTargetedPractice($subject, $topic, $difficulty, $count);

    if ($gen_res['success'] === true && !empty($gen_res['data']['questions'])) {
        $questions = $gen_res['data']['questions'];
        $actual_count = len($questions);

        // Create practice session record in MySQL
        $stmt_s = $conn->prepare("INSERT INTO ai_practice_sessions (student_id, subject, topic, difficulty, total_questions, status) VALUES (?, ?, ?, ?, ?, 'in_progress')");
        $stmt_s->bind_param("isssi", $student_id, $subject, $topic, $difficulty, $actual_count);
        $stmt_s->execute();
        $session_id = $stmt_s->insert_id;

        // Save IMMUTABLE SNAPSHOT of generated practice questions into database
        $stmt_q = $conn->prepare("INSERT INTO ai_practice_answers (session_id, student_id, question_index, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, subject, topic, difficulty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($questions as $idx => $q) {
            $q_text = $q['question'];
            $opt_a = $q['options']['A'] ?? 'Option A';
            $opt_b = $q['options']['B'] ?? 'Option B';
            $opt_c = $q['options']['C'] ?? 'Option C';
            $opt_d = $q['options']['D'] ?? 'Option D';
            $corr = strtoupper($q['correct_answer'] ?? 'A');
            $expl = $q['explanation'] ?? '';

            $stmt_q->bind_param("iiissssssssss", $session_id, $student_id, $idx, $q_text, $opt_a, $opt_b, $opt_c, $opt_d, $corr, $expl, $subject, $topic, $difficulty);
            $stmt_q->execute();
        }

        header("Location: practice_session.php?session_id=" . $session_id);
        exit();
    } else {
        $message = "Failed to generate targeted practice questions. " . ($gen_res['message'] ?? '');
    }
}

// 2. Handle Submitting Practice Answers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_practice') {
    $session_id = (int)$_POST['session_id'];

    // Verify session ownership
    $stmt_chk = $conn->prepare("SELECT id, status FROM ai_practice_sessions WHERE id = ? AND student_id = ?");
    $stmt_chk->bind_param("ii", $session_id, $student_id);
    $stmt_chk->execute();
    $sess_info = $stmt_chk->get_result()->fetch_assoc();

    if ($sess_info && $sess_info['status'] === 'in_progress') {
        $answers = $_POST['answers'] ?? [];
        $total_score = 0;

        $stmt_up = $conn->prepare("UPDATE ai_practice_answers SET student_answer = ?, is_correct = ? WHERE session_id = ? AND id = ? AND student_id = ?");

        foreach ($answers as $ans_id => $stu_ans) {
            $ans_id = (int)$ans_id;
            $stu_ans = strtoupper(trim($stu_ans));

            // Fetch correct option server-side
            $c_chk = $conn->prepare("SELECT correct_option FROM ai_practice_answers WHERE id = ? AND session_id = ?");
            $c_chk->bind_param("ii", $ans_id, $session_id);
            $c_chk->execute();
            $corr_res = $c_chk->get_result()->fetch_assoc();

            if ($corr_res) {
                $is_corr = ($stu_ans === strtoupper($corr_res['correct_option'])) ? 1 : 0;
                if ($is_corr) $total_score++;

                $stmt_up->bind_param("siiii", $stu_ans, $is_corr, $session_id, $ans_id, $student_id);
                $stmt_up->execute();
            }
        }

        // Complete session
        $now = date('Y-m-d H:i:s');
        $stmt_done = $conn->prepare("UPDATE ai_practice_sessions SET status = 'completed', score = ?, completed_at = ? WHERE id = ? AND student_id = ?");
        $stmt_done->bind_param("isii", $total_score, $now, $session_id, $student_id);
        $stmt_done->execute();

        header("Location: practice_session.php?session_id=" . $session_id);
        exit();
    }
}

// 3. Load Active / Completed Session
if (isset($_GET['session_id'])) {
    $session_id = (int)$_GET['session_id'];

    $stmt_s = $conn->prepare("SELECT * FROM ai_practice_sessions WHERE id = ? AND student_id = ?");
    $stmt_s->bind_param("ii", $session_id, $student_id);
    $stmt_s->execute();
    $session_data = $stmt_s->get_result()->fetch_assoc();

    if ($session_data) {
        $completed = ($session_data['status'] === 'completed');

        $stmt_q = $conn->prepare("SELECT * FROM ai_practice_answers WHERE session_id = ? ORDER BY question_index ASC");
        $stmt_q->bind_param("i", $session_id);
        $stmt_q->execute();
        $res = $stmt_q->get_result();
        while ($row = $res->fetch_assoc()) {
            $questions_data[] = $row;
        }
    }
}

function len($arr) { return count($arr); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Targeted AI Practice Session - Student</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .practice-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }
        .correct-bg { background-color: #d1e7dd; border-color: #a3cfbb; }
        .incorrect-bg { background-color: #f8d7da; border-color: #f5c2c7; }
    </style>
</head>
<body class="bg-light">

<div class="d-flex">
    <?php include 'sidebar.php'; ?>

    <div class="container-fluid p-4" style="margin-left: 260px;">
        
        <?php if ($message): ?>
            <div class="alert alert-danger mb-4"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($session_data && !empty($questions_data)): ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="h3 fw-bold text-dark mb-1"><i class="fa-solid fa-pen-ruler text-primary me-2"></i>Targeted AI Practice Session</h2>
                    <p class="text-muted small mb-0">Topic: <strong><?= htmlspecialchars($session_data['topic']) ?></strong> (<?= htmlspecialchars($session_data['subject']) ?>) | Difficulty: <span class="badge bg-dark"><?= ucfirst($session_data['difficulty']) ?></span></p>
                </div>
                <div>
                    <?php if ($completed): ?>
                        <a href="personalized_learning.php" class="btn btn-success fw-semibold">
                            <i class="fa-solid fa-rotate me-2"></i>Return to Learning Plan (Recalculate)
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($completed): ?>
                <div class="alert alert-success p-4 rounded-3 shadow-sm mb-4">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h4 class="fw-bold mb-1"><i class="fa-solid fa-trophy text-warning me-2"></i>Practice Session Completed!</h4>
                            <p class="mb-0">Your answers have been stored and your topic performance profile updated.</p>
                        </div>
                        <div class="text-center">
                            <div class="display-6 fw-bold text-success"><?= $session_data['score'] ?> / <?= $session_data['total_questions'] ?></div>
                            <div class="small text-muted fw-semibold">Score Achieved</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="action" value="submit_practice">
                <input type="hidden" name="session_id" value="<?= $session_data['id'] ?>">

                <?php foreach ($questions_data as $idx => $q): ?>
                    <?php
                    $is_ans_corr = ($completed && $q['is_correct'] == 1);
                    $is_ans_incorr = ($completed && $q['is_correct'] == 0);
                    $card_class = $completed ? ($is_ans_corr ? 'correct-bg' : 'incorrect-bg') : '';
                    ?>
                    <div class="practice-card shadow-sm p-4 mb-4 <?= $card_class ?>">
                        <h6 class="fw-bold text-dark mb-3">Question <?= ($idx + 1) ?> of <?= count($questions_data) ?></h6>
                        <p class="fw-semibold text-dark fs-5 mb-4"><?= htmlspecialchars($q['question_text']) ?></p>

                        <div class="row g-3">
                            <?php foreach (['A' => $q['option_a'], 'B' => $q['option_b'], 'C' => $q['option_c'], 'D' => $q['option_d']] as $opt_key => $opt_val): ?>
                                <?php
                                $checked = ($completed && strtoupper($q['student_answer']) === $opt_key) ? 'checked' : '';
                                ?>
                                <div class="col-md-6">
                                    <div class="form-check p-3 border rounded bg-white">
                                        <input class="form-check-input me-2" type="radio" name="answers[<?= $q['id'] ?>]" value="<?= $opt_key ?>" id="q_<?= $q['id'] ?>_<?= $opt_key ?>" <?= $checked ?> <?= $completed ? 'disabled' : '' ?> required>
                                        <label class="form-check-label w-100 text-dark fw-medium" for="q_<?= $q['id'] ?>_<?= $opt_key ?>">
                                            <strong><?= $opt_key ?>.</strong> <?= htmlspecialchars($opt_val) ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($completed): ?>
                            <div class="mt-3 pt-3 border-top">
                                <div class="small fw-bold text-dark mb-1">
                                    <i class="fa-solid fa-lightbulb text-warning me-1"></i>Correct Answer: Option <?= $q['correct_option'] ?>
                                </div>
                                <div class="small text-secondary"><?= htmlspecialchars($q['explanation']) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if (!$completed): ?>
                    <button type="submit" class="btn btn-primary btn-lg fw-semibold px-5 mb-5">
                        <i class="fa-solid fa-check-double me-2"></i>Submit Practice Session Answers
                    </button>
                <?php endif; ?>
            </form>

        <?php else: ?>
            <div class="text-center py-5">
                <i class="fa-solid fa-pen-to-square fa-3x text-muted mb-3"></i>
                <h5 class="fw-bold">No Active Practice Session</h5>
                <p class="text-muted">Select a topic from Personalized Learning to start a targeted practice session.</p>
                <a href="personalized_learning.php" class="btn btn-primary fw-semibold mt-2">Go to Personalized Learning</a>
            </div>
        <?php endif; ?>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
