<?php

require_once __DIR__ . '/../config/db.php';

echo "====================================================\n";
echo "  AI Difficulty Analytics: Subject Filter Test Suite\n";
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

function runAnalyticsIsolated(array $get = [], array $session = ['role' => 'admin', 'user_id' => 1]) {
    $adminDir = realpath(__DIR__ . '/../admin');
    $scriptFullPath = $adminDir . DIRECTORY_SEPARATOR . 'ai_difficulty_analytics.php';
    if (!isset($session['csrf_token'])) {
        $session['csrf_token'] = 'test_csrf_token_analytics';
    }

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
// Setup: Ensure we have both Python and Java questions in DB
// -------------------------------------------------------------
$javaExamRes = $conn->query("SELECT id FROM exams WHERE title LIKE '%Java%' LIMIT 1")->fetch_assoc();
$javaExamId = $javaExamRes ? (int)$javaExamRes['id'] : 0;
if ($javaExamId === 0) {
    // Insert temporary Java exam
    $conn->query("INSERT INTO exams (title, description, duration_minutes, pass_percentage, max_attempts) VALUES ('Java Programming', 'Test Exam', 30, 40, 3)");
    $javaExamId = $conn->insert_id;
    $createdTempExam = true;
} else {
    $createdTempExam = false;
}

$tempJavaQIds = [];
$chkJavaQ = $conn->query("SELECT id FROM questions WHERE subject = 'Java' AND exam_id = $javaExamId");
if ($chkJavaQ->num_rows === 0) {
    // Insert two temporary Java questions
    $stmtIns = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option, subject, topic, difficulty) VALUES (?, ?, 'A', 'B', 'C', 'D', 'A', 'Java', 'OOP', 'medium')");
    $q1Title = "What is polymorphism in Java?";
    $stmtIns->bind_param("is", $javaExamId, $q1Title);
    $stmtIns->execute();
    $tempJavaQIds[] = $conn->insert_id;

    $q2Title = "What is inheritance in Java?";
    $stmtIns->bind_param("is", $javaExamId, $q2Title);
    $stmtIns->execute();
    $tempJavaQIds[] = $conn->insert_id;
    $stmtIns->close();
}

try {
    // -------------------------------------------------------------
    // Test 1: Initial page load displays all subjects
    // -------------------------------------------------------------
    $totalBankRes = $conn->query("SELECT COUNT(DISTINCT q.id) AS c FROM questions q JOIN exams e ON q.exam_id = e.id")->fetch_assoc();
    $dbTotalQuestions = (int)$totalBankRes['c'];

    $resAll = runAnalyticsIsolated();
    preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resAll['output'], $mAll);
    $renderedAllCount = isset($mAll[1]) ? (int)$mAll[1] : -1;

    $hasSubjectFilterLabel = (strpos($resAll['output'], 'Filter by Subject:') !== false);
    $hasAllSubjectsOption = (strpos($resAll['output'], '<option value="" selected>All Subjects</option>') !== false);
    $hasPythonOption = (strpos($resAll['output'], '>Python</option>') !== false);
    $hasJavaOption = (strpos($resAll['output'], '>Java</option>') !== false);

    report(
        "1. Initial page displays all subjects and matches dataset count",
        ($resAll['code'] === 0 && $renderedAllCount === $dbTotalQuestions && $hasSubjectFilterLabel && $hasAllSubjectsOption && $hasPythonOption && $hasJavaOption),
        "DB Total: {$dbTotalQuestions}, Rendered: {$renderedAllCount}, Options verified"
    );

    // -------------------------------------------------------------
    // Test 2: Selecting Python displays only Python questions
    // -------------------------------------------------------------
    $pyRes = $conn->query("SELECT COUNT(DISTINCT q.id) AS c FROM questions q JOIN exams e ON q.exam_id = e.id WHERE q.subject = 'Python'")->fetch_assoc();
    $dbPyCount = (int)$pyRes['c'];

    $resPy = runAnalyticsIsolated(['subject' => 'Python']);
    preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resPy['output'], $mPy);
    $renderedPyCount = isset($mPy[1]) ? (int)$mPy[1] : -1;

    $pySelected = (strpos($resPy['output'], 'value="Python" selected') !== false);
    $hasPyBadge = (strpos($resPy['output'], "Filtered by: Python ({$renderedPyCount} of {$dbTotalQuestions} total)") !== false);
    $hasResetBtn = (strpos($resPy['output'], 'class="btn-reset-filter"') !== false && strpos($resPy['output'], '✕ Reset') !== false);

    // Check that Java questions are NOT present in the table
    $containsJavaInPy = (strpos($resPy['output'], 'What is polymorphism in Java?') !== false);

    report(
        "2. Selecting Python displays only Python questions with correct count & badge",
        ($renderedPyCount === $dbPyCount && $pySelected && $hasPyBadge && $hasResetBtn && !$containsJavaInPy),
        "DB Python: {$dbPyCount}, Rendered: {$renderedPyCount}"
    );

    // -------------------------------------------------------------
    // Test 3: Selecting Java displays only Java questions
    // -------------------------------------------------------------
    $javaRes = $conn->query("SELECT COUNT(DISTINCT q.id) AS c FROM questions q JOIN exams e ON q.exam_id = e.id WHERE q.subject = 'Java'")->fetch_assoc();
    $dbJavaCount = (int)$javaRes['c'];

    $resJava = runAnalyticsIsolated(['subject' => 'Java']);
    preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resJava['output'], $mJava);
    $renderedJavaCount = isset($mJava[1]) ? (int)$mJava[1] : -1;

    $javaSelected = (strpos($resJava['output'], 'value="Java" selected') !== false);
    $hasJavaBadge = (strpos($resJava['output'], "Filtered by: Java ({$renderedJavaCount} of {$dbTotalQuestions} total)") !== false);
    $containsJavaQuestion = (strpos($resJava['output'], 'What is polymorphism in Java?') !== false);

    report(
        "3. Selecting Java displays only Java questions with correct count & badge",
        ($renderedJavaCount === $dbJavaCount && $javaSelected && $hasJavaBadge && $containsJavaQuestion),
        "DB Java: {$dbJavaCount}, Rendered: {$renderedJavaCount}"
    );

    // -------------------------------------------------------------
    // Test 4: Switching between subjects updates the table correctly
    // -------------------------------------------------------------
    $switchOk = ($renderedPyCount !== $renderedJavaCount || $dbPyCount === $dbJavaCount)
        && (strpos($resPy['output'], 'What is polymorphism in Java?') === false)
        && (strpos($resJava['output'], 'What is polymorphism in Java?') !== false);

    report(
        "4. Switching between subjects updates table content cleanly",
        $switchOk,
        "Python view has no Java questions, Java view contains Java questions"
    );

    // -------------------------------------------------------------
    // Test 5: Selecting All Subjects restores the complete list
    // -------------------------------------------------------------
    $resRestored = runAnalyticsIsolated(['subject' => '']);
    preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resRestored['output'], $mRestored);
    $renderedRestoredCount = isset($mRestored[1]) ? (int)$mRestored[1] : -1;
    $noFilterBadge = (strpos($resRestored['output'], 'Filtered by:') === false);

    $resRestoredAllWord = runAnalyticsIsolated(['subject' => 'all']);
    preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resRestoredAllWord['output'], $mRestoredAllWord);
    $renderedRestoredAllWordCount = isset($mRestoredAllWord[1]) ? (int)$mRestoredAllWord[1] : -1;

    report(
        "5. Selecting All Subjects (empty or 'all') restores complete list",
        ($renderedRestoredCount === $dbTotalQuestions && $renderedRestoredAllWordCount === $dbTotalQuestions && $noFilterBadge),
        "Restored count: {$renderedRestoredCount} matches total {$dbTotalQuestions}"
    );

    // -------------------------------------------------------------
    // Test 6: Unknown or invalid subject does not expose unrelated data or SQL error
    // -------------------------------------------------------------
    $resInvalid = runAnalyticsIsolated(['subject' => 'NonExistentSubjectXYZ\' OR 1=1 --']);
    preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resInvalid['output'], $mInvalid);
    $renderedInvalidCount = isset($mInvalid[1]) ? (int)$mInvalid[1] : -1;

    $hasEmptyMessage = (strpos($resInvalid['output'], 'No questions found for the selected subject.') !== false);
    $noSqlError = (strpos($resInvalid['output'], 'Fatal error') === false && strpos($resInvalid['output'], 'mysqli_sql_exception') === false);

    report(
        "6. Unknown/invalid subject handles safely with 0 results and no SQL errors",
        ($resInvalid['code'] === 0 && $renderedInvalidCount === 0 && $hasEmptyMessage && $noSqlError),
        "Rendered: {$renderedInvalidCount}, Empty message displayed, No SQL errors"
    );

    // -------------------------------------------------------------
    // Test 7: Serial numbers restart at 1 after filtering
    // -------------------------------------------------------------
    // Check that the first row in Python filter has serial number 1
    $pyHasSerial1 = preg_match('/<tbody>\s*<tr>\s*<td>1<\/td>/is', $resPy['output']);
    // Check that Java filter has serial number 1
    $javaHasSerial1 = preg_match('/<tbody>\s*<tr>\s*<td>1<\/td>/is', $resJava['output']);

    report(
        "7. Serial numbers restart at 1 for filtered results",
        ($pyHasSerial1 && $javaHasSerial1),
        "Python starts at S.No 1, Java starts at S.No 1"
    );

    // -------------------------------------------------------------
    // Test 8: Existing analytics and cold-start protection behavior remain unchanged
    // -------------------------------------------------------------
    $hasColdStartBanner = (strpos($resPy['output'], 'Cold-Start Protection Rule:') !== false);
    $hasAttemptsColumn = (strpos($resPy['output'], 'Attempts / Accuracy') !== false);
    $hasPredictionColumn = (strpos($resPy['output'], 'ML Prediction') !== false);
    $hasRecommendationColumn = (strpos($resPy['output'], 'Recommendation') !== false);

    report(
        "8. Cold-start protection banner and analytics columns remain intact",
        ($hasColdStartBanner && $hasAttemptsColumn && $hasPredictionColumn && $hasRecommendationColumn),
        "Cold-start banner and all analytics columns present"
    );

} finally {
    // Clean up temporary Java questions
    if (!empty($tempJavaQIds)) {
        $idList = implode(',', array_map('intval', $tempJavaQIds));
        $conn->query("DELETE FROM questions WHERE id IN ($idList)");
    }
    if ($createdTempExam && $javaExamId > 0) {
        $conn->query("DELETE FROM exams WHERE id = $javaExamId");
    }
}

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
}
