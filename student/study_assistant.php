<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/text_helpers.php';
require_once __DIR__ . '/../config/ai_client.php';
require_once __DIR__ . '/../config/subject_resolver.php';

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

// Preload lookup map of authorized/ingested study materials for clickable links
$docLookup = [
    'by_id' => [],
    'by_name' => []
];
$res_docs = $conn->query("SELECT id, filename, original_name, subject, topic FROM ai_documents WHERE status = 'ingested'");
if ($res_docs) {
    while ($d = $res_docs->fetch_assoc()) {
        $d_id = (int)$d['id'];
        $docLookup['by_id'][$d_id] = $d;
        if (!empty($d['filename'])) {
            $docLookup['by_name'][strtolower($d['filename'])] = $d;
        }
        if (!empty($d['original_name'])) {
            $docLookup['by_name'][strtolower($d['original_name'])] = $d;
        }
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
$sql_exams = "SELECT sa.question_id, sa.answer AS student_answer, q.subject,
                     TRIM(q.topic) AS topic, q.difficulty,
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
            'topic' => $row['topic'] ?? '',
            'difficulty' => $row['difficulty'] ?? 'medium',
            'is_correct' => (bool)$row['is_correct'],
            'correct' => (bool)$row['is_correct'],
            'attempt_time' => $row['attempt_time']
        ];
    }
}

// 2. Practice session answers
$sql_practice = "SELECT pa.subject,
                     TRIM(pa.topic) AS topic, pa.difficulty, pa.is_correct, pa.created_at AS attempt_time
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
            'topic' => $row['topic'] ?? '',
            'difficulty' => $row['difficulty'] ?? 'medium',
            'is_correct' => (bool)$row['is_correct'],
            'correct' => (bool)$row['is_correct'],
            'attempt_time' => $row['attempt_time']
        ];
    }
}

$question_input = "";
$error_message = "";

$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
           (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $error_message = "Invalid CSRF security token.";
        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => $error_message]);
            exit();
        }
    } elseif (!empty($_POST['question'])) {
        $question_input = trim($_POST['question']);

        // Authoritative dynamic subject resolution from database exams, titles, aliases, or query
        $resolution = SubjectResolver::resolve($conn, $question_input);

        if ($resolution['status'] === 'ambiguous') {
            // Short clarification prompt instead of retrieving unrelated content
            $subjs = $resolution['subjects'];
            $subjsText = implode(' and ', $subjs);
            $subjsOr = implode(' or ', $subjs);
            $clarificationMsg = "It looks like your question mentions multiple subjects ({$subjsText}). Which subject would you like help with? Please specify either {$subjsOr} so I can provide the right exam questions, performance insights, and course materials.";

            $turn_time = date('h:i A');
            $_SESSION['study_assistant_history'][] = [
                'question' => $question_input,
                'answer' => $clarificationMsg,
                'sources' => [],
                'has_sufficient_context' => false,
                'is_conversational' => true,
                'intent' => 'clarification',
                'time' => $turn_time
            ];

            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => 'success',
                    'question' => $question_input,
                    'answer' => render_chat_markdown($clarificationMsg, $docLookup),
                    'sources' => [],
                    'time' => $turn_time
                ]);
                exit();
            }
            $question_input = "";
        } else {
            $resolvedSubject = $resolution['subject'] ?? null;
            $topic = null;

            // If no subject explicitly resolved in this turn, check previous turns in session history
            if ($resolvedSubject === null && !empty($_SESSION['study_assistant_history'])) {
                for ($i = count($_SESSION['study_assistant_history']) - 1; $i >= 0; $i--) {
                    if (!empty($_SESSION['study_assistant_history'][$i]['subject'])) {
                        $resolvedSubject = $_SESSION['study_assistant_history'][$i]['subject'];
                        break;
                    }
                }
            }

            // Build recent conversation context window (last 6 messages)
            $conversationHistory = [];
            $recentTurns = array_slice($_SESSION['study_assistant_history'] ?? [], -6);
            foreach ($recentTurns as $turn) {
                if (!empty($turn['question'])) {
                    $conversationHistory[] = ['role' => 'user', 'content' => (string)$turn['question']];
                }
                if (!empty($turn['answer'])) {
                    $conversationHistory[] = ['role' => 'assistant', 'content' => (string)$turn['answer']];
                }
            }

            // Enrich user_context with subject-specific exam question counts & student stats
            $callUserContext = $user_context;
            if ($resolvedSubject !== null) {
                $subjSummary = SubjectResolver::getSubjectSummary($conn, $resolvedSubject, $user_id);
                $callUserContext['subject_summary'] = $subjSummary;
            }

            $aiClient = new AiClient();
            $res = $aiClient->askRAG(
                question: $question_input,
                subject: $resolvedSubject,
                topic: $topic,
                topK: 5,
                threshold: null,
                studentId: $user_id,
                history: $history,
                userContext: $callUserContext,
                systemStats: $system_stats,
                otherUsersInfo: $other_users_info,
                conversationHistory: $conversationHistory
            );

            if ($res['status'] === 'success') {
                $rag_data = $res['data'] ?? [];
                $turn_time = date('h:i A');
                $raw_answer = $rag_data['answer'] ?? "I couldn't process an answer at this time.";
                $raw_sources = $rag_data['sources'] ?? [];

                $_SESSION['study_assistant_history'][] = [
                    'question' => $question_input,
                    'answer' => $raw_answer,
                    'sources' => $raw_sources,
                    'has_sufficient_context' => $rag_data['has_sufficient_context'] ?? false,
                    'is_conversational' => $rag_data['is_conversational'] ?? false,
                    'intent' => $rag_data['intent'] ?? 'academic_question',
                    'subject' => $resolvedSubject,
                    'time' => $turn_time
                ];

                if ($is_ajax) {
                    $formatted_sources = [];
                    foreach ($raw_sources as $src) {
                        $srcDocId = (int)($src['document_id'] ?? 0);
                        $srcFname = $src['filename'] ?? '';
                        $srcPnum = max(1, (int)($src['page_number'] ?? 1));
                        if ($srcDocId <= 0 && isset($docLookup['by_name'][strtolower($srcFname)])) {
                            $srcDocId = (int)$docLookup['by_name'][strtolower($srcFname)]['id'];
                        }
                        $viewLink = $srcDocId > 0
                            ? "view_course_material.php?id={$srcDocId}&page={$srcPnum}&source=assistant"
                            : "view_course_material.php?file=" . urlencode($srcFname) . "&page={$srcPnum}&source=assistant";
                        $formatted_sources[] = [
                            'filename' => $srcFname,
                            'page_number' => $srcPnum,
                            'document_id' => $srcDocId,
                            'view_link' => $viewLink
                        ];
                    }

                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'status' => 'success',
                        'question' => $question_input,
                        'answer' => render_chat_markdown($raw_answer, $docLookup),
                        'raw_answer' => $raw_answer,
                        'sources' => $formatted_sources,
                        'time' => $turn_time
                    ]);
                    exit();
                }
                $question_input = "";
            } else {
                $error_message = $res['message'] ?? "The AI Assistant is currently unavailable. Please check that the AI service is running.";
                if ($is_ajax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'error', 'message' => $error_message]);
                    exit();
                }
            }
        }
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
        .citation-badge-link {
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
        }
        .citation-badge-link:hover {
            background: #dbeafe;
            border-color: #93c5fd;
            color: #1e40af;
            box-shadow: 0 2px 6px rgba(29, 78, 216, 0.15);
            transform: translateY(-1px);
        }
        .material-link {
            color: #0d6efd;
            text-decoration: underline;
            font-weight: 600;
            transition: color 0.15s ease;
        }
        .material-link:hover {
            color: #0a58ca;
            text-decoration: underline;
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
                <?php 
                $totalHistory = count($_SESSION['study_assistant_history']);
                foreach ($_SESSION['study_assistant_history'] as $idx => $msg): 
                    $isLatest = ($idx === $totalHistory - 1);
                ?>
                    <!-- User Message -->
                    <div class="d-flex justify-content-end mb-3 chat-msg-row chat-msg-user">
                        <div class="user-bubble shadow-sm">
                            <div class="small text-white-50 fw-semibold mb-1"><i class="fa-solid fa-user me-1"></i>👤 You</div>
                            <div class="lh-base"><?= htmlspecialchars($msg['question']) ?></div>
                        </div>
                    </div>

                    <!-- Assistant Message -->
                    <div class="d-flex justify-content-start mb-4 chat-msg-row chat-msg-assistant" <?= ($isLatest) ? 'id="latest-assistant-response"' : '' ?>>
                        <div class="assistant-bubble">
                            <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-2">
                                <span class="fw-bold text-dark small"><i class="fa-solid fa-robot text-primary me-2"></i>🤖 AI Assistant</span>
                                <small class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($msg['time']) ?></small>
                            </div>
                            <div class="text-dark lh-base">
                                <?= render_chat_markdown($msg['answer'], $docLookup) ?>
                            </div>

                            <!-- Source Citations -->
                            <?php if (!empty($msg['sources'])): ?>
                                <div class="mt-3 pt-2 border-top">
                                    <div class="fw-semibold text-muted small mb-2"><i class="fa-solid fa-bookmark text-primary me-1"></i>Sources:</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($msg['sources'] as $src): ?>
                                            <?php
                                             $srcDocId = (int)($src['document_id'] ?? 0);
                                             $srcFname = $src['filename'] ?? '';
                                             $srcPnum = max(1, (int)($src['page_number'] ?? 1));
                                             if ($srcDocId <= 0 && isset($docLookup['by_name'][strtolower($srcFname)])) {
                                                 $srcDocId = (int)$docLookup['by_name'][strtolower($srcFname)]['id'];
                                             }
                                             $viewLink = $srcDocId > 0
                                                 ? "view_course_material.php?id={$srcDocId}&page={$srcPnum}&source=assistant"
                                                 : "view_course_material.php?file=" . urlencode($srcFname) . "&page={$srcPnum}&source=assistant";
                                            ?>
                                            <a href="<?= htmlspecialchars($viewLink) ?>" target="_blank" rel="noopener noreferrer" class="citation-badge citation-badge-link" title="Open study material">
                                                <i class="fa-solid fa-file-lines"></i>
                                                <span><?= htmlspecialchars($srcFname) ?> &mdash; Page <?= $srcPnum ?></span>
                                                <i class="fa-solid fa-arrow-up-right-from-square ms-1" style="font-size: 0.72rem; opacity: 0.75;"></i>
                                            </a>
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
            <form id="chat-form" method="POST" action="">
                <input type="hidden" name="csrf_token" id="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label fw-semibold text-dark mb-0">Your Message</label>
                        <small class="text-muted"><kbd class="bg-light text-dark border">Enter</kbd> to send &middot; <kbd class="bg-light text-dark border">Shift + Enter</kbd> for newline</small>
                    </div>
                    <textarea id="chat-textarea" name="question" class="form-control" rows="3" placeholder="Ask me anything about your exams, performance, studies, or the examination system..." required><?= htmlspecialchars($question_input) ?></textarea>
                </div>

                <button type="submit" id="chat-submit-btn" class="btn btn-primary fw-semibold px-4 py-2">
                    <i class="fa-solid fa-paper-plane me-2"></i>Ask Study Assistant
                </button>
            </form>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const chatContainer = document.getElementById('chat-container');
const chatForm = document.getElementById('chat-form');
const chatTextarea = document.getElementById('chat-textarea');
const chatSubmitBtn = document.getElementById('chat-submit-btn');
const csrfTokenInput = document.getElementById('csrf_token');

let isSubmitting = false;

/**
 * Positions the chat-container at the top of the latest assistant response
 * so the student can read from line 1, without scrolling the main document window.
 */
function positionChatAtLatestResponse(smooth = true) {
    if (!chatContainer) return;
    const latestResponse = document.getElementById('latest-assistant-response');
    if (!latestResponse) {
        chatContainer.scrollTo({
            top: chatContainer.scrollHeight,
            behavior: smooth ? 'smooth' : 'auto'
        });
        return;
    }

    // Target the user question right above the assistant response so both the question and answer start are visible
    let anchorElem = latestResponse;
    const prevElem = latestResponse.previousElementSibling;
    if (prevElem && prevElem.classList.contains('chat-msg-user')) {
        anchorElem = prevElem;
    }

    // Calculate position strictly relative to chatContainer scroll viewport using getBoundingClientRect
    const containerRect = chatContainer.getBoundingClientRect();
    const targetRect = anchorElem.getBoundingClientRect();
    const targetTop = targetRect.top - containerRect.top + chatContainer.scrollTop - 10;
    chatContainer.scrollTo({
        top: Math.max(0, targetTop),
        behavior: smooth ? 'smooth' : 'auto'
    });
}

/**
 * Escapes HTML characters for safe client-side insertion of user questions
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Handles message submission via AJAX with immediate user message display,
 * enter-to-send, loading indicator, and scroll positioning at line 1 of the AI answer.
 */
function submitChat() {
    if (isSubmitting) return;
    const questionText = chatTextarea ? chatTextarea.value.trim() : '';
    if (!questionText) return;

    isSubmitting = true;
    if (chatSubmitBtn) {
        chatSubmitBtn.disabled = true;
        chatSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Thinking...';
    }
    if (chatTextarea) {
        chatTextarea.disabled = true;
    }

    // 1. Remove initial empty placeholder if present
    const placeholder = chatContainer.querySelector('.text-center.text-muted');
    if (placeholder) {
        placeholder.remove();
    }

    // 2. Remove latest-assistant-response ID from any previous turn
    const prevLatest = document.getElementById('latest-assistant-response');
    if (prevLatest) {
        prevLatest.removeAttribute('id');
    }

    // 3. Immediately display the user's message in the thread
    const userRow = document.createElement('div');
    userRow.className = 'd-flex justify-content-end mb-3 chat-msg-row chat-msg-user';
    userRow.innerHTML = `
        <div class="user-bubble shadow-sm">
            <div class="small text-white-50 fw-semibold mb-1"><i class="fa-solid fa-user me-1"></i>👤 You</div>
            <div class="lh-base">${escapeHtml(questionText)}</div>
        </div>
    `;
    chatContainer.appendChild(userRow);

    // Scroll chat container so the user's message is immediately visible
    chatContainer.scrollTo({
        top: chatContainer.scrollHeight,
        behavior: 'smooth'
    });

    // 4. Show pulsing typing indicator
    const typingIndicator = document.createElement('div');
    typingIndicator.id = 'ai-typing-indicator';
    typingIndicator.className = 'd-flex justify-content-start mb-4 chat-msg-row chat-msg-assistant';
    typingIndicator.innerHTML = `
        <div class="assistant-bubble">
            <div class="d-flex align-items-center gap-2 text-primary small">
                <span class="spinner-grow spinner-grow-sm" role="status"></span>
                <span class="text-muted fw-medium fst-italic">AI Assistant is thinking...</span>
            </div>
        </div>
    `;
    chatContainer.appendChild(typingIndicator);

    // 5. Build form data for submission
    const csrfToken = csrfTokenInput ? csrfTokenInput.value : '';
    const bodyParams = new URLSearchParams();
    bodyParams.append('csrf_token', csrfToken);
    bodyParams.append('question', questionText);

    fetch('study_assistant.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        },
        body: bodyParams.toString()
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP error ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        // Remove typing indicator
        if (typingIndicator && typingIndicator.parentNode) {
            typingIndicator.remove();
        }

        if (data.status === 'success') {
            const timeStr = data.time || 'Just now';
            const answerHtml = data.answer || "I couldn't process an answer at this time.";
            const sources = data.sources || [];

            let sourcesHtml = '';
            if (sources.length > 0) {
                sourcesHtml += '<div class="mt-3 pt-2 border-top">';
                sourcesHtml += '<div class="fw-semibold text-muted small mb-2"><i class="fa-solid fa-bookmark text-primary me-1"></i>Sources:</div>';
                sourcesHtml += '<div class="d-flex flex-wrap gap-2">';
                sources.forEach(src => {
                    sourcesHtml += `
                        <a href="${escapeHtml(src.view_link)}" target="_blank" rel="noopener noreferrer" class="citation-badge citation-badge-link" title="Open study material">
                            <i class="fa-solid fa-file-lines"></i>
                            <span>${escapeHtml(src.filename)} &mdash; Page ${src.page_number}</span>
                            <i class="fa-solid fa-arrow-up-right-from-square ms-1" style="font-size: 0.72rem; opacity: 0.75;"></i>
                        </a>
                    `;
                });
                sourcesHtml += '</div></div>';
            }

            const assistantRow = document.createElement('div');
            assistantRow.className = 'd-flex justify-content-start mb-4 chat-msg-row chat-msg-assistant';
            assistantRow.id = 'latest-assistant-response';
            assistantRow.innerHTML = `
                <div class="assistant-bubble">
                    <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-2">
                        <span class="fw-bold text-dark small"><i class="fa-solid fa-robot text-primary me-2"></i>🤖 AI Assistant</span>
                        <small class="text-muted" style="font-size: 0.75rem;">${escapeHtml(timeStr)}</small>
                    </div>
                    <div class="text-dark lh-base">
                        ${answerHtml}
                    </div>
                    ${sourcesHtml}
                </div>
            `;
            chatContainer.appendChild(assistantRow);

            // Position chat at line 1 of the new AI response
            requestAnimationFrame(() => {
                positionChatAtLatestResponse(true);
            });
        } else {
            const errAlert = document.createElement('div');
            errAlert.className = 'alert alert-warning alert-dismissible fade show shadow-sm mb-4';
            errAlert.innerHTML = `
                <i class="fa-solid fa-triangle-exclamation me-2"></i>${escapeHtml(data.message || 'An error occurred.')}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            chatContainer.appendChild(errAlert);
        }
    })
    .catch(err => {
        if (typingIndicator && typingIndicator.parentNode) {
            typingIndicator.remove();
        }
        const errAlert = document.createElement('div');
        errAlert.className = 'alert alert-danger alert-dismissible fade show shadow-sm mb-4';
        errAlert.innerHTML = `
            <i class="fa-solid fa-triangle-exclamation me-2"></i>The AI Assistant is currently unavailable. Please verify the AI service is active.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        chatContainer.appendChild(errAlert);
    })
    .finally(() => {
        isSubmitting = false;
        if (chatSubmitBtn) {
            chatSubmitBtn.disabled = false;
            chatSubmitBtn.innerHTML = '<i class="fa-solid fa-paper-plane me-2"></i>Ask Study Assistant';
        }
        if (chatTextarea) {
            chatTextarea.disabled = false;
            chatTextarea.value = '';
            chatTextarea.focus();
        }
    });
}

// Enter-to-send: Enter submits, Shift+Enter inserts newline (respects IME composition)
if (chatTextarea) {
    chatTextarea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
            e.preventDefault();
            submitChat();
        }
    });
}

// Form submit event
if (chatForm) {
    chatForm.addEventListener('submit', function(e) {
        e.preventDefault();
        submitChat();
    });
}

// Initial positioning on page load
document.addEventListener('DOMContentLoaded', function() {
    positionChatAtLatestResponse(false);
});
window.addEventListener('load', function() {
    positionChatAtLatestResponse(false);
});
</script>
</body>
</html>