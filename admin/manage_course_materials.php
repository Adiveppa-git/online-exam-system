<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

// Auth Guard - Admin Only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$message = "";
$message_type = "info";

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

        if ($action === 'upload_material') {
            $subject = trim($_POST['subject'] ?? '');
            $topic = trim($_POST['topic'] ?? '');

            if (empty($subject) || empty($topic)) {
                $message = "Subject and Topic are required fields.";
                $message_type = "danger";
            } elseif (!isset($_FILES['material_file']) || $_FILES['material_file']['error'] !== UPLOAD_ERR_OK) {
                $message = "Please select a valid course material file to upload.";
                $message_type = "danger";
            } else {
                $file = $_FILES['material_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['pdf', 'txt', 'md'];

                if (!in_array($ext, $allowed)) {
                    $message = "Invalid file type. Only PDF, TXT, and MD files are supported.";
                    $message_type = "danger";
                } else {
                    $uploadDir = __DIR__ . '/../uploads/course_materials/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $uniqueName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
                    $targetPath = $uploadDir . $uniqueName;

                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        // Call AI Client RAG Ingestion API
                        $aiClient = new AiClient();
                        $ingestRes = $aiClient->ingestDocument($targetPath, $file['name'], $subject, $topic);

                        if ($ingestRes['status'] === 'success') {
                            $docData = $ingestRes['data'];
                            $docId = (int)($docData['document_id'] ?? 0);
                            $pages = (int)($docData['total_pages'] ?? 0);
                            $chunks = (int)($docData['total_chunks'] ?? 0);

                            // Sync DB status to ingested
                            $stmt = $conn->prepare("UPDATE ai_documents SET status = 'ingested' WHERE id = ?");
                            $stmt->bind_param("i", $docId);
                            $stmt->execute();

                            $message = "Course material '{$file['name']}' uploaded and ingested successfully into RAG Vector Store! ({$chunks} text chunks generated across {$pages} pages)";
                            $message_type = "success";
                        } else {
                            $errMsg = $ingestRes['message'] ?? 'RAG Ingestion Service failed.';
                            $message = "File uploaded, but RAG Ingestion failed: " . htmlspecialchars($errMsg);
                            $message_type = "warning";
                        }
                    } else {
                        $message = "Failed to save uploaded file on server.";
                        $message_type = "danger";
                    }
                }
            }
        } elseif ($action === 'delete_material') {
            $docId = (int)($_POST['doc_id'] ?? 0);
            if ($docId > 0) {
                // Fetch document details
                $stmt = $conn->prepare("SELECT file_path, subject, topic FROM ai_documents WHERE id = ?");
                $stmt->bind_param("i", $docId);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();

                if ($res) {
                    $aiClient = new AiClient();
                    $delRes = $aiClient->deleteDocument($docId);

                    // Delete file from disk if exists
                    if (file_exists($res['file_path'])) {
                        @unlink($res['file_path']);
                    }

                    // Delete metadata from database
                    $delStmt = $conn->prepare("DELETE FROM ai_documents WHERE id = ?");
                    $delStmt->bind_param("i", $docId);
                    $delStmt->execute();

                    $message = "Course material #{$docId} and its associated vector embeddings deleted successfully.";
                    $message_type = "success";
                }
            }
        }
    }
}

// Fetch all uploaded documents
$documents = [];
$res = $conn->query("SELECT * FROM ai_documents ORDER BY id DESC");
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="h3 fw-bold text-dark mb-1"><i class="fa-solid fa-book-open text-primary me-2"></i>Course Material Management (RAG)</h2>
                <p class="text-muted small mb-0">Upload and manage approved study materials for the AI Student Assistant</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Upload Form Card -->
        <div class="card border-0 shadow-sm mb-4 rounded-3 w-100">
            <div class="card-header bg-white py-3">
                <h5 class="card-title fw-bold text-dark mb-0"><i class="fa-solid fa-cloud-arrow-up text-primary me-2"></i>Upload New Study Material</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" class="row g-3">
                    <input type="hidden" name="action" value="upload_material">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Subject / Course</label>
                        <input type="text" name="subject" class="form-control" placeholder="e.g. Operating Systems" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Topic / Module</label>
                        <input type="text" name="topic" class="form-control" placeholder="e.g. Process Management" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Course File (.pdf, .txt, .md)</label>
                        <input type="file" name="material_file" class="form-control" accept=".pdf,.txt,.md" required>
                    </div>

                    <div class="col-12 mt-3">
                        <button type="submit" class="btn btn-primary fw-semibold px-4">
                            <i class="fa-solid fa-upload me-2"></i>Upload &amp; Index Material
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Document List Table -->
        <div class="card border-0 shadow-sm rounded-3 w-100">
            <div class="card-header bg-white py-3">
                <h5 class="card-title fw-bold text-dark mb-0"><i class="fa-solid fa-list text-primary me-2"></i>Uploaded Course Materials</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive w-100">
                    <table class="table table-hover align-middle mb-0 w-100">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 70px;">ID</th>
                                <th style="min-width: 200px;">Document Name</th>
                                <th style="min-width: 140px;">Subject</th>
                                <th style="min-width: 140px;">Topic</th>
                                <th style="width: 80px;">Pages</th>
                                <th style="width: 80px;">Chunks</th>
                                <th style="width: 110px;">Status</th>
                                <th style="width: 150px;">Uploaded At</th>
                                <th style="width: 100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($documents)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No course materials uploaded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($documents as $doc): ?>
                                    <tr>
                                        <td>#<?= $doc['id'] ?></td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($doc['original_name']) ?></div>
                                            <small class="text-muted"><?= round($doc['file_size'] / 1024, 1) ?> KB</small>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($doc['subject']) ?></span></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($doc['topic']) ?></span></td>
                                        <td><?= $doc['total_pages'] ?></td>
                                        <td><?= $doc['total_chunks'] ?></td>
                                        <td>
                                            <?php if ($doc['status'] === 'ingested'): ?>
                                                <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i>Ingested</span>
                                            <?php elseif ($doc['status'] === 'failed'): ?>
                                                <span class="badge bg-danger" title="<?= htmlspecialchars($doc['error_message']) ?>"><i class="fa-solid fa-triangle-exclamation me-1"></i>Failed</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><i class="fa-solid fa-spinner fa-spin me-1"></i>Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?= date('M d, Y H:i', strtotime($doc['created_at'])) ?></td>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this document and remove its vectors?');">
                                                <input type="hidden" name="action" value="delete_material">
                                                <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                                    <i class="fa-solid fa-trash"></i> Delete
                                                </button>
                                            </form>
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
</body>
</html>