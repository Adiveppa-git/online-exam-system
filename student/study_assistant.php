<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';

// Fetch current logged-in user details securely
$user_name = "User";
$stmt_u = $conn->prepare("SELECT name FROM users WHERE id = ?");
if ($stmt_u) {
    $stmt_u->bind_param("i", $user_id);
    $stmt_u->execute();
    $res_u = $stmt_u->get_result();
    if ($row_u = $res_u->fetch_assoc()) {
        $user_name = $row_u['name'];
    }
}

$user_context = [
    'id' => $user_id,
    'name' => $user_name,
    'role' => $user_role
];

// Fetch system stats if admin
$system_stats = null;
if ($user_role === 'admin') {
    $system_stats = [
        'total_students' => (int)($conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'student'")->fetch_assoc()['cnt'] ?? 0),
        'total_exams' => (int)($conn->query("SELECT COUNT(*) AS cnt FROM exams")->fetch_assoc()['cnt'] ?? 0),
        'total_questions' => (int)($conn->query("SELECT COUNT(*) AS cnt FROM questions")->fetch_assoc()['cnt'] ?? 0),
        'total_results' => (int)($conn->query("SELECT COUNT(*) AS cnt FROM results")->fetch_assoc()['cnt'] ?? 0),
    ];
}

// Fetch non-sensitive user directory
$other_users_info = [];
$res_users = $conn->query("SELECT id, name, role FROM users LIMIT 50");
if ($res_users) {
    while ($r = $res_users->fetch_assoc()) {
        $other_users_info[] = [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'role' => $r['role']
        ];
    }
}

// Initialize chat history in session
if (!isset($_SESSION['study_assistant_history']) || !is_array($_SESSION['study_assistant_history'])) {
    $_SESSION['study_assistant_history'] = [];
}

// Clear conversation thread
if (isset($_GET['action']) && $_GET['action'] === 'clear_chat') {
    $_SESSION['study_assistant_history'] = [];
    header("Location: study_assistant.php");
    exit();
}

// Fetch student attempt history from official exams and practice sessions
$history = [];

// 1. Official exam answers
$sql_exams = "SELECT sa.question_id, sa.answer AS student_answer, q.subject, q.topic, q.difficulty, q.correct_option,
                     (TRIM(sa.answer) = TRIM(q.correct_option)) AS is_correct, r.taken_at AS attempt_time
              FROM student_answers sa
              JOIN questions q ON sa.question_id = q.id
              JOIN results r ON (sa.student_id = r.user_id AND sa.exam_id = r.exam_id)
              WHERE sa.student_id = ?
              ORDER BY r.taken_at ASC";

$stmt = $conn->prepare($sql_exams);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
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
}

// 2. Practice session answers
$sql_practice = "SELECT pa.subject, pa.topic, pa.difficulty, pa.is_correct, pa.created_at AS attempt_time
                FROM ai_practice_answers pa
                JOIN ai_practice_sessions ps ON pa.session_id = ps.id
                WHERE pa.student_id = ? AND ps.status = 'completed'
                ORDER BY pa.created_at ASC";

$stmt_p = $conn->prepare($sql_practice);
if ($stmt_p) {
    $stmt_p->bind_param("i", $user_id);
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
}

$question_input = "";
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['question'])) {
    $question_input = trim($_POST['question']);

    $aiClient = new AiClient();
    $res = $aiClient->askRAG(
        question: $question_input,
        subject: null,
        topic: null,
        topK: null,
        threshold: null,
        studentId: $user_id,
        history: $history,
        userContext: $user_context,
        systemStats: $system_stats,
        otherUsersInfo: $other_users_info
    );

    if ($res['status'] === 'success') {
        $rag_data = $res['data'] ?? [];
        $_SESSION['study_assistant_history'][] = [
            'question' => $question_input,
            'answer' => $rag_data['answer'] ?? "I couldn't process an answer at this time.",
            'sources' => $rag_data['sources'] ?? [],
            'has_sufficient_context' => $rag_data['has_sufficient_context'] ?? false,
            'is_conversational' => $rag_data['is_conversational'] ?? false,
            'intent' => $rag_data['intent'] ?? 'academic_question',
            'time' => date('h:i A')
        ];
        $question_input = "";
    } else {
        $error_message = $res['message'] ?? "The AI Assistant is currently unavailable. Please check that the AI service is running.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI System Assistant - Online Examination System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .main-content {
            margin-left: 250px;
            width: calc(100% - 250px);
            max-width: none;
            padding: 28px 36px;
            transition: margin-left 0.3s ease, width 0.3s ease;
            box-sizing: border-box;
            min-width: 0;
        }
        .sidebar.closed ~ .main-content {
            margin-left: 60px;
            width: calc(100% - 60px);
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 16px !important;
            }
        }
        .chat-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e9ecef;
            width: 100%;
        }
        .chat-thread {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            min-height: 280px;
            max-height: 520px;
            overflow-y: auto;
        }
        .user-bubble {
            background: #0d6efd;
            color: #ffffff;
            border-radius: 14px 14px 2px 14px;
            padding: 12px 16px;
            max-width: 80%;
            margin-left: auto;
        }
        .assistant-bubble {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            border-radius: 14px 14px 14px 2px;
            padding: 16px 20px;
            max-width: 90%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .citation-badge {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 0.82rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }
    </style>
</head>
<body class="bg-light">

<div class="d-flex min-vh-100">
    <?php include 'sidebar.php'; ?>

    <div class="main-content flex-grow-1">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h2 class="h3 fw-bold text-dark mb-1"><i class="fa-solid fa-robot text-primary me-2"></i>AI Assistant</h2>
                <p class="text-muted small mb-0">Ask me anything about your exams, performance, studies, or the examination system...</p>
            </div>
            <?php if (!empty($_SESSION['study_assistant_history'])): ?>
                <a href="study_assistant.php?action=clear_chat" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="fa-solid fa-trash-can me-1"></i>Clear Chat
                </a>
            <?php endif; ?>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-warning alert-dismissible fade show shadow-sm mb-4" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Chat Conversation Area -->
        <div class="chat-thread shadow-sm mb-4" id="chat-container">
            <?php if (empty($_SESSION['study_assistant_history'])): ?>
                <div class="text-center text-muted py-5 my-auto">
                    <i class="fa-solid fa-comments text-secondary mb-2" style="font-size: 2.5rem; opacity: 0.5;"></i>
                    <p class="mb-0 small fw-medium">No messages yet. Type your message below to start chatting with the AI Assistant!</p>
                </div>
            <?php else: ?>
                <?php foreach ($_SESSION['study_assistant_history'] as $msg): ?>
                    <!-- User Message -->
                    <div class="d-flex justify-content-end mb-3">
                        <div class="user-bubble shadow-sm">
                            <div class="small text-white-50 fw-semibold mb-1"><i class="fa-solid fa-user me-1"></i>👤 You</div>
                            <div class="lh-base"><?= htmlspecialchars($msg['question']) ?></div>
                        </div>
                    </div>

                    <!-- Assistant Message -->
                    <div class="d-flex justify-content-start mb-4">
                        <div class="assistant-bubble">
                            <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-2">
                                <span class="fw-bold text-dark small"><i class="fa-solid fa-robot text-primary me-2"></i>🤖 AI Assistant</span>
                                <small class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($msg['time']) ?></small>
                            </div>
                            <div class="text-dark lh-base" style="white-space: pre-line;">
                                <?= htmlspecialchars($msg['answer']) ?>
                            </div>

                            <!-- Source Citations -->
                            <?php if (!empty($msg['sources'])): ?>
                                <div class="mt-3 pt-2 border-top">
                                    <div class="fw-semibold text-muted small mb-2"><i class="fa-solid fa-bookmark text-primary me-1"></i>Sources:</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($msg['sources'] as $src): ?>
                                            <div class="citation-badge">
                                                <i class="fa-solid fa-file-lines"></i>
                                                <span><?= htmlspecialchars($src['filename']) ?> &mdash; Page <?= (int)$src['page_number'] ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Question Input Card -->
        <div class="chat-card shadow-sm p-4">
            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label fw-semibold text-dark">Your Message</label>
                    <textarea name="question" class="form-control" rows="3" placeholder="Ask me anything about your exams, performance, studies, or the examination system..." required><?= htmlspecialchars($question_input) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary fw-semibold px-4 py-2">
                    <i class="fa-solid fa-paper-plane me-2"></i>Ask Study Assistant
                </button>
            </form>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function scrollChatToBottom(smooth = true) {
    const chatContainer = document.getElementById('chat-container');
    if (!chatContainer) return;

    requestAnimationFrame(() => {
        chatContainer.scrollTo({
            top: chatContainer.scrollHeight,
            behavior: smooth ? 'smooth' : 'auto'
        });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    scrollChatToBottom(false);
    setTimeout(function() {
        scrollChatToBottom(true);
    }, 100);
});

window.addEventListener('load', function() {
    scrollChatToBottom(true);
});

const chatForm = document.querySelector('form');
if (chatForm) {
    chatForm.addEventListener('submit', function() {
        scrollChatToBottom(true);
    });
}
</script>
</body>
</html>