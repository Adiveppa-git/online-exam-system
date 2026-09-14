<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db.php";
require_once "../config/ai_client.php";

/* ===== ADMIN AUTH GUARD ===== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

/* ===== HANDLE MANUAL DIFFICULTY OVERWRITE UPDATE ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_difficulty') {
    $post_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $post_token)) {
        $_SESSION['error'] = "Invalid CSRF security token.";
    } else {
        $qid = (int)($_POST['question_id'] ?? 0);
        $new_diff = $_POST['new_difficulty'] ?? 'medium';

        if (in_array($new_diff, ['easy', 'medium', 'hard'])) {
            $stmt = $conn->prepare("UPDATE questions SET difficulty = ? WHERE id = ?");
            $stmt->bind_param("si", $new_diff, $qid);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Question #{$qid} difficulty updated to " . strtoupper($new_diff) . " successfully!";
            } else {
                $_SESSION['error'] = "Failed to update question difficulty: " . $conn->error;
            }
        }
    }
    header("Location: ai_difficulty_analytics.php");
    exit;
}

/* ===== FETCH ACTIVE QUESTIONS & ATTEMPT STATS ===== */
$topic_acc_res = $conn->query("
    SELECT q.topic,
           COUNT(sa.id) AS total,
           SUM(CASE WHEN TRIM(sa.answer) = TRIM(q.correct_option) THEN 1 ELSE 0 END) AS correct
    FROM questions q
    JOIN student_answers sa ON q.id = sa.question_id
    GROUP BY q.topic
");
$topic_accuracies = [];
if ($topic_acc_res) {
    while ($t_row = $topic_acc_res->fetch_assoc()) {
        $t_tot = (int)$t_row['total'];
        $t_cor = (int)$t_row['correct'];
        if ($t_tot > 0) {
            $topic_accuracies[$t_row['topic']] = round(($t_cor / $t_tot) * 100, 1);
        }
    }
}

$subj_acc_res = $conn->query("
    SELECT q.subject,
           COUNT(sa.id) AS total,
           SUM(CASE WHEN TRIM(sa.answer) = TRIM(q.correct_option) THEN 1 ELSE 0 END) AS correct
    FROM questions q
    JOIN student_answers sa ON q.id = sa.question_id
    GROUP BY q.subject
");
$subject_accuracies = [];
if ($subj_acc_res) {
    while ($s_row = $subj_acc_res->fetch_assoc()) {
        $s_tot = (int)$s_row['total'];
        $s_cor = (int)$s_row['correct'];
        if ($s_tot > 0) {
            $subject_accuracies[$s_row['subject']] = round(($s_cor / $s_tot) * 100, 1);
        }
    }
}

$query = "
    SELECT q.id, q.question, q.subject, q.topic, q.difficulty AS assigned_difficulty, q.correct_option,
           COUNT(sa.id) AS total_attempts,
           SUM(CASE WHEN TRIM(sa.answer) = TRIM(q.correct_option) THEN 1 ELSE 0 END) AS correct_attempts,
           COUNT(DISTINCT sa.student_id) AS unique_students,
           e.title AS exam_title
    FROM questions q
    JOIN exams e ON q.exam_id = e.id
    LEFT JOIN student_answers sa ON q.id = sa.question_id
    GROUP BY q.id, q.question, q.subject, q.topic, q.difficulty, q.correct_option, e.title
    ORDER BY q.id ASC
";
$res = $conn->query($query);

$aiClient = new AiClient();
$analyzed_questions = [];

while ($res && $row = $res->fetch_assoc()) {
    $attempts = (int)$row['total_attempts'];
    $correct = (int)$row['correct_attempts'];
    $students = (int)$row['unique_students'];
    $correct_rate = ($attempts > 0) ? ($correct / $attempts) : 0.0;

    $topic = $row['topic'];
    $subject = $row['subject'];
    $topic_avg = isset($topic_accuracies[$topic]) ? $topic_accuracies[$topic] : 50.0;
    $subject_avg = isset($subject_accuracies[$subject]) ? $subject_accuracies[$subject] : 50.0;

    // Call ML Prediction endpoint
    $mlInput = [
        'question_id' => (int)$row['id'],
        'total_attempts' => $attempts,
        'correct_attempts' => $correct,
        'unique_students' => $students,
        'topic_avg_accuracy' => $topic_avg,
        'subject_avg_accuracy' => $subject_avg,
        'min_attempts_threshold' => 3
    ];

    $mlResp = $aiClient->predictQuestionDifficulty($mlInput);
    
    if ($mlResp['success']) {
        $mlData = $mlResp['data'];
        $pred_diff = $mlData['predicted_difficulty'];
        $conf = $mlData['confidence'];
        $status = $mlData['status'];
        $data_mode = $mlData['data_mode'] ?? 'synthetic_benchmark';
        $disclaimer = $mlData['disclaimer'] ?? 'Synthetic Benchmark — Pipeline Validation Only.';
    } else {
        // Offline Fallback
        if ($attempts < 3) {
            $pred_diff = 'insufficient_data';
            $conf = 0.0;
            $status = 'insufficient_real_data';
            $data_mode = 'insufficient_data';
            $disclaimer = 'Insufficient real student data available.';
        } else {
            $pred_diff = ($correct_rate >= 0.75) ? 'easy' : (($correct_rate < 0.45) ? 'hard' : 'medium');
            $conf = 0.85;
            $status = 'predicted';
            $data_mode = 'real_data_production';
            $disclaimer = 'Offline Real-Data Fallback Inference.';
        }
    }

    $row['predicted_difficulty'] = $pred_diff;
    $row['confidence'] = round($conf * 100, 1);
    $row['correct_rate_pct'] = round($correct_rate * 100, 1);
    $row['ml_status'] = $status;
    $row['data_mode'] = $data_mode;
    $row['disclaimer'] = $disclaimer;

    // Discrepancy check
    $assigned = strtolower($row['assigned_difficulty']);
    if ($status === 'insufficient_real_data' || $pred_diff === 'insufficient_data') {
        $row['discrepancy'] = 'insufficient_data';
    } elseif ($assigned === $pred_diff) {
        $row['discrepancy'] = 'match';
    } else {
        $row['discrepancy'] = 'mismatch';
    }

    $analyzed_questions[] = $row;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>ML Question Difficulty Analytics</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <style>
        .analytics-card {
            background: #ffffff;
            border-radius: 8px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .notice-box {
            background: #fffbe6;
            border: 1px solid #ffe58f;
            color: #873800;
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .badge-diff {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .diff-easy { background: #d1fae5; color: #065f46; }
        .diff-medium { background: #fef3c7; color: #92400e; }
        .diff-hard { background: #fee2e2; color: #991b1b; }
        .diff-insufficient_data { background: #f3f4f6; color: #4b5563; }

        .mode-tag {
            display: inline-block;
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: bold;
            margin-top: 4px;
        }
        .mode-synthetic { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .mode-real { background: #d1fae5; color: #047857; border: 1px solid #a7f3d0; }
        .mode-insufficient { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }

        .match-badge {
            background: #ecfdf5;
            color: #047857;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }
        .btn-update {
            background: #1e88e5;
            color: white;
            padding: 6px 12px;
            font-size: 13px;
            font-weight: bold;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th { background: #f8fafc; font-weight: 600; color: #475569; }
        .alert-error { background: #fde8e8; color: #9b1c1c; padding: 12px; border-radius: 6px; margin-bottom: 15px; }
        .alert-success { background: #def7ec; color: #03543f; padding: 12px; border-radius: 6px; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="wrapper">
    <?php include "sidebar.php"; ?>

    <div class="content">
        <h1>🤖 ML Question Difficulty Analytics</h1>
        <p style="color: #64748b; margin-bottom: 20px;">Estimates empirical question difficulty based on student interaction metrics. Preserves human oversight: ML predictions do NOT automatically overwrite assigned difficulty without your explicit approval.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- SCIENTIFIC CORRECTION NOTICE BANNER -->
        <div class="notice-box">
            🧪 <strong>Cold-Start Protection Rule:</strong> Real student attempts must be &ge; 3 before an empirical ML prediction is made.
        </div>

        <div class="analytics-card">
            <table>
                <thead>
                    <tr>
                        <th>SL No</th>
                        <th>Question</th>
                        <th>Exam / Topic</th>
                        <th>Assigned</th>
                        <th>Attempts / Accuracy</th>
                        <th>ML Prediction</th>
                        <th>Recommendation</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sl_no = 1; foreach ($analyzed_questions as $q): ?>
                        <tr>
                            <td><?= $sl_no++ ?></td>
                            <td style="max-width: 280px; word-break: break-word;">
                                <strong><?= htmlspecialchars($q['question']) ?></strong>
                            </td>
                            <td>
                                <div><small><?= htmlspecialchars($q['exam_title']) ?></small></div>
                                <div><span class="badge-diff" style="background:#e0e7ff; color:#3730a3;"><?= htmlspecialchars($q['topic']) ?></span></div>
                            </td>
                            <td>
                                <span class="badge-diff diff-<?= strtolower($q['assigned_difficulty']) ?>">
                                    <?= strtoupper($q['assigned_difficulty']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($q['total_attempts'] < 3): ?>
                                    <div><strong>Real attempts: <?= $q['total_attempts'] ?> / 3</strong></div>
                                    <div style="font-size:12px; color:#d97706;">Awaiting <?= (3 - $q['total_attempts']) ?> more real attempt(s)</div>
                                <?php else: ?>
                                    <div><strong><?= $q['total_attempts'] ?></strong> attempts</div>
                                    <div style="font-size:12px; color:#64748b;"><?= $q['correct_rate_pct'] ?>% correct</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($q['ml_status'] === 'insufficient_real_data' || $q['predicted_difficulty'] === 'insufficient_data'): ?>
                                    <span class="badge-diff diff-insufficient_data">INSUFFICIENT DATA</span>
                                <?php else: ?>
                                    <span class="badge-diff diff-<?= $q['predicted_difficulty'] ?>">
                                        <?= strtoupper($q['predicted_difficulty']) ?>
                                    </span>
                                    <div style="font-size: 11px; color: #64748b;"><?= $q['confidence'] ?>% conf</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($q['discrepancy'] === 'match'): ?>
                                    <span class="match-badge">✓ Match</span>
                                <?php elseif ($q['discrepancy'] === 'mismatch'): ?>
                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="action" value="update_difficulty">
                                        <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                        <input type="hidden" name="new_difficulty" value="<?= htmlspecialchars($q['predicted_difficulty']) ?>">
                                        
                                        <button type="submit" class="btn-update">
                                            Update to <?= strtoupper($q['predicted_difficulty']) ?>
                                        </button>
                                    </form>
                                    <div style="font-size:11px; color:#d97706; margin-top:4px;">⚠️ Suggested update</div>
                                <?php else: ?>
                                    <span style="font-size:12px; color:#6b7280; font-weight: 500;">Awaiting 3 real attempts</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
