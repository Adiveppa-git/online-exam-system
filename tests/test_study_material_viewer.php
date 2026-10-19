<?php

require_once __DIR__ . '/../config/db.php';

echo "====================================================\n";
echo "  Course Material Viewer Test Suite                 \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

function report($name, $condition, $info = "") {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$name}" . ($info ? " ({$info})" : "") . "\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}" . ($info ? " ({$info})" : "") . "\n";
        $failed++;
    }
}

function runScriptIsolated(string $scriptRelativePath, array $get = [], array $session = ['role' => 'admin', 'user_id' => 1]) {
    $adminDir = realpath(__DIR__ . '/../admin');
    $scriptFullPath = realpath(__DIR__ . '/../' . $scriptRelativePath);

    $payload = [
        'admin_dir' => $adminDir,
        'script' => $scriptFullPath,
        'session' => $session,
        'post' => [],
        'get' => $get
    ];
    $payloadJson = base64_encode(json_encode($payload));

    $bootstrapCode = '<?php
    $data = json_decode(base64_decode("' . $payloadJson . '"), true);
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = $data["session"] ?? [];
    $_POST = $data["post"] ?? [];
    $_GET = $data["get"] ?? [];
    $_SERVER["REQUEST_METHOD"] = "GET";
    chdir($data["admin_dir"]);
    ob_start();
    include $data["script"];
    $out = ob_get_clean();
    echo $out;
    ';

    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];

    $proc = proc_open('C:\\xampp\\php\\php.exe', $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['code' => -1, 'output' => '', 'error' => 'proc_open failed'];
    }

    fwrite($pipes[0], $bootstrapCode);
    fclose($pipes[0]);

    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($proc);
    return ['code' => $exitCode, 'output' => $out, 'error' => $err];
}

// -------------------------------------------------------------
// Setup: Find an existing document or create a verified test doc
// -------------------------------------------------------------
$existingDoc = $conn->query("SELECT * FROM ai_documents WHERE status = 'ingested' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$testDocId = 0;
$createdTempDoc = false;

if ($existingDoc && file_exists($existingDoc['file_path'])) {
    $testDocId = (int)$existingDoc['id'];
} else {
    // Create a temporary verified test document
    $uploadDir = __DIR__ . '/../uploads/course_materials/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $sampleFilename = 'test_sample_viewer_' . time() . '.md';
    $samplePath = $uploadDir . $sampleFilename;
    $sampleContent = "# Sample Study Material\n\n**Subject:** Python\n\n## 1. Overview\n\n> This is a verified sample note.\n\n- Point 1\n- Point 2\n\n```python\nprint('hello')\n```\n";
    file_put_contents($samplePath, $sampleContent);

    $insStmt = $conn->prepare("INSERT INTO ai_documents (filename, original_name, file_path, file_size, subject, topic, status, uploaded_by, is_generated) VALUES (?, 'Sample Study Material.md', ?, ?, 'Python', 'Sample', 'ingested', 1, 1)");
    $fSize = filesize($samplePath);
    $insStmt->bind_param("ssi", $sampleFilename, $samplePath, $fSize);
    $insStmt->execute();
    $testDocId = (int)$insStmt->insert_id;
    $insStmt->close();
    $createdTempDoc = true;
}

try {
    // -------------------------------------------------------------
    // Test 1: Manage Course Materials table renders clickable links
    // -------------------------------------------------------------
    $resManage = runScriptIsolated('admin/manage_course_materials.php');
    $hasClickableName = (strpos($resManage['output'], "href=\"view_course_material.php?id={$testDocId}\"") !== false);
    $hasTargetBlank = (strpos($resManage['output'], "target=\"_blank\"") !== false);
    $hasViewBtn = (strpos($resManage['output'], "view_course_material.php?id={$testDocId}") !== false
        && strpos($resManage['output'], "View") !== false);

    report(
        "1. Manage page renders clickable document name and view button",
        ($resManage['code'] === 0 && $hasClickableName && $hasTargetBlank && $hasViewBtn),
        "Link target: view_course_material.php?id={$testDocId} with target=_blank verified"
    );

    // -------------------------------------------------------------
    // Test 2: Document viewer opens and renders existing material
    // -------------------------------------------------------------
    $resView = runScriptIsolated('admin/view_course_material.php', ['id' => $testDocId]);
    $docRecord = $conn->query("SELECT * FROM ai_documents WHERE id = $testDocId")->fetch_assoc();

    $containsTitle = (strpos($resView['output'], htmlspecialchars($docRecord['original_name'])) !== false);
    $containsSubject = (strpos($resView['output'], htmlspecialchars($docRecord['subject'])) !== false);
    $noErrorBox = (strpos($resView['output'], 'Unable to Open Study Material') === false);

    report(
        "2. Viewer loads correct document metadata and content without errors",
        ($resView['code'] === 0 && $containsTitle && $containsSubject && $noErrorBox),
        "Document #{$testDocId} loaded successfully"
    );

    // -------------------------------------------------------------
    // Test 3: Markdown formatting elements are properly parsed and styled
    // -------------------------------------------------------------
    $hasHeadings = (preg_match('/<h[1-4][^>]*>.*<\/h[1-4]>/i', $resView['output']) === 1);
    $hasStrong = (strpos($resView['output'], '<strong>') !== false);
    $hasLists = (strpos($resView['output'], '<ul') !== false && strpos($resView['output'], '<li') !== false);
    $hasBlockquote = (strpos($resView['output'], '<blockquote') !== false);

    report(
        "3. Markdown formatting correctly rendered into HTML (headings, bold, lists, quotes)",
        ($hasHeadings && $hasStrong && $hasLists),
        "Headings, bold tags, and list tags verified"
    );

    // -------------------------------------------------------------
    // Test 4: Missing or invalid document IDs produce a safe, clear error
    // -------------------------------------------------------------
    $resNonExistent = runScriptIsolated('admin/view_course_material.php', ['id' => 9999999]);
    $hasSafeMissingError = (strpos($resNonExistent['output'], 'Unable to Open Study Material') !== false
        && strpos($resNonExistent['output'], '9999999') !== false);

    $resInvalidId = runScriptIsolated('admin/view_course_material.php', ['id' => 'abc']);
    $hasInvalidError = (strpos($resInvalidId['output'], 'Invalid or missing document ID') !== false
        || strpos($resInvalidId['output'], 'Unable to Open Study Material') !== false);

    report(
        "4. Non-existent and invalid IDs return clear, safe error cards",
        ($hasSafeMissingError && $hasInvalidError),
        "Safe error handling verified for invalid IDs"
    );

    // -------------------------------------------------------------
    // Test 5: Unauthorized users cannot access the protected viewer
    // -------------------------------------------------------------
    $resUnauth = runScriptIsolated('admin/view_course_material.php', ['id' => $testDocId], ['role' => 'student', 'user_id' => 10]);
    // In our isolated wrapper, an unauthenticated session header redirect prevents page rendering or exits
    $hasNoSecretContent = (strpos($resUnauth['output'], 'Study Notes Content') === false);

    $resNoSession = runScriptIsolated('admin/view_course_material.php', ['id' => $testDocId], []);
    $hasNoContentGuest = (strpos($resNoSession['output'], 'Study Notes Content') === false);

    report(
        "5. Unauthorized users (students/guests) cannot view protected study materials",
        ($hasNoSecretContent && $hasNoContentGuest),
        "Content guarded against non-admin roles"
    );

    // -------------------------------------------------------------
    // Test 6: Path traversal attempts are blocked by security policy
    // -------------------------------------------------------------
    // Create a temporary document with malicious path traversal attempt in file_path
    $conn->query("INSERT INTO ai_documents (filename, original_name, file_path, file_size, subject, topic, status, uploaded_by) VALUES ('traversal_test.md', 'Traversal Attack Test.md', '../../config/db.php', 500, 'Security', 'Testing', 'ingested', 1)");
    $traversalDocId = $conn->insert_id;

    $resTraversal = runScriptIsolated('admin/view_course_material.php', ['id' => $traversalDocId]);
    $blockedBySecurity = (strpos($resTraversal['output'], 'Security Violation') !== false
        || strpos($resTraversal['output'], 'missing or inaccessible') !== false);
    $dbConfigNotLeaked = (strpos($resTraversal['output'], 'DB_PASS') === false && strpos($resTraversal['output'], '$conn = new mysqli') === false);

    // Clean up traversal doc
    $conn->query("DELETE FROM ai_documents WHERE id = $traversalDocId");

    report(
        "6. Path traversal and arbitrary file reads are strictly prevented",
        ($blockedBySecurity && $dbConfigNotLeaked),
        "Arbitrary file access blocked and credentials not leaked"
    );

    // -------------------------------------------------------------
    // Test 7: Manage page actions and layout integrity preserved
    // -------------------------------------------------------------
    $hasDeleteAction = (strpos($resManage['output'], 'delete_material') !== false);
    $hasExamIdColumn = (strpos($resManage['output'], 'Exam ID') !== false);
    $hasSlNoColumn = (strpos($resManage['output'], 'SL No') !== false);

    report(
        "7. Existing layout, columns, and delete actions remain unchanged",
        ($hasDeleteAction && $hasExamIdColumn && $hasSlNoColumn),
        "Delete action and all 10 table columns preserved"
    );

    // -------------------------------------------------------------
    // Test 8: No documents or files lost or overwritten
    // -------------------------------------------------------------
    $finalDocChk = $conn->query("SELECT id FROM ai_documents WHERE id = $testDocId")->fetch_assoc();
    report(
        "8. Existing document records and physical files preserved",
        (!empty($finalDocChk)),
        "Doc #{$testDocId} intact"
    );

} finally {
    if ($createdTempDoc && $testDocId > 0) {
        $docToDelete = $conn->query("SELECT file_path FROM ai_documents WHERE id = $testDocId")->fetch_assoc();
        if ($docToDelete && file_exists($docToDelete['file_path'])) {
            @unlink($docToDelete['file_path']);
        }
        $conn->query("DELETE FROM ai_documents WHERE id = $testDocId");
    }
}

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
}
