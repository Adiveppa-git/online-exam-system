<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";

// Ensure upload directory exists
$upload_dir = __DIR__ . '/../uploads/course_materials/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Handle Document Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_material') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Invalid CSRF token request.";
        $message_type = "danger";
    } elseif (!isset($_FILES['material_file']) || $_FILES['material_file']['error'] !== UPLOAD_ERR_OK) {
        $message = "File upload failed or no file selected.";
        $message_type = "danger";
    } else {
        $subject = trim($_POST['subject'] ?? 'General');
        $topic = trim($_POST['topic'] ?? 'General');
        $original_name = basename($_FILES['material_file']['name']);
        $tmp_path = $_FILES['material_file']['tmp_name'];
        $file_size = $_FILES['material_file']['size'];

        $allowed_exts = ['pdf', 'txt', 'md'];
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_exts)) {
            $message = "Invalid file type. Only .pdf, .txt, and .md files are allowed.";
            $message_type = "danger";
        } elseif ($file_size > 15 * 1024 * 1024) {
            $message = "File exceeds maximum size limit of 15MB.";
            $message_type = "danger";
        } else {
            // Generate safe filename to prevent path traversal
            $safe_filename = uniqid('doc_', true) . '.' . $ext;
            $destination = $upload_dir . $safe_filename;

            if (move_uploaded_file($tmp_path, $destination)) {
                // Insert MySQL pending record
                $stmt = $conn->prepare("INSERT INTO ai_documents (filename, original_name, file_path, file_size, subject, topic, status, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)");
                $admin_id = $_SESSION['admin_id'] ?? 1;
                $stmt->bind_param("sssissi", $safe_filename, $original_name, $destination, $file_size, $subject, $topic, $admin_id);
                
                if ($stmt->execute()) {
                    $doc_id = $stmt->insert_id;

                    // Trigger Python FastAPI AI Service Ingestion
                    $aiClient = new AiClient();
                    $ingest_res = $aiClient->ingestDocument($destination, $doc_id, $original_name, $subject, $topic);

                    if ($ingest_res['status'] === 'success') {
                        $data = $ingest_res['data'];
                        $total_pages = $data['total_pages'] ?? 1;
                        $total_chunks = $data['total_chunks'] ?? 0;

                        // Update status to ingested
                        $up = $conn->prepare("UPDATE ai_documents SET status = 'ingested', total_pages = ?, total_chunks = ? WHERE id = ?");
                        $up->bind_param("iii", $total_pages, $total_chunks, $doc_id);
                        $up->execute();

                        // Populate MySQL chunk tracking table
                        if (!empty($data['chunks'])) {
                            $c_stmt = $conn->prepare("INSERT INTO ai_document_chunks (document_id, chunk_index, page_number, chunk_text, chunk_hash) VALUES (?, ?, ?, ?, ?)");
                            foreach ($data['chunks'] as $chk) {
                                $c_stmt->bind_param("iiiss", $doc_id, $chk['chunk_index'], $chk['page_number'], $chk['chunk_text'], $chk['chunk_hash']);
                                $c_stmt->execute();
                            }
                        }

                        $message = "Document '{$original_name}' uploaded and indexed successfully into ChromaDB ({$total_pages} pages, {$total_chunks} chunks).";
                        $message_type = "success";
                    } else {
                        $err_msg = $ingest_res['message'] ?? 'Unknown AI ingestion error';
                        $up = $conn->prepare("UPDATE ai_documents SET status = 'failed', error_message = ? WHERE id = ?");
                        $up->bind_param("si", $err_msg, $doc_id);
                        $up->execute();

                        $message = "Document uploaded to server, but AI ingestion failed: " . htmlspecialchars($err_msg);
                        $message_type = "warning";
                    }
                } else {
                    $message = "Failed to record document metadata in database.";
                    $message_type = "danger";
                }
            } else {
                $message = "Failed to save uploaded file to destination server directory.";
                $message_type = "danger";
            }
        }
    }
}

// Handle Document Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_material') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Invalid CSRF token request.";
        $message_type = "danger";
    } else {
        $doc_id = (int)$_POST['doc_id'];
        
        // Fetch file info
        $stmt = $conn->prepare("SELECT file_path, original_name FROM ai_documents WHERE id = ?");
        $stmt->bind_param("i", $doc_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();

        if ($res) {
            // 1. Delete ChromaDB vectors via AI Client
            $aiClient = new AiClient();
            $aiClient->deleteRAGDocument($doc_id);

            // 2. Delete server physical file
            if (file_exists($res['file_path'])) {
                @unlink($res['file_path']);
            }

            // 3. Delete MySQL record (ON DELETE CASCADE handles ai_document_chunks)
            $del = $conn->prepare("DELETE FROM ai_documents WHERE id = ?");
            $del->bind_param("i", $doc_id);
            $del->execute();

            $message = "Course material '{$res['original_name']}' deleted from database and vector index.";
            $message_type = "success";
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
</head>
<body class="bg-light">

<div class="d-flex">
    <?php include 'sidebar.php'; ?>

    <div class="container-fluid p-4" style="margin-left: 260px;">
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
        <div class="card border-0 shadow-sm mb-4 rounded-3">
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
                            <i class="fa-solid fa-upload me-2"></i>Upload & Index Material
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Document List Table -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3">
                <h5 class="card-title fw-bold text-dark mb-0"><i class="fa-solid fa-list text-primary me-2"></i>Uploaded Course Materials</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Document Name</th>
                                <th>Subject</th>
                                <th>Topic</th>
                                <th>Pages</th>
                                <th>Chunks</th>
                                <th>Status</th>
                                <th>Uploaded At</th>
                                <th>Actions</th>
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
