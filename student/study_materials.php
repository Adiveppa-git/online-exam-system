<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

// Auth Guard: Student only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$current_page = basename($_SERVER['PHP_SELF']);

// Optional Filter and Search parameters
$search = trim($_GET['search'] ?? '');
$subjectFilter = trim($_GET['subject'] ?? '');

// Fetch distinct subjects for the filter dropdown (only from ingested materials)
$subjectsRes = $conn->query("
    SELECT DISTINCT d.subject 
    FROM ai_documents d 
    WHERE d.status = 'ingested' AND d.subject IS NOT NULL AND d.subject != ''
    ORDER BY d.subject ASC
");
$availableSubjects = [];
if ($subjectsRes) {
    while ($sRow = $subjectsRes->fetch_assoc()) {
        $availableSubjects[] = $sRow['subject'];
    }
}

// Build query with authorization guard:
// Only show status='ingested' materials that are general or linked to an active exam
$whereClauses = ["d.status = 'ingested'", "(d.exam_id IS NULL OR d.exam_id = 0 OR e.id IS NOT NULL)"];
$params = [];
$types = "";

if ($subjectFilter !== '') {
    $whereClauses[] = "d.subject = ?";
    $params[] = $subjectFilter;
    $types .= "s";
}

if ($search !== '') {
    $whereClauses[] = "(d.original_name LIKE ? OR d.filename LIKE ? OR d.subject LIKE ? OR d.topic LIKE ? OR e.title LIKE ?)";
    $likeSearch = "%" . $search . "%";
    $params[] = $likeSearch;
    $params[] = $likeSearch;
    $params[] = $likeSearch;
    $params[] = $likeSearch;
    $params[] = $likeSearch;
    $types .= "sssss";
}

$whereSql = implode(" AND ", $whereClauses);
$sql = "
    SELECT d.id, d.filename, d.original_name, d.file_path, d.file_size,
           d.subject, d.topic, d.total_pages, d.total_chunks, d.status,
           d.created_at, d.exam_id, d.is_generated,
           e.title AS exam_title
    FROM ai_documents d
    LEFT JOIN exams e ON d.exam_id = e.id
    WHERE {$whereSql}
    ORDER BY d.id DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$materials = $stmt->get_result();
$totalCount = $materials ? $materials->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Study Materials - Student</title>
    
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/student_mobile.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .materials-header {
            margin-bottom: 24px;
        }
        .materials-header h1 {
            margin-bottom: 6px;
            font-size: 26px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .materials-header p {
            color: #64748b;
            margin: 0;
            font-size: 14.5px;
        }
        
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            background: #ffffff;
            padding: 16px 20px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .filter-bar input[type="text"],
        .filter-bar select {
            height: 38px;
            padding: 6px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.15s ease;
        }
        .filter-bar input[type="text"]:focus,
        .filter-bar select:focus {
            border-color: #3b82f6;
        }
        .filter-bar input[type="text"] {
            min-width: 240px;
            flex-grow: 1;
        }
        .filter-btn {
            height: 38px;
            padding: 0 18px;
            background-color: #0d6efd;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .filter-btn:hover {
            background-color: #0b5ed7;
        }
        .filter-reset {
            font-size: 13.5px;
            color: #64748b;
            text-decoration: underline;
            margin-left: 6px;
        }
        .filter-reset:hover {
            color: #1e293b;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .materials-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            background: #ffffff;
        }
        .materials-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            font-size: 13.5px;
            text-align: left;
            padding: 14px 16px;
            border-bottom: 2px solid #e2e8f0;
            border-top: none;
            border-left: none;
            border-right: none;
            white-space: nowrap;
        }
        .materials-table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
            border-top: none;
            border-left: none;
            border-right: none;
            vertical-align: middle;
            font-size: 14px;
            color: #334155;
        }
        .materials-table tr:hover td {
            background: #f8fafc;
        }

        .material-title-cell {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .file-icon-box {
            width: 36px;
            height: 36px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }
        .file-icon-box.icon-md {
            background: #f0fdf4;
            color: #16a34a;
            border-color: #bbf7d0;
        }
        .file-icon-box.icon-pdf {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fecaca;
        }
        .material-primary-title {
            font-weight: 600;
            color: #0f172a;
            font-size: 14.5px;
            text-decoration: none;
        }
        .material-primary-title:hover {
            color: #2563eb;
            text-decoration: underline;
        }
        .material-secondary-desc {
            font-size: 12.5px;
            color: #64748b;
            margin-top: 3px;
        }

        .badge-subject {
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
            font-weight: 600;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        .badge-course {
            background: #f1f5f9;
            color: #475569;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 4px;
            margin-top: 4px;
            display: inline-block;
        }

        .view-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background-color: #0d6efd;
            color: #ffffff !important;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none !important;
            transition: background-color 0.15s ease, transform 0.15s ease;
            white-space: nowrap;
        }
        .view-btn:hover {
            background-color: #0b5ed7;
            transform: translateY(-1px);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-top: 10px;
        }
        .empty-state-icon {
            font-size: 48px;
            color: #94a3b8;
            margin-bottom: 16px;
        }
        .empty-state h3 {
            font-size: 18px;
            color: #1e293b;
            margin: 0 0 8px 0;
            font-weight: 600;
        }
        .empty-state p {
            color: #64748b;
            font-size: 14px;
            max-width: 480px;
            margin: 0 auto 18px auto;
            line-height: 1.5;
        }
    </style>
</head>
<body>

<div class="wrapper">

    <!-- ===== STUDENT SIDEBAR ===== -->
    <?php include "sidebar.php"; ?>

    <!-- ===== CONTENT ===== -->
    <div class="content">

        <div class="materials-header">
            <h1><i class="fa-solid fa-book-open text-primary"></i> Course Study Materials</h1>
            <p>Access authorized course notes, study guides, and lecture reference materials for your enrolled subjects.</p>
        </div>

        <!-- Filter & Search Bar -->
        <form method="GET" action="study_materials.php" class="filter-bar">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search materials by title, topic, or subject...">
            
            <select name="subject">
                <option value="">All Subjects</option>
                <?php foreach ($availableSubjects as $sbj): ?>
                    <option value="<?= htmlspecialchars($sbj) ?>" <?= $subjectFilter === $sbj ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sbj) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit" class="filter-btn">
                <i class="fa-solid fa-magnifying-glass"></i> Filter
            </button>
            
            <?php if ($search !== '' || $subjectFilter !== ''): ?>
                <a href="study_materials.php" class="filter-reset">Reset</a>
            <?php endif; ?>
        </form>

        <?php if ($totalCount > 0): ?>
            <div class="table-responsive">
                <table class="materials-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Material Title</th>
                            <th>Subject / Course</th>
                            <th>Topic &amp; Details</th>
                            <th>Type &amp; Size</th>
                            <th>Upload Date</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $idx = 1;
                        while ($doc = $materials->fetch_assoc()):
                            $docId = (int)$doc['id'];
                            $docTitle = !empty($doc['original_name']) ? $doc['original_name'] : $doc['filename'];
                            $fileExt = strtoupper(pathinfo($doc['filename'], PATHINFO_EXTENSION));
                            
                            $iconClass = 'icon-md';
                            $faIcon = 'fa-file-lines';
                            if ($fileExt === 'PDF') {
                                $iconClass = 'icon-pdf';
                                $faIcon = 'fa-file-pdf';
                            }
                            
                            $fileSizeStr = $doc['file_size'] > 0 
                                ? ($doc['file_size'] >= 1048576 
                                    ? round($doc['file_size'] / 1048576, 1) . ' MB' 
                                    : round($doc['file_size'] / 1024, 1) . ' KB')
                                : 'Text Document';
                                
                            $uploadDate = date('M d, Y', strtotime($doc['created_at']));
                            $viewUrl = "view_course_material.php?id=" . $docId . "&source=study_materials";
                        ?>
                        <tr>
                            <td><?= $idx++ ?></td>
                            <td>
                                <div class="material-title-cell">
                                    <div class="file-icon-box <?= $iconClass ?>">
                                        <i class="fa-solid <?= $faIcon ?>"></i>
                                    </div>
                                    <div>
                                        <a href="<?= htmlspecialchars($viewUrl) ?>" target="_blank" rel="noopener noreferrer" class="material-primary-title" title="Open in viewer">
                                            <?= htmlspecialchars($docTitle) ?>
                                        </a>
                                        <div class="material-secondary-desc">
                                            <?= (int)$doc['total_pages'] ?> <?= (int)$doc['total_pages'] === 1 ? 'page' : 'pages' ?> &bull; <?= (int)$doc['total_chunks'] ?> indexed chunks
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge-subject"><?= htmlspecialchars($doc['subject'] ?? 'General') ?></span>
                                <?php if (!empty($doc['exam_title'])): ?>
                                    <br><span class="badge-course"><i class="fa-solid fa-graduation-cap me-1"></i><?= htmlspecialchars($doc['exam_title']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 500; color: #1e293b;"><?= htmlspecialchars($doc['topic'] ?? 'General Course Material') ?></div>
                                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                    <?= !empty($doc['is_generated']) ? 'AI-Grounded Study Notes' : 'Uploaded Course Reference' ?>
                                </div>
                            </td>
                            <td>
                                <span style="font-weight: 600; font-size: 12.5px;"><?= htmlspecialchars($fileExt) ?></span>
                                <div style="font-size: 12px; color: #64748b;"><?= $fileSizeStr ?></div>
                            </td>
                            <td>
                                <span style="font-size: 13px; color: #475569;"><?= $uploadDate ?></span>
                            </td>
                            <td style="text-align: right;">
                                <a href="<?= htmlspecialchars($viewUrl) ?>" target="_blank" rel="noopener noreferrer" class="view-btn" title="Open study material in viewer">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> View Material
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fa-solid fa-book-open"></i>
                </div>
                <h3>No Study Materials Available</h3>
                <p>
                    <?= ($search !== '' || $subjectFilter !== '') 
                        ? 'No course study materials matched your search or subject filter criteria. Please check your spelling or clear filters.' 
                        : 'There are currently no authorized course study materials uploaded for your courses. Check back later.' ?>
                </p>
                <?php if ($search !== '' || $subjectFilter !== ''): ?>
                    <a href="study_materials.php" class="view-btn" style="display: inline-flex; margin-top: 8px;">
                        <i class="fa-solid fa-rotate-left"></i> View All Materials
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
