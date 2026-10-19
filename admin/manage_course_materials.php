<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';
require_once __DIR__ . '/../config/study_material_service.php';

// Auth Guard - Admin Only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$message = "";
$message_type = "info";
$selectedSubject = "";

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Invalid CSRF token.";
        $message_type = "danger";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'generate_study_material') {
            $subject = trim($_POST['subject'] ?? '');
            $adminId = (int)($_SESSION['user_id'] ?? 1);
            $selectedSubject = $subject;

            if (empty($subject)) {
                $message = "Please select a Subject to generate study material.";
                $message_type = "warning";
            } else {
                $genRes = StudyMaterialService::generateSubjectStudyMaterial($conn, $subject, $adminId);
                $message = $genRes['message'] ?? 'Study material processed.';
                if (!empty($genRes['success'])) {
                    $message_type = 'success';
                } elseif (!empty($genRes['empty'])) {
                    $message_type = 'warning';
                } else {
                    $message_type = 'danger';
                }
            }
        } elseif ($action === 'retry_indexing') {
            $docId = (int)($_POST['doc_id'] ?? 0);
            $adminId = (int)($_SESSION['user_id'] ?? 1);
            if ($docId > 0) {
                $retryRes = StudyMaterialService::retryIngestion($conn, $docId, $adminId);
                $message = $retryRes['message'] ?? 'Indexing processed.';
                $message_type = !empty($retryRes['success']) ? 'success' : 'danger';
            }
        } elseif ($action === 'delete_material') {
            $docId = (int)($_POST['doc_id'] ?? 0);
            if ($docId > 0) {
                $delRes = StudyMaterialService::deleteMaterial($conn, $docId);
                if (!empty($delRes['success'])) {
                    if (!empty($delRes['vector_deleted'])) {
                        $message = "Course material #{$docId} and its associated vector embeddings deleted successfully.";
                    } else {
                        $message = "Course material #{$docId} deleted from database, but vector store cleanup failed: " . htmlspecialchars($delRes['vector_error'] ?? 'Service unavailable') . ". Queued for retry.";
                    }
                    $message_type = "success";
                } else {
                    $message = $delRes['message'] ?? "Course material not found or already deleted.";
                    $message_type = "warning";
                }
            }
        }
    }
}

// Fetch reference data and approved subjects
StudyMaterialService::ensureSchema($conn);
$approvedSubjects = StudyMaterialService::getSubjectsWithApprovedQuestions($conn);

$documents = [];
$res = $conn->query("
    SELECT d.*, e.title AS exam_title
    FROM ai_documents d
    LEFT JOIN exams e ON d.exam_id = e.id
    ORDER BY d.id DESC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $documents[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Material Management (RAG) - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .main-admin-content {
            margin-left: 250px;
            width: calc(100% - 250px);
            max-width: none;
            padding: 28px 36px;
            transition: margin-left 0.3s ease, width 0.3s ease;
            box-sizing: border-box;
            min-width: 0;
        }
        .sidebar.closed ~ .main-admin-content {
            margin-left: 60px;
            width: calc(100% - 60px);
        }
        @media (max-width: 768px) {
            .main-admin-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 16px !important;
            }
        }
        .card {
            width: 100%;
        }
    </style>
</head>
<body class="bg-light">

<div class="d-flex min-vh-100">
    <?php include 'sidebar.php'; ?>

    <div class="main-admin-content flex-grow-1">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h2 class="h3 fw-bold text-dark mb-1"><i class="fa-solid fa-book-open text-primary me-2"></i>Course Material Management (RAG)</h2>
                <p class="text-muted small mb-0">Generate and index approved study materials for the AI Student Assistant</p>
            </div>
            <a href="review_ai_questions.php" class="btn btn-outline-primary btn-sm">
                <i class="fa-solid fa-clipboard-check me-1"></i>Review Queue
            </a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Generate Study Materials Section (Simplified Controls Only) -->
        <div class="card border-0 shadow-sm rounded-3 mb-4" style="border-left: 5px solid #0d6efd !important;">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="card-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-wand-magic-sparkles text-primary me-2"></i>Generate Study Materials from Approved AI Questions
                </h5>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                    <i class="fa-solid fa-database me-1"></i>Approved Questions Only
                </span>
            </div>
            <div class="card-body p-4">
                <p class="text-muted small mb-3">
                    Select a subject to automatically synthesize structured course study notes, key concepts, definitions, explanations, and practice points exclusively from approved AI questions.
                </p>

                <?php if (empty($approvedSubjects)): ?>
                    <div class="alert alert-warning mb-0" role="alert">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i>
                        No approved AI-generated questions found in the question bank. Please generate and approve questions first in the <a href="review_ai_questions.php" class="alert-link">AI Question Review Queue</a>.
                    </div>
                <?php else: ?>
                    <form method="POST" id="generateStudyMaterialForm">
                        <input type="hidden" name="action" value="generate_study_material">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                        <div class="row align-items-end g-3">
                            <div class="col-md-7 col-lg-6">
                                <label class="form-label fw-semibold" for="subjectSelect">Select Subject <span class="text-danger">*</span></label>
                                <select name="subject" id="subjectSelect" class="form-select" required>
                                    <option value="">-- Select Subject --</option>
                                    <?php foreach ($approvedSubjects as $s): ?>
                                        <option value="<?= htmlspecialchars($s['subject']) ?>" <?= ($selectedSubject === $s['subject']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($s['subject']) ?> (<?= $s['count'] ?> approved question<?= $s['count'] > 1 ? 's' : '' ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5 col-lg-4">
                                <button type="submit" class="btn btn-primary fw-semibold px-4 py-2 w-100" id="btnGenerate">
                                    <i class="fa-solid fa-book-bookmark me-2"></i>Generate Study Materials
                                </button>
                            </div>
                        </div>

                        <!-- Dynamic Loading Indicator -->
                        <div id="loadingIndicator" class="alert alert-info d-none mt-3 mb-0 d-flex align-items-center" role="status">
                            <div class="spinner-border spinner-border-sm text-primary me-3" role="status" aria-hidden="true"></div>
                            <div>
                                <strong>Generating and Indexing Study Material...</strong>
                                <div class="small text-muted">Retrieving approved questions, grouping detected topics, synthesizing notes, and indexing vectors into RAG. This may take a few moments.</div>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Course Materials Table -->
        <div class="card border-0 shadow-sm rounded-3 w-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="card-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-list text-primary me-2"></i>Course Materials (RAG Vector Store)
                </h5>
                <span class="badge bg-secondary-subtle text-secondary-emphasis border">
                    Total: <?= count($documents) ?> Document<?= count($documents) !== 1 ? 's' : '' ?>
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive w-100">
                    <table class="table table-hover align-middle mb-0 w-100">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 60px;">SL No</th>
                                <th style="width: 130px;">Exam ID</th>
                                <th style="min-width: 220px;">Document Name</th>
                                <th style="min-width: 120px;">Subject</th>
                                <th style="min-width: 140px;">Topic(s)</th>
                                <th style="width: 70px;">Pages</th>
                                <th style="width: 70px;">Chunks</th>
                                <th style="width: 110px;">Status</th>
                                <th style="width: 140px;">Generated At</th>
                                <th style="width: 140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($documents)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">No course materials indexed yet. Generate study materials above to populate the AI Student Assistant.</td>
                                </tr>
                            <?php else: ?>
                                <?php $slno = 1; foreach ($documents as $doc): ?>
                                    <tr>
                                        <td class="fw-bold text-muted"><?= $slno++ ?></td>
                                        <td>
                                            <?php if (!empty($doc['exam_id'])): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle" title="<?= htmlspecialchars($doc['exam_title'] ?? ('Exam #' . $doc['exam_id'])) ?>">
                                                    <i class="fa-solid fa-graduation-cap me-1"></i>Exam #<?= (int)$doc['exam_id'] ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis border" title="Covers entire subject; not tied to a single exam">
                                                    <i class="fa-solid fa-book-bookmark me-1"></i>Subject-level
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($doc['original_name']) ?></div>
                                            <div class="d-flex align-items-center gap-1 mt-1 flex-wrap">
                                                <span class="badge bg-light text-muted border" style="font-size: 11px;">Doc #<?= $doc['id'] ?></span>
                                                <small class="text-muted"><?= round($doc['file_size'] / 1024, 1) ?> KB</small>
                                                <?php if (!empty($doc['is_generated']) || !empty($doc['source_question_ids'])): ?>
                                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 11px;"><i class="fa-solid fa-microchip me-1"></i>AI Question Bank</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border" style="font-size: 11px;"><i class="fa-solid fa-file-arrow-up me-1"></i>Manual Upload</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($doc['subject']) ?></span></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($doc['topic']) ?></span></td>
                                        <td><?= $doc['total_pages'] ?></td>
                                        <td><?= $doc['total_chunks'] ?></td>
                                        <td>
                                            <?php if ($doc['status'] === 'ingested'): ?>
                                                <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i>Ingested</span>
                                            <?php elseif ($doc['status'] === 'failed'): ?>
                                                <span class="badge bg-danger" title="<?= htmlspecialchars($doc['error_message'] ?? '') ?>"><i class="fa-solid fa-triangle-exclamation me-1"></i>Failed</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><i class="fa-solid fa-spinner fa-spin me-1"></i>Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?= date('M d, Y H:i', strtotime($doc['created_at'])) ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-1">
                                                <?php if ($doc['status'] === 'failed'): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="retry_indexing">
                                                        <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                        <button type="submit" class="btn btn-outline-warning btn-sm" title="Retry RAG Ingestion">
                                                            <i class="fa-solid fa-rotate-right"></i> Retry
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="delete_material">
                                                    <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete Material">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('generateStudyMaterialForm');
    const btn = document.getElementById('btnGenerate');
    const select = document.getElementById('subjectSelect');
    const loadingBox = document.getElementById('loadingIndicator');

    if (form && btn && select) {
        form.addEventListener('submit', function (e) {
            if (!select.value) {
                e.preventDefault();
                select.focus();
                return false;
            }

            // Show loading indicator & disable button to prevent double-submits
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Generating & Indexing...';
            if (loadingBox) {
                loadingBox.classList.remove('d-none');
            }
        });
    }
});
</script>
</body>
</html>