<?php
session_start();
require_once "../config/db.php";
require_once "../config/ai_client.php";

/* ===== STUDENT AUTH GUARD ===== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

/* ===== 1. FETCH COMPLETED EXAMS FOR THIS STUDENT ===== */
$examStmt = $conn->prepare("
    SELECT r.exam_id, r.score, r.taken_at, e.title, e.marks_per_question,
           (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) as total_questions
    FROM results r
    JOIN exams e ON r.exam_id = e.id
    WHERE r.user_id = ?
    ORDER BY r.taken_at ASC
");
$examStmt->bind_param("i", $student_id);
$examStmt->execute();
$examRes = $examStmt->get_result();

$exams_payload = [];
while ($row = $examRes->fetch_assoc()) {
    $tQuestions = (int)$row['total_questions'];
    $maxMarks = $tQuestions * (int)$row['marks_per_question'];
    if ($maxMarks <= 0) $maxMarks = max(1, (int)$row['score']);
    
    $pct = ($row['score'] / max(1, $tQuestions)) * 100.0;

    $exams_payload[] = [
        'exam_id' => (int)$row['exam_id'],
        'title' => $row['title'],
        'score' => (float)$row['score'],
        'total_marks' => (float)$maxMarks,
        'percentage' => round($pct, 2),
        'taken_at' => $row['taken_at']
    ];
}

/* ===== 2. FETCH TOPIC-WISE ACCURACY FOR THIS STUDENT ===== */
$topicStmt = $conn->prepare("
    SELECT q.subject, q.topic,
           COUNT(*) as attempted,
           SUM(CASE WHEN sa.answer = q.correct_option THEN 1 ELSE 0 END) as correct
    FROM student_answers sa
    JOIN questions q ON sa.question_id = q.id
    WHERE sa.student_id = ?
    GROUP BY q.subject, q.topic
");
$topicStmt->bind_param("i", $student_id);
$topicStmt->execute();
$topicRes = $topicStmt->get_result();

$topics_payload = [];
while ($row = $topicRes->fetch_assoc()) {
    $attempted = (int)$row['attempted'];
    $correct = (int)$row['correct'];
    $acc = ($attempted > 0) ? (($correct / $attempted) * 100.0) : 0.0;

    $topics_payload[] = [
        'subject' => $row['subject'] ?: 'General',
        'topic' => $row['topic'] ?: 'General',
        'attempted' => $attempted,
        'correct' => $correct,
        'accuracy' => round($acc, 2)
    ];
}

/* ===== 3. CALL PERFORMANCE SERVICE VIA AI CLIENT ===== */
$aiClient = new AiClient();
$perfInput = [
    'student_id' => $student_id,
    'strong_threshold' => 80.0,
    'weak_threshold' => 50.0,
    'exams' => $exams_payload,
    'topics' => $topics_payload
];

$aiResponse = $aiClient->analyzePerformance($perfInput);

// Fallback logic if AI service is offline
if ($aiResponse['success']) {
    $analytics = $aiResponse['data'];
} else {
    // Local PHP Fallback calculation
    $totExams = count($exams_payload);
    $totQuestions = array_sum(array_column($topics_payload, 'attempted'));
    $totCorrect = array_sum(array_column($topics_payload, 'correct'));
    $overallAcc = ($totQuestions > 0) ? round(($totCorrect / $totQuestions) * 100.0, 2) : 0.0;
    $avgPct = ($totExams > 0) ? round(array_sum(array_column($exams_payload, 'percentage')) / $totExams, 2) : 0.0;

    $classified = [];
    $strongs = [];
    $weaks = [];
    foreach ($topics_payload as $tp) {
        $acc = $tp['accuracy'];
        $cls = ($acc >= 80.0) ? 'Strong' : (($acc < 50.0) ? 'Weak' : 'Developing');
        $item = [
            'subject' => $tp['subject'],
            'topic' => $tp['topic'],
            'attempted' => $tp['attempted'],
            'correct' => $tp['correct'],
            'accuracy' => $acc,
            'classification' => $cls
        ];
        $classified[] = $item;
        if ($cls === 'Strong') $strongs[] = $item;
        if ($cls === 'Weak') $weaks[] = $item;
    }

    $analytics = [
        'student_id' => $student_id,
        'total_exams_attempted' => $totExams,
        'total_questions_attempted' => $totQuestions,
        'total_correct' => $totCorrect,
        'total_incorrect' => max(0, $totQuestions - $totCorrect),
        'overall_accuracy' => $overallAcc,
        'average_exam_percentage' => $avgPct,
        'strong_topics' => $strongs,
        'weak_topics' => $weaks,
        'all_topic_performance' => $classified,
        'trend' => [
            'has_trend' => false,
            'direction' => 'insufficient_data',
            'message' => 'Not enough historical exam data yet.'
        ],
        'thresholds' => ['strong_threshold' => 80.0, 'weak_threshold' => 50.0]
    ];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Performance Analytics</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #ffffff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-top: 4px solid #1e88e5;
        }
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #1e88e5;
            margin-top: 8px;
        }
        .stat-label {
            font-size: 13px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .trend-banner {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-weight: 600;
        }
        .trend-improving { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .trend-declining { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .trend-stable { background: #e0f2fe; color: #0369a1; border-left: 4px solid #0284c7; }
        .trend-insufficient_data { background: #f3f4f6; color: #4b5563; border-left: 4px solid #9ca3af; }

        .section-card {
            background: #ffffff;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .section-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #1e293b;
        }
        .topic-pill {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        .pill-strong { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .pill-weak { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th { background: #f8fafc; font-weight: 600; color: #475569; }
        .cls-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .cls-Strong { background: #d1fae5; color: #065f46; }
        .cls-Developing { background: #fef3c7; color: #92400e; }
        .cls-Weak { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

<div class="wrapper">
    <?php include "sidebar.php"; ?>

    <div class="content">
        <h1>📊 Student Performance Analytics</h1>
        <p style="color: #64748b; margin-bottom: 25px;">Data-driven overview of your examination scores, accuracy trends, strong subjects, and topics requiring improvement.</p>

        <?php if ($analytics['total_exams_attempted'] === 0): ?>
            <div class="section-card" style="text-align: center; padding: 40px;">
                <h3>No Exam History Recorded Yet</h3>
                <p style="color: #64748b;">Complete an exam from the <a href="exams.php" style="color: #1e88e5; font-weight: bold;">Available Exams</a> page to view your performance metrics.</p>
            </div>
        <?php else: ?>

            <!-- OVERALL METRICS GRID -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Overall Accuracy</div>
                    <div class="stat-number"><?= $analytics['overall_accuracy'] ?>%</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Average Score</div>
                    <div class="stat-number"><?= $analytics['average_exam_percentage'] ?>%</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Exams Completed</div>
                    <div class="stat-number"><?= $analytics['total_exams_attempted'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Questions Attempted</div>
                    <div class="stat-number"><?= $analytics['total_questions_attempted'] ?></div>
                </div>
            </div>

            <!-- TREND BANNER -->
            <div class="trend-banner trend-<?= $analytics['trend']['direction'] ?>">
                📈 <strong>Performance Trend:</strong> <?= htmlspecialchars($analytics['trend']['message']) ?>
            </div>

            <!-- STRONG & WEAK TOPICS -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="section-card">
                    <div class="section-title">🌟 Strong Topics (≥ <?= $analytics['thresholds']['strong_threshold'] ?>%)</div>
                    <?php if (empty($analytics['strong_topics'])): ?>
                        <p style="color: #64748b; font-size: 14px;">No strong topics identified yet.</p>
                    <?php else: ?>
                        <?php foreach ($analytics['strong_topics'] as $st): ?>
                            <span class="topic-pill pill-strong">
                                ✓ <?= htmlspecialchars($st['topic']) ?> — <?= $st['accuracy'] ?>%
                            </span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="section-card">
                    <div class="section-title">⚠️ Topics to Improve (< <?= $analytics['thresholds']['weak_threshold'] ?>%)</div>
                    <?php if (empty($analytics['weak_topics'])): ?>
                        <p style="color: #64748b; font-size: 14px;">Great job! No weak topics detected below <?= $analytics['thresholds']['weak_threshold'] ?>%.</p>
                    <?php else: ?>
                        <?php foreach ($analytics['weak_topics'] as $wt): ?>
                            <span class="topic-pill pill-weak">
                                ⚠ <?= htmlspecialchars($wt['topic']) ?> — <?= $wt['accuracy'] ?>%
                            </span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TOPIC PERFORMANCE TABLE -->
            <div class="section-card">
                <div class="section-title">📚 Topic Breakdown</div>
                <table>
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Topic</th>
                            <th>Attempted</th>
                            <th>Correct</th>
                            <th>Accuracy</th>
                            <th>Classification</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analytics['all_topic_performance'] as $tp): ?>
                            <tr>
                                <td><?= htmlspecialchars($tp['subject']) ?></td>
                                <td><strong><?= htmlspecialchars($tp['topic']) ?></strong></td>
                                <td><?= $tp['attempted'] ?></td>
                                <td><?= $tp['correct'] ?></td>
                                <td><?= $tp['accuracy'] ?>%</td>
                                <td><span class="cls-badge cls-<?= $tp['classification'] ?>"><?= $tp['classification'] ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- EXAM HISTORY TABLE -->
            <div class="section-card">
                <div class="section-title">📝 Exam History</div>
                <table>
                    <thead>
                        <tr>
                            <th>Exam Title</th>
                            <th>Score</th>
                            <th>Percentage</th>
                            <th>Completed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exams_payload as $ex): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($ex['title']) ?></strong></td>
                                <td><?= $ex['score'] ?> / <?= $ex['total_marks'] ?></td>
                                <td><?= $ex['percentage'] ?>%</td>
                                <td><?= htmlspecialchars($ex['taken_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>
</div>

</body>
</html>
