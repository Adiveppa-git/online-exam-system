<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/text_helpers.php';

// Auth Guard: Student or Admin only
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$userRole = $_SESSION['role'] ?? 'student';
if (!in_array($userRole, ['student', 'admin'], true)) {
    header("Location: ../index.php");
    exit();
}

$docId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$fileQuery = isset($_GET['file']) ? trim($_GET['file']) : (isset($_GET['doc']) ? trim($_GET['doc']) : '');
$targetPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Validate origin source parameter
$rawSource = isset($_GET['source']) ? trim((string)$_GET['source']) : '';
$allowedSources = ['assistant', 'personalized_learning', 'study_materials'];
$source = in_array($rawSource, $allowedSources, true) ? $rawSource : '';

$document = null;
$error = null;
$renderedHtml = '';

// Retrieve document record with strict student authorization (must be ingested)
if ($docId > 0) {
    $stmt = $conn->prepare("
        SELECT d.*, e.title AS exam_title
        FROM ai_documents d
        LEFT JOIN exams e ON d.exam_id = e.id
        WHERE d.id = ? AND d.status = 'ingested'
    ");
    $stmt->bind_param("i", $docId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        $document = $row;
    } else {
        $error = "Study material #{$docId} is not available or you do not have permission to view it.";
    }
    $stmt->close();
} elseif ($fileQuery !== '') {
    $safeName = basename($fileQuery);
    $stmt = $conn->prepare("
        SELECT d.*, e.title AS exam_title
        FROM ai_documents d
        LEFT JOIN exams e ON d.exam_id = e.id
        WHERE (d.filename = ? OR d.original_name = ?) AND d.status = 'ingested'
        LIMIT 1
    ");
    $stmt->bind_param("ss", $safeName, $safeName);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        $document = $row;
        $docId = (int)$row['id'];
    } else {
        $error = "Requested study material ('" . htmlspecialchars($safeName) . "') is not available or you do not have permission to view it.";
    }
    $stmt->close();
} else {
    $error = "Invalid or missing study material reference.";
}

// Secure file access and path traversal check
if ($document && !$error) {
    $uploadsBaseDir = realpath(__DIR__ . '/../uploads/course_materials');
    $rawPath = $document['file_path'];

    $resolvedPath = realpath($rawPath);
    if ($resolvedPath === false) {
        $fallback = __DIR__ . '/../uploads/course_materials/' . basename($document['filename']);
        $resolvedPath = realpath($fallback);
    }

    if ($resolvedPath === false || !file_exists($resolvedPath)) {
        $error = "The physical file for Document #{$docId} ('" . htmlspecialchars($document['original_name']) . "') is missing on server storage.";
    } else {
        // Strict path traversal guard: target must be inside uploads/course_materials
        $normUploads = rtrim(str_replace('\\', '/', $uploadsBaseDir), '/') . '/';
        $normTarget = str_replace('\\', '/', $resolvedPath);

        if (strpos($normTarget, $normUploads) !== 0) {
            $error = "Security Violation: Access denied to files outside the course materials directory.";
        } else {
            $fileData = @file_get_contents($resolvedPath);
            if ($fileData === false) {
                $error = "Failed to read the study material content from server storage.";
            } else {
                $renderedHtml = renderStudyMaterialMarkdown($fileData);
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
    <title><?= $document ? htmlspecialchars($document['original_name']) : 'Study Material Viewer' ?> - Online Examination System</title>
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
        .study-content-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }
        .study-material-body {
            font-size: 15px;
            line-height: 1.7;
            color: #1e293b;
        }
        .study-material-body p {
            margin-bottom: 1rem;
        }
        .study-material-body h1, .study-material-body h2, .study-material-body h3 {
            color: #0f172a;
        }
        .highlight-jump {
            animation: highlightFade 3s ease-out;
        }
        @keyframes highlightFade {
            0% { background-color: #fef08a; }
            100% { background-color: transparent; }
        }
        @media print {
            .sidebar, .btn-toolbar-actions {
                display: none !important;
            }
            .main-content {
                margin: 0 !important;
                width: 100% !important;
                padding: 0 !important;
            }
            .study-content-card {
                border: none !important;
                box-shadow: none !important;
            }
        }
    </style>
</head>
<body class="bg-light">

<div class="d-flex min-vh-100">
    <?php include 'sidebar.php'; ?>

    <div class="main-content flex-grow-1">
        <!-- Top Navigation & Action Toolbar -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 btn-toolbar-actions">
            <div>
                <?php if ($source === 'assistant'): ?>
                    <a href="study_assistant.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 mb-2">
                        <i class="fa-solid fa-arrow-left me-1"></i>Back to AI Assistant
                    </a>
                <?php endif; ?>
                <h2 class="h4 fw-bold text-dark mb-0">
                    <i class="fa-solid fa-book-open-reader text-primary me-2"></i>Study Material Viewer
                </h2>
            </div>
            <div class="d-flex gap-2">
                <button onclick="window.print()" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                    <i class="fa-solid fa-print me-1"></i>Print / Save
                </button>
            </div>
        </div>

        <?php if ($error): ?>
            <!-- Error Card -->
            <div class="card border-danger shadow-sm rounded-3 mb-4">
                <div class="card-body p-4 text-center">
                    <div class="text-danger mb-3" style="font-size: 3rem;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                    </div>
                    <h4 class="h5 fw-bold text-danger mb-2">Unable to Open Study Material</h4>
                    <p class="text-muted mb-4"><?= htmlspecialchars($error) ?></p>
                    <?php
                    $returnUrl = 'study_assistant.php';
                    $returnLabel = 'Return to AI Assistant';
                    if ($source === 'personalized_learning') {
                        $returnUrl = 'personalized_learning.php';
                        $returnLabel = 'Return to Personalized Learning';
                    } elseif ($source === 'study_materials') {
                        $returnUrl = 'study_materials.php';
                        $returnLabel = 'Return to Study Materials';
                    }
                    ?>
                    <a href="<?= htmlspecialchars($returnUrl) ?>" class="btn btn-primary px-4 rounded-pill">
                        <i class="fa-solid fa-arrow-left me-2"></i><?= htmlspecialchars($returnLabel) ?>
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Material Header Overview Card -->
            <div class="card border-0 shadow-sm rounded-3 mb-4" style="border-left: 5px solid #0d6efd !important;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <h3 class="h5 fw-bold text-dark mb-1">
                                <?= htmlspecialchars($document['original_name']) ?>
                            </h3>
                            <div class="text-muted small">
                                Verified Study Note &bull; Published <?= date('M d, Y', strtotime($document['created_at'])) ?>
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge bg-success px-3 py-2"><i class="fa-solid fa-circle-check me-1"></i>Approved Study Material</span>
                            <?php if ($targetPage > 1): ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                                    <i class="fa-solid fa-bookmark me-1"></i>Referenced Page <?= (int)$targetPage ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row g-3 pt-2 border-top">
                        <div class="col-6 col-md-3">
                            <span class="text-muted small d-block">Subject</span>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                <i class="fa-solid fa-book me-1"></i><?= htmlspecialchars($document['subject']) ?>
                            </span>
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="text-muted small d-block">Associated Exam</span>
                            <?php if (!empty($document['exam_id'])): ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                    <i class="fa-solid fa-graduation-cap me-1"></i><?= htmlspecialchars($document['exam_title'] ?? ('Exam #' . $document['exam_id'])) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border">
                                    <i class="fa-solid fa-book-bookmark me-1"></i>Subject-wide Notes
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="text-muted small d-block">Topic Scope</span>
                            <span class="badge bg-light text-dark border">
                                <?= htmlspecialchars($document['topic']) ?>
                            </span>
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="text-muted small d-block">Content Scope</span>
                            <span class="fw-semibold text-dark">
                                <?= (int)$document['total_chunks'] ?> study sections &bull; <?= round($document['file_size'] / 1024, 1) ?> KB
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rendered Material Content Card -->
            <div class="study-content-card rounded-3 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="card-title fw-bold text-dark mb-0">
                        <i class="fa-solid fa-file-lines text-primary me-2"></i>Course Material Content
                    </h5>
                    <span class="badge bg-light text-muted border">
                        <i class="fa-solid fa-shield-halved me-1 text-success"></i>Grounded in Approved Question Bank
                    </span>
                </div>
                <div class="card-body p-4 p-md-5 study-material-body">
                    <?= $renderedHtml ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const targetPage = <?= (int)$targetPage ?>;
    if (targetPage > 1) {
        const anchor = document.getElementById('page-' + targetPage) || document.getElementById('section-' + targetPage);
        if (anchor) {
            anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
            anchor.classList.add('highlight-jump');
        }
    }
});
</script>
</body>
</html>
