<?php
/**
 * Regression Test Suite: Exam Deletion Persistence & Error Validation
 * 
 * Verifies:
 * 1. Deleting an exam removes it from Manage Exams after refreshing the page.
 * 2. The deleted exam does not reappear after navigating away and returning.
 * 3. All Results does not show the deleted exam as an active exam.
 * 4. Running test suites / restarting the application does not recreate deleted exams.
 * 5. Failed deletions display a genuine error rather than a false success.
 * 6. Python and Java exams, their questions, and their results remain intact.
 * 7. Historical student results are preserved according to the data-retention policy.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/study_material_service.php';

echo "====================================================\n";
echo " Exam Deletion Persistence & Data Retention Tests  \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

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
// TEST 1: Initial Baseline - English does not exist, Python & Java intact
// --------------------------------------------------------------------------
try {
    $resPy = $conn->query("SELECT id, title FROM exams WHERE title = 'Python'")->fetch_assoc();
    $resJava = $conn->query("SELECT id, title FROM exams WHERE title = 'Java'")->fetch_assoc();
    $resEng = $conn->query("SELECT id, title FROM exams WHERE title = 'English' OR id = 126")->fetch_assoc();

    $baselineOk = ($resPy !== null && $resJava !== null && $resEng === null);
    report("1. Baseline state: Python and Java intact, English does not exist", $baselineOk, "Py: {$resPy['id']}, Java: {$resJava['id']}");
} catch (Throwable $e) {
    report("1. Baseline state", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 2: Create a test exam with historical student result & study material
// --------------------------------------------------------------------------
$testExamId = 0;
$testResultId = 0;
$testDocId = 0;

try {
    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Test Deletion Persistence Exam', 25, 10, 1)");
    $testExamId = (int)$conn->insert_id;

    // Add a question
    $conn->query("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES ({$testExamId}, 'Sample Q?', 'A', 'B', 'C', 'D', 'A')");

    // Add a historical student result
    $conn->query("INSERT INTO results (user_id, exam_id, score, tab_switch_count) VALUES (1, {$testExamId}, 8, 0)");
    $testResultId = (int)$conn->insert_id;

    // Add an associated study material
    $testFilePath = __DIR__ . "/test_persist_{$testExamId}.txt";
    file_put_contents($testFilePath, "Persistent test notes");
    $testFname = basename($testFilePath);
    $stmtDoc = $conn->prepare("INSERT INTO ai_documents (filename, original_name, file_path, subject, topic, status, exam_id) VALUES (?, 'notes.txt', ?, 'PersistSubj', 'Topic1', 'ingested', ?)");
    $stmtDoc->bind_param("ssi", $testFname, $testFilePath, $testExamId);
    $stmtDoc->execute();
    $testDocId = (int)$conn->insert_id;
    $stmtDoc->close();

    // Verify it renders in Manage Exams and All Results
    $manageBefore = runScriptIsolated('admin/exams.php', ['role' => 'admin', 'user_id' => 1]);
    $resultsBefore = runScriptIsolated('admin/results.php', ['role' => 'admin', 'user_id' => 1]);

    $inManageBefore = str_contains($manageBefore['stdout'], 'Test Deletion Persistence Exam');
    $inResultsBefore = str_contains($resultsBefore['stdout'], 'Test Deletion Persistence Exam');

    report("2. Test exam created and visible in Manage Exams and All Results", ($inManageBefore && $inResultsBefore), "Exam #{$testExamId} active");
} catch (Throwable $e) {
    report("2. Setup test exam", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 3: Delete exam via admin/exams.php and verify persistence
// --------------------------------------------------------------------------
try {
    $csrfToken = bin2hex(random_bytes(32));
    $delRes = runScriptIsolated(
        'admin/exams.php',
        ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfToken],
        ['delete_exam' => '1', 'exam_id' => $testExamId, 'csrf_token' => $csrfToken]
    );

    // 3a. Verify exam row is gone from exams table
    $chkExam = $conn->query("SELECT id FROM exams WHERE id = {$testExamId}")->fetch_assoc();
    $examDeleted = ($chkExam === null);

    // 3b. Verify refreshing Manage Exams page does NOT show the exam
    $manageRefresh = runScriptIsolated('admin/exams.php', ['role' => 'admin', 'user_id' => 1], [], ['msg' => 'deleted']);
    $notInManageOnRefresh = !str_contains($manageRefresh['stdout'], 'Test Deletion Persistence Exam');
    $showsSuccess = str_contains($manageRefresh['stdout'], 'Exam deleted successfully');

    // 3c. Verify navigating away (e.g. to dashboard) and returning does NOT bring it back
    $dashView = runScriptIsolated('admin/dashboard.php', ['role' => 'admin', 'user_id' => 1]);
    $manageReturn = runScriptIsolated('admin/exams.php', ['role' => 'admin', 'user_id' => 1]);
    $notInManageOnReturn = !str_contains($manageReturn['stdout'], 'Test Deletion Persistence Exam');

    // 3d. Verify All Results does not show the deleted exam as an active exam
    $resultsAfter = runScriptIsolated('admin/results.php', ['role' => 'admin', 'user_id' => 1]);
    $notInResults = !str_contains($resultsAfter['stdout'], 'Test Deletion Persistence Exam');

    $allPassedPersistent = ($examDeleted && $notInManageOnRefresh && $showsSuccess && $notInManageOnReturn && $notInResults);
    report("3. Deletion persists on refresh, navigation, and in All Results", $allPassedPersistent, "Exam completely removed from active listings");
} catch (Throwable $e) {
    report("3. Deletion persistence", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 4: Historical student results are preserved according to data-retention policy
// --------------------------------------------------------------------------
try {
    $resRecord = $conn->query("SELECT * FROM results WHERE id = {$testResultId}")->fetch_assoc();
    $resultPreserved = ($resRecord !== null);
    $examIdNullified = ($resRecord !== null && $resRecord['exam_id'] === null);
    $scorePreserved = ($resRecord !== null && (int)$resRecord['score'] === 8);

    report("4. Historical student result is preserved with exam_id nullified", ($resultPreserved && $examIdNullified && $scorePreserved), "Result #{$testResultId} score={$resRecord['score']}");

    // Clean up the test result
    $conn->query("DELETE FROM results WHERE id = {$testResultId}");
} catch (Throwable $e) {
    report("4. Historical result retention", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 5: Failed deletions display genuine error rather than false success
// --------------------------------------------------------------------------
try {
    $csrfToken = bin2hex(random_bytes(32));

    // 5a. Non-existent exam ID (e.g. 999999)
    $failNonExistent = runScriptIsolated(
        'admin/exams.php',
        ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfToken],
        ['delete_exam' => '1', 'exam_id' => 999999, 'csrf_token' => $csrfToken]
    );
    $hasNotFoundError = str_contains($failNonExistent['stdout'], 'Exam not found or already deleted');
    $noFalseSuccess1 = !str_contains($failNonExistent['stdout'], 'Exam deleted successfully');

    // 5b. Invalid exam ID (e.g. 0)
    $failInvalid = runScriptIsolated(
        'admin/exams.php',
        ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfToken],
        ['delete_exam' => '1', 'exam_id' => 0, 'csrf_token' => $csrfToken]
    );
    $hasInvalidError = str_contains($failInvalid['stdout'], 'Invalid exam ID specified');
    $noFalseSuccess2 = !str_contains($failInvalid['stdout'], 'Exam deleted successfully');

    // 5c. Invalid CSRF token
    $failCsrf = runScriptIsolated(
        'admin/exams.php',
        ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfToken],
        ['delete_exam' => '1', 'exam_id' => 330, 'csrf_token' => 'tampered_token']
    );
    $hasCsrfError = str_contains($failCsrf['stdout'], 'Invalid CSRF security token');

    $errorsGenuine = ($hasNotFoundError && $noFalseSuccess1 && $hasInvalidError && $noFalseSuccess2 && $hasCsrfError);
    report("5. Failed deletions display genuine errors and never report false success", $errorsGenuine, "All 3 failure paths verified");
} catch (Throwable $e) {
    report("5. Failed deletion error handling", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 6: Running test suite does not resurrect English exam
// --------------------------------------------------------------------------
try {
    // Run test_study_material_generation.php
    $suiteOutput = shell_exec('C:\\xampp\\php\\php.exe ' . escapeshellarg(__DIR__ . '/test_study_material_generation.php') . ' 2>&1');
    $suitePassed = str_contains($suiteOutput, '11 Passed, 0 Failed');

    // Check DB state immediately after test suite run
    $chkEnglishAfter = $conn->query("SELECT id, title FROM exams WHERE title = 'English' OR id = 126")->fetch_assoc();
    $noEnglishExam = ($chkEnglishAfter === null);

    // Check Manage Exams markup
    $manageAfterSuite = runScriptIsolated('admin/exams.php', ['role' => 'admin', 'user_id' => 1]);
    $notInManageAfterSuite = !str_contains($manageAfterSuite['stdout'], 'English');

    // Check All Results markup
    $resultsAfterSuite = runScriptIsolated('admin/results.php', ['role' => 'admin', 'user_id' => 1]);
    $notInResultsAfterSuite = !str_contains($resultsAfterSuite['stdout'], 'English');

    $noResurrection = ($suitePassed && $noEnglishExam && $notInManageAfterSuite && $notInResultsAfterSuite);
    report("6. Test suite runs and passes without resurrecting English exam", $noResurrection, "English remains 100% absent");
} catch (Throwable $e) {
    report("6. Anti-resurrection test", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 7: Python and Java exams remain intact with all questions and data
// --------------------------------------------------------------------------
try {
    $py = $conn->query("SELECT id, title FROM exams WHERE id = 330 AND title = 'Python'")->fetch_assoc();
    $java = $conn->query("SELECT id, title FROM exams WHERE id = 331 AND title = 'Java'")->fetch_assoc();
    $pyQuestions = (int)$conn->query("SELECT COUNT(*) as c FROM questions WHERE exam_id = 330")->fetch_assoc()['c'];
    $pyAiQuestions = (int)$conn->query("SELECT COUNT(*) as c FROM ai_generated_questions WHERE exam_id = 330 AND status = 'approved'")->fetch_assoc()['c'];

    $pyJavaIntact = ($py !== null && $java !== null && $pyQuestions === 25 && $pyAiQuestions === 25);
    report("7. Python and Java exams, questions, and data remain completely intact", $pyJavaIntact, "Python #330 ({$pyQuestions} Qs), Java #331");
} catch (Throwable $e) {
    report("7. Python & Java integrity", false, $e->getMessage());
}

// Clean up any test file on disk
if (isset($testFilePath) && file_exists($testFilePath)) {
    @unlink($testFilePath);
}

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
} else {
    exit(0);
}
