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

// Fetch student attempt history from BOTH official exams and practice sessions
$history = [];

// 1. Official exam answers
$sql_exams = "SELECT sa.question_id, sa.answer AS student_answer, q.subject, q.topic, q.difficulty, q.correct_option,
                     (sa.answer = q.correct_option) AS is_correct, r.taken_at AS attempt_time
              FROM student_answers sa
              JOIN questions q ON sa.question_id = q.id
              JOIN results r ON (sa.student_id = r.user_id AND sa.exam_id = r.exam_id)
              WHERE sa.student_id = ?
              ORDER BY r.taken_at ASC";

$stmt = $conn->prepare($sql_exams);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$res1 = $stmt->get_result();
while ($row = $res1->fetch_assoc()) {
    $history[] = [
        'subject' => $row['subject'] ?? 'General',
        'topic' => $row['topic'] ?? 'General',
        'difficulty' => $row['difficulty'] ?? 'medium',
        'is_correct' => (bool)$row['is_correct'],
        'correct' => (bool)$row['is_correct'],
        'attempt_time' => $row['attempt_time']
    ];
}

// 2. Practice session answers
$sql_practice = "SELECT pa.subject, pa.topic, pa.difficulty, pa.is_correct, pa.created_at AS attempt_time
                FROM ai_practice_answers pa
                JOIN ai_practice_sessions ps ON pa.session_id = ps.id
                WHERE pa.student_id = ? AND ps.status = 'completed'
                ORDER BY pa.created_at ASC";

$stmt_p = $conn->prepare($sql_practice);
$stmt_p->bind_param("i", $student_id);
$stmt_p->execute();
$res2 = $stmt_p->get_result();
while ($row = $res2->fetch_assoc()) {
    $history[] = [
        'subject' => $row['subject'] ?? 'General',
        'topic' => $row['topic'] ?? 'General',
        'difficulty' => $row['difficulty'] ?? 'medium',
        'is_correct' => (bool)$row['is_correct'],
        'correct' => (bool)$row['is_correct'],
        'attempt_time' => $row['attempt_time']
    ];
}

// Get Personalized Study Plan from AI Client
$plan_res = $aiClient->getPersonalizedStudyPlan($student_id, $history);
$plan_data = $plan_res['data'] ?? [];
$status = $plan_data['status'] ?? 'insufficient_data';
$overall_accuracy = $plan_data['overall_accuracy'] ?? 0.0;
$summary = $plan_data['summary_explanation'] ?? "Complete an exam or practice session to receive personalized recommendations.";
$plan_items = $plan_data['plan_items'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personalized Adaptive Learning - Student</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .learning-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }
        .metric-badge {
            font-size: 0.85rem;
            padding: 4px 10px;
            border-radius: 20px;
        }
        .priority-border-weak { border-left: 5px solid #dc3545 !important; }
        .priority-border-dev { border-left: 5px solid #ffc107 !important; }
        .priority-border-strong { border-left: 5px solid #198754 !important; }
    </style>
</head>
<body class="bg-light">

<div class="d-flex">
    <?php include 'sidebar.php'; ?>

    <div class="container-fluid p-4" style="margin-left: 260px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="h3 fw-bold text-dark mb-1"><i class="fa-solid fa-brain text-primary me-2"></i>Personalized Adaptive Learning</h2>
                <p class="text-muted small mb-0">Explainable AI recommendations derived from your actual exam & practice performance</p>
            </div>
        </div>

        <!-- Overall Summary Banner -->
        <div class="learning-card shadow-sm p-4 mb-4">
            <div class="row align-items-center">
                <div class="col-md-3 text-center border-end">
                    <div class="display-5 fw-bold text-primary"><?= number_format($overall_accuracy, 1) ?>%</div>
                    <div class="text-muted small fw-semibold">Overall Mastery Accuracy</div>
                    <div class="mt-2"><span class="badge bg-light text-dark border">Attempts: <?= count($history) ?> questions</span></div>
                </div>
                <div class="col-md-9 ps-md-4">
                    <h5 class="fw-bold text-dark mb-2"><i class="fa-solid fa-wand-magic-sparkles me-2 text-warning"></i>Study Plan Recommendation Summary</h5>
                    <p class="text-secondary mb-0 lh-base"><?= htmlspecialchars($summary) ?></p>
                </div>
            </div>
        </div>

        <?php if ($status === 'insufficient_data' || empty($plan_items)): ?>
            <div class="alert alert-info p-4 rounded-3 shadow-sm mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fa-solid fa-circle-info fa-2x me-3 text-info"></i>
                    <div>
                        <h5 class="fw-bold mb-1">Not Enough Data Yet</h5>
                        <p class="mb-0">Complete an exam or practice session to receive a reliable personalized learning recommendation.</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-list-check text-primary me-2"></i>Recommended Learning Tasks</h5>

            <div class="row g-3 mb-4">
                <?php foreach ($plan_items as $item): ?>
                    <?php
                    $cls = $item['classification'];
                    $border_class = ($cls === 'NEEDS_IMPROVEMENT') ? 'priority-border-weak' : (($cls === 'DEVELOPING') ? 'priority-border-dev' : 'priority-border-strong');
                    $badge_class = ($cls === 'NEEDS_IMPROVEMENT') ? 'bg-danger' : (($cls === 'DEVELOPING') ? 'bg-warning text-dark' : 'bg-success');
                    ?>
                    <div class="col-12">
                        <div class="learning-card shadow-sm p-4 <?= $border_class ?>">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                <div>
                                    <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($item['topic']) ?></h5>
                                    <span class="badge bg-light text-dark border me-2"><?= htmlspecialchars($item['subject']) ?></span>
                                    <span class="badge <?= $badge_class ?> me-2"><?= str_replace('_', ' ', $cls) ?> (<?= $item['accuracy'] ?>%)</span>
                                    <span class="badge bg-light text-secondary border"><i class="fa-solid fa-chart-line me-1"></i>Trend: <?= ucfirst($item['trend']) ?></span>
                                </div>
                                <div>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle me-2">Priority Score: <?= $item['priority_score'] ?></span>
                                    <span class="badge bg-dark">Rec. Diff: <?= ucfirst($item['recommended_difficulty']) ?></span>
                                </div>
                            </div>

                            <p class="text-secondary small mb-3">
                                <i class="fa-solid fa-circle-exclamation me-1 text-primary"></i>
                                <strong>Reason Tag:</strong> <code><?= htmlspecialchars($item['reason']) ?></code> — <?= htmlspecialchars($item['suggested_action']) ?>
                            </p>

                            <!-- Course Material RAG Section -->
                            <div class="bg-light p-3 rounded mb-3">
                                <div class="fw-semibold small text-dark mb-1"><i class="fa-solid fa-book-open me-2 text-primary"></i>Phase G Grounded Course Material:</div>
                                <div class="small text-muted mb-2"><?= htmlspecialchars($item['course_material_notice']) ?></div>
                                <?php if ($item['has_course_material'] && !empty($item['sources'])): ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($item['sources'] as $src): ?>
                                            <span class="badge bg-white text-primary border">
                                                <i class="fa-solid fa-file-pdf me-1"></i><?= htmlspecialchars($src['filename']) ?> (Page <?= $src['page_number'] ?>)
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Action Form for Targeted Practice -->
                            <form method="POST" action="practice_session.php">
                                <input type="hidden" name="action" value="start_practice">
                                <input type="hidden" name="subject" value="<?= htmlspecialchars($item['subject']) ?>">
                                <input type="hidden" name="topic" value="<?= htmlspecialchars($item['topic']) ?>">
                                <input type="hidden" name="difficulty" value="<?= htmlspecialchars($item['recommended_difficulty']) ?>">
                                <input type="hidden" name="count" value="<?= (int)$item['recommended_question_count'] ?>">
                                
                                <button type="submit" class="btn btn-primary fw-semibold btn-sm px-3">
                                    <i class="fa-solid fa-play me-2"></i>Practice This Topic Now (5 Questions)
                                </button>
                                <a href="study_assistant.php?subject=<?= urlencode($item['subject']) ?>&topic=<?= urlencode($item['topic']) ?>" class="btn btn-outline-secondary btn-sm fw-semibold ms-2">
                                    <i class="fa-solid fa-comments me-2"></i>Ask Study Assistant
                                </a>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
