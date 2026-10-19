<?php
/**
 * Test Suite: Automatic Study Material Cleanup on Exam Deletion
 * and Confirmation Popup Removal.
 *
 * Verifies:
 * 1. Absence of JavaScript native confirmation dialog in manage_course_materials.php.
 * 2. Safe, direct individual material deletion without popup.
 * 3. Idempotent material deletion handling missing/already-deleted records.
 * 4. Automatic cleanup of study material and vector embeddings on exam deletion.
 * 5. Automatic cleanup of multiple materials belonging to a single exam.
 * 6. Cross-exam isolation (other exams' study materials are untouched).
 * 7. Graceful handling of non-existent or repeated exam deletions.
 * 8. Vector store unreachable failure logging, queueing in ai_vector_cleanup_queue, and retryability.
 * 9. Full end-to-end exam deletion via admin/exams.php with CSRF protection.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';
require_once __DIR__ . '/../config/study_material_service.php';

echo "====================================================\n";
echo " Study Material Automatic Cleanup & Popup Test Suite \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

StudyMaterialService::ensureSchema($conn);

function report(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "[PASS] {$name}" . ($detail ? " ({$detail})" : "") . "\n";
    } else {
        $failed++;
        echo "[FAIL] {$name}" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

function runScriptIsolated(string $scriptRelativePath, array $session = [], array $post = [], array $get = []): array {
    $scriptFullPath = realpath(__DIR__ . '/../' . $scriptRelativePath);
    $payload = [
        'script' => $scriptFullPath,
        'session' => $session,
        'post' => $post,
        'get' => $get,
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
    $_SERVER["REQUEST_METHOD"] = !empty($_POST) ? "POST" : "GET";
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
        return ['stdout' => '', 'stderr' => 'Failed to proc_open php', 'exit' => -1];
    }

    fwrite($pipes[0], $bootstrapCode);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($proc);

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exit' => $exitCode
    ];
}

// --------------------------------------------------------------------------
// TEST 1: Removal of delete confirmation popup in manage_course_materials.php
// --------------------------------------------------------------------------
try {
    $content = file_get_contents(__DIR__ . '/../admin/manage_course_materials.php');

    $hasConfirmText = str_contains($content, 'Are you sure you want to delete this course material');
    $hasOnsubmitConfirm = preg_match('/onsubmit\s*=\s*["\'][^"\']*confirm/i', $content);
    $hasDeleteForm = str_contains($content, 'name="action" value="delete_material"');
    $hasCsrfToken = str_contains($content, 'name="csrf_token"');
    $hasDeleteButton = str_contains($content, 'btn-outline-danger') && str_contains($content, 'fa-trash');

    $noPopup = (!$hasConfirmText && !$hasOnsubmitConfirm);
    $formValid = ($hasDeleteForm && $hasCsrfToken && $hasDeleteButton);

    report(
        "1. Confirmation popup removed and direct delete form preserved",
        $noPopup && $formValid,
        "No confirm() popup present; form directly submits POST with CSRF token"
    );
} catch (Throwable $e) {
    report("1. Confirmation popup removed", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 2: Direct individual course material deletion via StudyMaterialService
// --------------------------------------------------------------------------
try {
    // Create dummy local file
    $testDir = __DIR__ . '/../uploads/course_materials';
    if (!is_dir($testDir)) {
        mkdir($testDir, 0755, true);
    }
    $dummyFile = $testDir . '/test_mat_' . time() . '_' . rand(1000, 9999) . '.txt';
    file_put_contents($dummyFile, "Sample content for test material.");

    // Insert test document
    $ins = $conn->prepare("INSERT INTO ai_documents (filename, original_name, file_path, file_size, subject, topic, status, total_chunks) VALUES (?, 'test.txt', ?, 30, 'TestSubj', 'TestTopic', 'ingested', 1)");
    $fname = basename($dummyFile);
    $ins->bind_param("ss", $fname, $dummyFile);
    $ins->execute();
    $testDocId = $conn->insert_id;
    $ins->close();

    // Insert dummy chunk
    $cIns = $conn->prepare("INSERT INTO ai_document_chunks (document_id, chunk_index, page_number, chunk_text, chunk_hash) VALUES (?, 0, 1, 'Sample chunk text', 'testhash')");
    $cIns->bind_param("i", $testDocId);
    $cIns->execute();
    $cIns->close();

    // Execute deletion
    $delRes = StudyMaterialService::deleteMaterial($conn, $testDocId);

    // Verify row removed
    $chkDoc = $conn->query("SELECT id FROM ai_documents WHERE id = {$testDocId}")->fetch_assoc();
    $chkChunk = $conn->query("SELECT id FROM ai_document_chunks WHERE document_id = {$testDocId}")->fetch_assoc();
    $fileDeleted = !file_exists($dummyFile);

    $success = ($delRes['success'] && $chkDoc === null && $chkChunk === null && $fileDeleted);
    report("2. Direct material deletion cleans up document, chunks, and file", $success, "Doc #{$testDocId} cleaned up");
} catch (Throwable $e) {
    report("2. Direct material deletion", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 3: Idempotent material deletion on already-deleted records
// --------------------------------------------------------------------------
try {
    // Retry deletion on already-deleted ID
    $retryDel = StudyMaterialService::deleteMaterial($conn, $testDocId);
    $isIdempotent = ($retryDel['success'] && !empty($retryDel['already_deleted']));
    report("3. Idempotent deletion on already-deleted document ID", $isIdempotent, "Returned graceful success without error");
} catch (Throwable $e) {
    report("3. Idempotent deletion", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 4: Automatic cleanup of study materials when an exam is deleted
// --------------------------------------------------------------------------
try {
    // 1. Create a test exam
    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Auto Cleanup Test Exam', 30, 10, 1)");
    $examId = (int)$conn->insert_id;

    // 2. Create associated study material
    $dummyFile = $testDir . '/exam_mat_' . time() . '.txt';
    file_put_contents($dummyFile, "Content for exam {$examId}");
    $fname = basename($dummyFile);

    $insDoc = $conn->prepare("INSERT INTO ai_documents (filename, original_name, file_path, file_size, subject, topic, status, exam_id, total_chunks) VALUES (?, 'exam_mat.txt', ?, 20, 'AutoTest', 'Cleanup', 'ingested', ?, 1)");
    $insDoc->bind_param("ssi", $fname, $dummyFile, $examId);
    $insDoc->execute();
    $matDocId = (int)$conn->insert_id;
    $insDoc->close();

    // 3. Delete materials for exam
    $cleanupRes = StudyMaterialService::deleteMaterialsForExam($conn, $examId);

    // 4. Verify DB and file state
    $chkMat = $conn->query("SELECT id FROM ai_documents WHERE id = {$matDocId}")->fetch_assoc();
    $fileGone = !file_exists($dummyFile);

    $cleanSuccess = ($cleanupRes['success'] && $cleanupRes['deleted_count'] === 1 && $chkMat === null && $fileGone);
    report("4. deleteMaterialsForExam removes associated material and file", $cleanSuccess, "Exam #{$examId} material Doc #{$matDocId} deleted");

    // Clean up test exam
    $conn->query("DELETE FROM exams WHERE id = {$examId}");
} catch (Throwable $e) {
    report("4. deleteMaterialsForExam test", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 5: Automatic cleanup handles multiple materials for a single exam
// --------------------------------------------------------------------------
try {
    // 1. Create exam
    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Multi Material Test Exam', 45, 20, 1)");
    $multiExamId = (int)$conn->insert_id;

    // 2. Create 3 associated documents
    $multiDocIds = [];
    $multiFiles = [];
    for ($i = 1; $i <= 3; $i++) {
        $fPath = $testDir . "/multi_{$multiExamId}_{$i}.txt";
        file_put_contents($fPath, "Multi doc {$i} for exam {$multiExamId}");
        $multiFiles[] = $fPath;
        $fname = basename($fPath);

        $mStmt = $conn->prepare("INSERT INTO ai_documents (filename, original_name, file_path, file_size, subject, topic, status, exam_id, total_chunks) VALUES (?, 'multi.txt', ?, 25, 'MultiSubj', 'MultiTopic', 'ingested', ?, 1)");
        $mStmt->bind_param("ssi", $fname, $fPath, $multiExamId);
        $mStmt->execute();
        $multiDocIds[] = (int)$conn->insert_id;
        $mStmt->close();
    }

    // 3. Execute cleanup
    $multiCleanup = StudyMaterialService::deleteMaterialsForExam($conn, $multiExamId);

    // 4. Verify all 3 are deleted
    $chkRemaining = $conn->query("SELECT COUNT(*) as cnt FROM ai_documents WHERE exam_id = {$multiExamId}")->fetch_assoc()['cnt'];
    $allFilesGone = true;
    foreach ($multiFiles as $f) {
        if (file_exists($f)) {
            $allFilesGone = false;
            @unlink($f);
        }
    }

    $allCleaned = ($multiCleanup['deleted_count'] === 3 && (int)$chkRemaining === 0 && $allFilesGone);
    report("5. Multiple materials for one exam all cleanly deleted", $allCleaned, "All 3 documents cleaned up");

    $conn->query("DELETE FROM exams WHERE id = {$multiExamId}");
} catch (Throwable $e) {
    report("5. Multiple materials test", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 6: Cross-exam isolation: materials belonging to other exams remain intact
// --------------------------------------------------------------------------
try {
    // Create Exam A and Exam B
    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Exam Alpha', 30, 10, 1)");
    $examA = (int)$conn->insert_id;
    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Exam Beta', 30, 10, 1)");
    $examB = (int)$conn->insert_id;

    // Attach Material A to Exam A
    $fileA = $testDir . "/mat_alpha_{$examA}.txt";
    file_put_contents($fileA, "Alpha material");
    $fNameA = basename($fileA);
    $conn->query("INSERT INTO ai_documents (filename, original_name, file_path, subject, topic, status, exam_id) VALUES ('{$fNameA}', 'alpha.txt', '{$fileA}', 'AlphaSubj', 'AlphaTopic', 'ingested', {$examA})");
    $docA = (int)$conn->insert_id;

    // Attach Material B to Exam B
    $fileB = $testDir . "/mat_beta_{$examB}.txt";
    file_put_contents($fileB, "Beta material");
    $fNameB = basename($fileB);
    $conn->query("INSERT INTO ai_documents (filename, original_name, file_path, subject, topic, status, exam_id) VALUES ('{$fNameB}', 'beta.txt', '{$fileB}', 'BetaSubj', 'BetaTopic', 'ingested', {$examB})");
    $docB = (int)$conn->insert_id;

    // Delete Exam A materials ONLY
    StudyMaterialService::deleteMaterialsForExam($conn, $examA);

    // Verify Doc A deleted, Doc B INTACT
    $chkA = $conn->query("SELECT id FROM ai_documents WHERE id = {$docA}")->fetch_assoc();
    $chkB = $conn->query("SELECT id, exam_id FROM ai_documents WHERE id = {$docB}")->fetch_assoc();

    $isolationSuccess = ($chkA === null && $chkB !== null && (int)$chkB['exam_id'] === $examB && file_exists($fileB));
    report("6. Cross-exam isolation strictly preserved", $isolationSuccess, "Doc B for Exam Beta remains completely untouched");

    // Clean up B
    StudyMaterialService::deleteMaterialsForExam($conn, $examB);
    $conn->query("DELETE FROM exams WHERE id IN ({$examA}, {$examB})");
} catch (Throwable $e) {
    report("6. Cross-exam isolation test", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 7: Idempotency and missing/invalid exam ID handling
// --------------------------------------------------------------------------
try {
    $resMissing = StudyMaterialService::deleteMaterialsForExam($conn, 999999);
    $resZero = StudyMaterialService::deleteMaterialsForExam($conn, 0);
    $resNegative = StudyMaterialService::deleteMaterialsForExam($conn, -10);

    $idempotentExam = (
        $resMissing['success'] && $resMissing['deleted_count'] === 0 &&
        $resZero['success'] && $resZero['deleted_count'] === 0 &&
        $resNegative['success'] && $resNegative['deleted_count'] === 0
    );
    report("7. Safe handling of non-existent and invalid exam IDs", $idempotentExam, "Returned safe success with 0 deleted");
} catch (Throwable $e) {
    report("7. Invalid exam ID handling", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 8: Vector store unreachable failure logging & retry queue
// --------------------------------------------------------------------------
try {
    // Create custom mock AiClient that simulates a 503 / network timeout
    $failingAiClient = new class extends AiClient {
        public function deleteDocument(int $docId): array {
            return [
                'status' => 'error',
                'message' => 'Vector store service temporarily offline (HTTP 503)',
                'code' => 503
            ];
        }
    };

    // Insert test document
    $fileFail = $testDir . "/fail_test_" . time() . ".txt";
    file_put_contents($fileFail, "Failure handling test");
    $fnameFail = basename($fileFail);
    $conn->query("INSERT INTO ai_documents (filename, original_name, file_path, subject, topic, status, exam_id) VALUES ('{$fnameFail}', 'fail.txt', '{$fileFail}', 'FailSubj', 'FailTopic', 'ingested', 8888)");
    $failDocId = (int)$conn->insert_id;

    // Delete with failing AI client
    $failDelRes = StudyMaterialService::deleteMaterial($conn, $failDocId, $failingAiClient);

    // Verify:
    // 1. vector_deleted is FALSE (does not falsely claim deletion)
    // 2. vector_error is populated
    // 3. queued_for_retry is TRUE
    // 4. Record is queued in ai_vector_cleanup_queue
    // 5. DB record in ai_documents was still removed to avoid blocking FK constraints
    $queueRow = $conn->query("SELECT * FROM ai_vector_cleanup_queue WHERE document_id = {$failDocId} AND status = 'pending'")->fetch_assoc();
    $docRemoved = ($conn->query("SELECT id FROM ai_documents WHERE id = {$failDocId}")->fetch_assoc() === null);

    $handledCorrectly = (
        $failDelRes['success'] &&
        !$failDelRes['vector_deleted'] &&
        !empty($failDelRes['vector_error']) &&
        $failDelRes['queued_for_retry'] &&
        $queueRow !== null &&
        $docRemoved
    );

    report("8. Vector store failure handled safely, not falsely reported, and queued for retry", $handledCorrectly, "Queued Doc #{$failDocId} with error");

    // Test processPendingVectorCleanups with a recovering client
    $recoveringAiClient = new class extends AiClient {
        public function deleteDocument(int $docId): array {
            return [
                'status' => 'success',
                'document_id' => $docId,
                'deleted_chunks' => 1
            ];
        }
    };
    $retryProcess = StudyMaterialService::processPendingVectorCleanups($conn, $recoveringAiClient);
    $queueCompleted = $conn->query("SELECT status FROM ai_vector_cleanup_queue WHERE document_id = {$failDocId}")->fetch_assoc();

    $retryWorked = ($retryProcess['succeeded'] >= 1 && ($queueCompleted['status'] ?? '') === 'completed');
    report("8b. Retry processor processes pending queue items upon recovery", $retryWorked, "Pending cleanup marked completed");

    // Cleanup queue item
    $conn->query("DELETE FROM ai_vector_cleanup_queue WHERE document_id = {$failDocId}");
    if (file_exists($fileFail)) @unlink($fileFail);
} catch (Throwable $e) {
    report("8. Vector store failure handling", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 9: End-to-end exam deletion workflow via admin/exams.php
// --------------------------------------------------------------------------
try {
    // Create an exam with question, student answer, and study material
    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('E2E Deletion Exam', 20, 5, 1)");
    $e2eExamId = (int)$conn->insert_id;

    $conn->query("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES ({$e2eExamId}, 'Sample Q', 'A', 'B', 'C', 'D', 'A')");
    $qId = (int)$conn->insert_id;

    $e2eFile = $testDir . "/e2e_{$e2eExamId}.txt";
    file_put_contents($e2eFile, "E2E Course Material for Exam {$e2eExamId}");
    $e2eFname = basename($e2eFile);
    $stmtE2e = $conn->prepare("INSERT INTO ai_documents (filename, original_name, file_path, subject, topic, status, exam_id) VALUES (?, 'e2e.txt', ?, 'E2ESubj', 'E2ETopic', 'ingested', ?)");
    $stmtE2e->bind_param("ssi", $e2eFname, $e2eFile, $e2eExamId);
    $stmtE2e->execute();
    $e2eDocId = (int)$conn->insert_id;
    $stmtE2e->close();

    // Simulate POST request to admin/exams.php
    $csrfToken = bin2hex(random_bytes(32));

    $res = runScriptIsolated(
        'admin/exams.php',
        ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfToken],
        ['delete_exam' => '1', 'exam_id' => $e2eExamId, 'csrf_token' => $csrfToken]
    );

    // Verify Exam AND Course Material were both automatically cleaned up
    $chkExam = $conn->query("SELECT id FROM exams WHERE id = {$e2eExamId}")->fetch_assoc();
    $chkDoc = $conn->query("SELECT id FROM ai_documents WHERE id = {$e2eDocId}")->fetch_assoc();
    $fileDeleted = !file_exists($e2eFile);

    $e2eSuccess = ($chkExam === null && $chkDoc === null && $fileDeleted);
    report("9. E2E admin/exams.php deletion automatically cleans up exam and study material", $e2eSuccess, "Exam and Doc removed");
} catch (Throwable $e) {
    report("9. E2E exam deletion test", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 10: CSRF protection on exam deletion preserves materials
// --------------------------------------------------------------------------
try {
    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('CSRF Protected Exam', 20, 5, 1)");
    $csrfExamId = (int)$conn->insert_id;

    $csrfFile = $testDir . "/csrf_{$csrfExamId}.txt";
    file_put_contents($csrfFile, "CSRF Protected Material");
    $csrfFname = basename($csrfFile);
    $stmtCsrf = $conn->prepare("INSERT INTO ai_documents (filename, original_name, file_path, subject, topic, status, exam_id) VALUES (?, 'csrf.txt', ?, 'CsrfSubj', 'CsrfTopic', 'ingested', ?)");
    $stmtCsrf->bind_param("ssi", $csrfFname, $csrfFile, $csrfExamId);
    $stmtCsrf->execute();
    $csrfDocId = (int)$conn->insert_id;
    $stmtCsrf->close();

    $realCsrfToken = bin2hex(random_bytes(32));
    $badCsrfToken = "tampered_csrf_token_12345";

    $res = runScriptIsolated(
        'admin/exams.php',
        ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $realCsrfToken],
        ['delete_exam' => '1', 'exam_id' => $csrfExamId, 'csrf_token' => $badCsrfToken]
    );

    // Verify Exam AND Material remain preserved
    $chkExam = $conn->query("SELECT id FROM exams WHERE id = {$csrfExamId}")->fetch_assoc();
    $chkDoc = $conn->query("SELECT id FROM ai_documents WHERE id = {$csrfDocId}")->fetch_assoc();

    $csrfProtected = ($chkExam !== null && $chkDoc !== null);
    report("10. CSRF protection strictly prevents unauthorized exam and material deletion", $csrfProtected, "Exam & material preserved");

    // Clean up test exam and material
    StudyMaterialService::deleteMaterialsForExam($conn, $csrfExamId);
    $conn->query("DELETE FROM exams WHERE id = {$csrfExamId}");
    if (file_exists($csrfFile)) @unlink($csrfFile);
} catch (Throwable $e) {
    report("10. CSRF test", false, $e->getMessage());
}

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
} else {
    exit(0);
}
