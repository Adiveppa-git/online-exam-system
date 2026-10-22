<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/text_helpers.php';

// Auth Guard - Admin Only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$docId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$document = null;
$error = null;
$renderedHtml = '';

/**
 * Robust, XSS-safe Markdown to HTML parser for study materials.
 * Escapes all text elements before wrapping in semantic HTML tags.
 */
if (!function_exists('renderStudyMaterialMarkdown')) {
function renderStudyMaterialMarkdown(string $md): string {
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $lines = explode("\n", $md);
    $html = [];

    $inCodeBlock = false;
    $codeLines = [];
    $inList = false;
    $listType = '';
    $inBlockquote = false;
    $quoteLines = [];
    $inTable = false;
    $tableRows = [];

    $flushBlockquote = function() use (&$html, &$inBlockquote, &$quoteLines) {
        if ($inBlockquote) {
            $quoteText = implode("<br>", $quoteLines);
            $html[] = '<blockquote class="border-start border-4 border-primary ps-3 py-2 my-3 bg-light text-secondary rounded-end">' . $quoteText . '</blockquote>';
            $quoteLines = [];
            $inBlockquote = false;
        }
    };

    $flushList = function() use (&$html, &$inList, &$listType) {
        if ($inList) {
            $html[] = ($listType === 'ol') ? '</ol>' : '</ul>';
            $inList = false;
            $listType = '';
        }
    };

    $flushTable = function() use (&$html, &$inTable, &$tableRows) {
        if ($inTable && !empty($tableRows)) {
            $tableHtml = '<div class="table-responsive my-3"><table class="table table-bordered table-striped align-middle">';
            foreach ($tableRows as $rIdx => $row) {
                if ($rIdx === 0) {
                    $tableHtml .= '<thead class="table-light"><tr>';
                    foreach ($row as $cell) {
                        $tableHtml .= '<th class="fw-bold">' . htmlspecialchars(trim($cell), ENT_QUOTES, 'UTF-8') . '</th>';
                    }
                    $tableHtml .= '</tr></thead><tbody>';
                } else {
                    $tableHtml .= '<tr>';
                    foreach ($row as $cell) {
                        $tableHtml .= '<td>' . htmlspecialchars(trim($cell), ENT_QUOTES, 'UTF-8') . '</td>';
                    }
                    $tableHtml .= '</tr>';
                }
            }
            $tableHtml .= '</tbody></table></div>';
            $html[] = $tableHtml;
            $tableRows = [];
            $inTable = false;
        }
    };

    $formatInline = function(string $text): string {
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        // Inline code: `code`
        $text = preg_replace('/`([^`]+)`/', '<code class="bg-light text-danger px-1.5 py-0.5 rounded border" style="font-size:0.9em;">$1</code>', $text);
        // Bold: **text**
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        // Italic: *text*
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);
        // Markdown links: [label](url) - strictly sanitize scheme
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($m) {
            $label = $m[1];
            $url = $m[2];
            if (preg_match('#^https?://#i', $url) || preg_match('#^/[a-zA-Z0-9_.-]*#i', $url)) {
                return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" class="text-primary">' . $label . '</a>';
            }
            return $label;
        }, $text);
        return $text;
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Code block toggle: ```lang
        if (preg_match('/^```([a-zA-Z0-9_-]*)/', $trimmed, $m)) {
            if ($inCodeBlock) {
                $codeContent = htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES, 'UTF-8');
                $html[] = '<pre class="bg-dark text-light p-3 rounded-3 my-3 overflow-auto" style="font-size: 0.9rem; line-height: 1.5;"><code>' . $codeContent . '</code></pre>';
                $codeLines = [];
                $inCodeBlock = false;
            } else {
                $flushBlockquote();
                $flushList();
                $flushTable();
                $inCodeBlock = true;
            }
            continue;
        }

        if ($inCodeBlock) {
            $codeLines[] = $line;
            continue;
        }

        // Tables: | col1 | col2 |
        if (preg_match('/^\|(.+)\|$/', $trimmed, $m)) {
            // Check for delimiter row like | --- | --- |
            if (preg_match('/^\|[\s\-:|]+\|$/', $trimmed)) {
                continue;
            }
            $flushBlockquote();
            $flushList();
            $inTable = true;
            $cells = explode('|', trim($m[1]));
            $tableRows[] = $cells;
            continue;
        } else {
            $flushTable();
        }

        // Horizontal Rule: --- or ***
        if (preg_match('/^(\*{3,}|-{3,}|_{3,})$/', $trimmed)) {
            $flushBlockquote();
            $flushList();
            $html[] = '<hr class="my-4 text-muted opacity-25">';
            continue;
        }

        // Blockquote: > text
        if (preg_match('/^>\s?(.*)$/', $trimmed, $m)) {
            $flushList();
            $inBlockquote = true;
            $quoteLines[] = $formatInline($m[1]);
            continue;
        } else {
            $flushBlockquote();
        }

        // Headings: #, ##, ###, ####
        if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m)) {
            $flushList();
            $level = strlen($m[1]);
            $title = $formatInline($m[2]);
            $classes = [
                1 => 'h3 fw-bold text-dark border-bottom pb-2 mt-4 mb-3',
                2 => 'h4 fw-bold text-primary mt-4 mb-3',
                3 => 'h5 fw-semibold text-dark mt-3 mb-2',
                4 => 'h6 fw-bold text-secondary mt-3 mb-2',
                5 => 'h6 fw-semibold text-muted mt-2 mb-1',
                6 => 'small fw-bold text-muted mt-2 mb-1'
            ];
            $cls = $classes[$level] ?? 'h5 fw-bold';
            $html[] = "<h{$level} class=\"{$cls}\">{$title}</h{$level}>";
            continue;
        }

        // Unordered List Items: - or *
        if (preg_match('/^(\s*)([-*])\s+(.*)$/', $line, $m)) {
            $indent = strlen($m[1]);
            $itemText = $formatInline($m[3]);
            if (!$inList || $listType !== 'ul') {
                $flushList();
                $html[] = '<ul class="mb-3 ps-4">';
                $inList = true;
                $listType = 'ul';
            }
            if ($indent >= 2) {
                $html[] = '<li class="ms-3 mb-1 text-dark">' . $itemText . '</li>';
            } else {
                $html[] = '<li class="mb-1 text-dark">' . $itemText . '</li>';
            }
            continue;
        }

        // Ordered List Items: 1.
        if (preg_match('/^\s*(\d+)\.\s+(.*)$/', $line, $m)) {
            $itemText = $formatInline($m[2]);
            if (!$inList || $listType !== 'ol') {
                $flushList();
                $html[] = '<ol class="mb-3 ps-4">';
                $inList = true;
                $listType = 'ol';
            }
            $html[] = '<li class="mb-1 text-dark">' . $itemText . '</li>';
            continue;
        }

        // Empty line
        if ($trimmed === '') {
            $flushList();
            continue;
        }

        // Normal paragraph
        $flushList();
        $html[] = '<p class="mb-2 text-dark lh-base">' . $formatInline($trimmed) . '</p>';
    }

    $flushBlockquote();
    $flushList();
    $flushTable();
    if ($inCodeBlock) {
        $html[] = '<pre class="bg-dark text-light p-3 rounded-3 my-3"><code>' . htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES, 'UTF-8') . '</code></pre>';
    }

    return implode("\n", $html);
}
}

// Process Document Retrieval
if ($docId <= 0) {
    $error = "Invalid or missing document ID.";
} else {
    $stmt = $conn->prepare("
        SELECT d.*, e.title AS exam_title
        FROM ai_documents d
        LEFT JOIN exams e ON d.exam_id = e.id
        WHERE d.id = ?
    ");
    $stmt->bind_param("i", $docId);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $row = $res->fetch_assoc()) {
        $document = $row;
    } else {
        $error = "Study material #{$docId} does not exist in the database.";
    }
    $stmt->close();
}

if ($document && !$error) {
    $rawPath = $document['file_path'];
    $uploadsBaseDir = realpath(__DIR__ . '/../uploads/course_materials');

    // Resolve real file path securely
    $resolvedPath = realpath($rawPath);
    if ($resolvedPath === false) {
        // Fallback to uploads/course_materials/<filename> if stored path had different relative prefix
        $fallback = __DIR__ . '/../uploads/course_materials/' . basename($document['filename']);
        $resolvedPath = realpath($fallback);
    }

    if ($resolvedPath === false || !file_exists($resolvedPath)) {
        $error = "The physical file for Document #{$docId} ('" . htmlspecialchars($document['original_name']) . "') is missing or inaccessible on server storage.";
    } else {
        // Path traversal guard: must be inside uploads/course_materials
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
    <title><?= $document ? htmlspecialchars($document['original_name']) : 'View Study Material' ?> - Admin</title>
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
        .study-content-card {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
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
        @media print {
            .sidebar, .btn-toolbar-actions {
                display: none !important;
            }
            .main-admin-content {
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

    <div class="main-admin-content flex-grow-1">
        <!-- Top Navigation & Action Toolbar -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 btn-toolbar-actions">
            <div>
                <a href="manage_course_materials.php" class="btn btn-outline-secondary btn-sm mb-2">
                    <i class="fa-solid fa-arrow-left me-1"></i>Back to Course Materials
                </a>
                <h2 class="h4 fw-bold text-dark mb-0">
                    <i class="fa-solid fa-book-open-reader text-primary me-2"></i>Study Material Reader
                </h2>
            </div>
            <div class="d-flex gap-2">
                <button onclick="window.print()" class="btn btn-outline-primary btn-sm">
                    <i class="fa-solid fa-print me-1"></i>Print / Export
                </button>
                <a href="manage_course_materials.php" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-list me-1"></i>All Materials
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <!-- Error State -->
            <div class="card border-danger shadow-sm rounded-3 mb-4">
                <div class="card-body p-4 text-center">
                    <div class="text-danger mb-3" style="font-size: 3rem;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                    </div>
                    <h4 class="h5 fw-bold text-danger mb-2">Unable to Open Study Material</h4>
                    <p class="text-muted mb-4"><?= htmlspecialchars($error) ?></p>
                    <a href="manage_course_materials.php" class="btn btn-primary px-4">
                        <i class="fa-solid fa-arrow-left me-2"></i>Return to Course Materials
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Metadata Overview Card -->
            <div class="card border-0 shadow-sm rounded-3 mb-4" style="border-left: 5px solid #0d6efd !important;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <h3 class="h5 fw-bold text-dark mb-1">
                                <?= htmlspecialchars($document['original_name']) ?>
                            </h3>
                            <div class="text-muted small">
                                Database Record #<?= (int)$document['id'] ?> &bull; Generated <?= date('F d, Y \a\t H:i', strtotime($document['created_at'])) ?>
                            </div>
                        </div>
                        <div>
                            <?php if ($document['status'] === 'ingested'): ?>
                                <span class="badge bg-success px-3 py-2"><i class="fa-solid fa-circle-check me-1"></i>RAG Ingested</span>
                            <?php elseif ($document['status'] === 'failed'): ?>
                                <span class="badge bg-danger px-3 py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i>RAG Failed</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark px-3 py-2"><i class="fa-solid fa-clock me-1"></i>Pending</span>
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
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle" title="<?= htmlspecialchars($document['exam_title'] ?? ('Exam #' . $document['exam_id'])) ?>">
                                    <i class="fa-solid fa-graduation-cap me-1"></i>Exam #<?= (int)$document['exam_id'] ?> (<?= htmlspecialchars($document['exam_title'] ?? 'Active Exam') ?>)
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border">
                                    <i class="fa-solid fa-book-bookmark me-1"></i>Subject-level
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
                            <span class="text-muted small d-block">Vector Size / Chunks</span>
                            <span class="fw-semibold text-dark">
                                <?= (int)$document['total_chunks'] ?> chunks &bull; <?= round($document['file_size'] / 1024, 1) ?> KB
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rendered Markdown Content Card -->
            <div class="study-content-card rounded-3 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="card-title fw-bold text-dark mb-0">
                        <i class="fa-solid fa-file-lines text-primary me-2"></i>Study Notes Content
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
</body>
</html>
