<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

// Fetch distinct subjects for filter dropdown
$subjects = [];
$res = $conn->query("SELECT DISTINCT subject FROM ai_documents WHERE status = 'ingested' ORDER BY subject ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $subjects[] = $row['subject'];
    }
}

$question_input = "";
$selected_subject = $_POST['subject'] ?? 'All';
$selected_topic = $_POST['topic'] ?? '';
$rag_response = null;
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['question'])) {
    $question_input = trim($_POST['question']);
    $subject_filter = ($selected_subject !== 'All') ? $selected_subject : null;
    $topic_filter = (!empty($selected_topic)) ? trim($selected_topic) : null;

    $aiClient = new AiClient();
    $res = $aiClient->askRAG($question_input, $subject_filter, $topic_filter);

    if ($res['status'] === 'success') {
        $rag_response = $res['data'];
    } else {
        $error_message = $res['message'] ?? "The AI Study Assistant is currently unavailable. Please try again later.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Study Assistant - Student Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .chat-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }
        .answer-box {
            background: #f8f9fa;
            border-left: 4px solid #0d6efd;
            border-radius: 6px;
            padding: 1.25rem;
        }
        .citation-badge {
            background: #e7f1ff;
            color: #0c63e4;
            border: 1px solid #b6d4fe;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .chunk-box {
            background: #ffffff;
            border: 1px dashed #dee2e6;
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 0.85rem;
            color: #6c757d;
        }
    </style>
</head>
<body class="bg-light">

<div class="d-flex">
    <?php include 'sidebar.php'; ?>

    <div class="container-fluid p-4" style="margin-left: 260px;">
        <div class="mb-4">
            <h2 class="h3 fw-bold text-dark mb-1"><i class="fa-solid fa-graduation-cap text-primary me-2"></i>AI Course Study Assistant</h2>
            <p class="text-muted small mb-0">Ask questions grounded directly in your uploaded course study materials</p>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Search Form Card -->
        <div class="chat-card shadow-sm p-4 mb-4">
            <form method="POST" action="">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-muted small">Filter by Course Subject</label>
                        <select name="subject" class="form-select">
                            <option value="All" <?= ($selected_subject === 'All') ? 'selected' : '' ?>>All Subjects</option>
                            <?php foreach ($subjects as $subj): ?>
                                <option value="<?= htmlspecialchars($subj) ?>" <?= ($selected_subject === $subj) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subj) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-muted small">Filter by Topic (Optional)</label>
                        <input type="text" name="topic" class="form-control" placeholder="e.g. Memory Management" value="<?= htmlspecialchars($selected_topic) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Your Question</label>
                    <textarea name="question" class="form-control" rows="3" placeholder="e.g. What is process scheduling and what are its main algorithms?" required><?= htmlspecialchars($question_input) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary fw-semibold px-4">
                    <i class="fa-solid fa-paper-plane me-2"></i>Ask Study Assistant
                </button>
            </form>
        </div>

        <!-- RAG Answer Display -->
        <?php if ($rag_response): ?>
            <div class="chat-card shadow-sm p-4">
                <div class="d-flex align-items-center mb-3">
                    <div class="bg-primary text-white rounded-circle p-2 me-3" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-robot"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0">Grounded Answer</h5>
                        <small class="text-muted">Grounded in official course materials</small>
                    </div>
                </div>

                <div class="answer-box mb-4">
                    <div class="text-dark lh-base" style="white-space: pre-line;">
                        <?= htmlspecialchars($rag_response['answer']) ?>
                    </div>
                </div>

                <!-- Source Citations -->
                <?php if (!empty($rag_response['sources'])): ?>
                    <div class="mb-3">
                        <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-bookmark text-primary me-2"></i>Verified Source References:</h6>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($rag_response['sources'] as $src): ?>
                                <div class="citation-badge">
                                    <i class="fa-solid fa-file-pdf"></i>
                                    <span><?= htmlspecialchars($src['filename']) ?> — Page <?= (int)$src['page_number'] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="fa-solid fa-circle-info me-2"></i>No matching document pages found meeting the relevance threshold.
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
