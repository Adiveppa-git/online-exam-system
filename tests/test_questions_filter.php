<?php

require_once __DIR__ . '/../config/db.php';

echo "====================================================\n";
echo "  Manage Questions: Count & Filter Test Suite       \n";
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

function runQuestionsIsolated(array $get = [], array $session = ['role' => 'admin', 'user_id' => 1]) {
    $scriptFullPath = realpath(__DIR__ . '/../admin/questions.php');
    if (!isset($session['csrf_token'])) {
        $session['csrf_token'] = 'test_csrf_token_12345';
    }

    $payload = [
        'script' => $scriptFullPath,
        'session' => $session,
        'post' => [],
        'get' => $get,
        'headers' => []
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
// Test 1: Overall question count with no subject filter
// -------------------------------------------------------------
$dbTotal = (int)$conn->query("SELECT COUNT(*) AS c FROM questions")->fetch_assoc()['c'];
$res1 = runQuestionsIsolated(['mode' => 'manage']);

$hasTotalSpan = preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $res1['output'], $m1);
$renderedTotal = $hasTotalSpan ? (int)$m1[1] : -1;
report("1. Overall count matches database with no filter",
    ($res1['code'] === 0 && $renderedTotal === $dbTotal),
    "DB count: {$dbTotal}, Rendered: {$renderedTotal}"
);

// -------------------------------------------------------------
// Test 2: Subject dropdown is dynamically populated with distinct subjects
// -------------------------------------------------------------
$subjectsRes = $conn->query("SELECT DISTINCT subject FROM questions WHERE subject IS NOT NULL AND TRIM(subject) != '' ORDER BY subject ASC");
$expectedSubjects = [];
while ($row = $subjectsRes->fetch_assoc()) {
    $expectedSubjects[] = trim($row['subject']);
}

$allExpectedInDropdown = true;
foreach ($expectedSubjects as $subj) {
    if (strpos($res1['output'], '>' . htmlspecialchars($subj) . '</option>') === false) {
        $allExpectedInDropdown = false;
        break;
    }
}
$hasAllSubjectsOption = (strpos($res1['output'], '<option value="" selected>All Subjects</option>') !== false
    || strpos($res1['output'], '<option value=""  selected>All Subjects</option>') !== false);

report("2. Dropdown populated dynamically with distinct subjects",
    ($allExpectedInDropdown && $hasAllSubjectsOption),
    "Expected subjects: " . implode(', ', $expectedSubjects)
);

// -------------------------------------------------------------
// Test 3: Filter by subject 'English'
// -------------------------------------------------------------
$dbEnglish = (int)$conn->query("SELECT COUNT(*) AS c FROM questions WHERE subject = 'English'")->fetch_assoc()['c'];
$resEng = runQuestionsIsolated(['mode' => 'manage', 'subject' => 'English']);

$hasEngSpan = preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resEng['output'], $mEng);
$renderedEng = $hasEngSpan ? (int)$mEng[1] : -1;
$engOptionSelected = (strpos($resEng['output'], 'value="English" selected') !== false);
$hasResetLink = (strpos($resEng['output'], 'href="questions.php?mode=manage"') !== false && strpos($resEng['output'], 'Reset') !== false);

report("3. Filter by 'English' displays exact matching count and select state",
    ($renderedEng === $dbEnglish && $engOptionSelected && $hasResetLink),
    "DB English: {$dbEnglish}, Rendered: {$renderedEng}"
);

// -------------------------------------------------------------
// Test 4: Filter by subject 'Maths'
// -------------------------------------------------------------
$dbMaths = (int)$conn->query("SELECT COUNT(*) AS c FROM questions WHERE subject = 'Maths'")->fetch_assoc()['c'];
$resMath = runQuestionsIsolated(['mode' => 'manage', 'subject' => 'Maths']);

$hasMathSpan = preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resMath['output'], $mMath);
$renderedMath = $hasMathSpan ? (int)$mMath[1] : -1;
$mathOptionSelected = (strpos($resMath['output'], 'value="Maths" selected') !== false);

report("4. Filter by 'Maths' displays exact matching count and select state",
    ($renderedMath === $dbMaths && $mathOptionSelected),
    "DB Maths: {$dbMaths}, Rendered: {$renderedMath}"
);

// -------------------------------------------------------------
// Test 5: Pagination preserves subject filter
// -------------------------------------------------------------
$resEngP2 = runQuestionsIsolated(['mode' => 'manage', 'subject' => 'English', 'page' => 2]);
$hasEngP2Span = preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resEngP2['output'], $mEngP2);
$renderedEngP2 = $hasEngP2Span ? (int)$mEngP2[1] : -1;
$serial11Present = (strpos($resEngP2['output'], '>11</td>') !== false);
$paginationPreservesSubject = (strpos($resEngP2['output'], 'subject=English') !== false);

report("5. Pagination preserves selected subject across page links and serial numbers",
    ($renderedEngP2 === $dbEnglish && $serial11Present && $paginationPreservesSubject),
    "Page 2 starts at SL #11, preserves subject=English"
);

// -------------------------------------------------------------
// Test 6: Reset / All Subjects filter returns to full question bank
// -------------------------------------------------------------
$resReset = runQuestionsIsolated(['mode' => 'manage', 'subject' => '']);
$hasResetSpan = preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resReset['output'], $mReset);
$renderedReset = $hasResetSpan ? (int)$mReset[1] : -1;

report("6. All Subjects reset restores full bank count",
    ($renderedReset === $dbTotal),
    "Full bank count restored: {$renderedReset}"
);

// -------------------------------------------------------------
// Test 7: Nonexistent subject handles zero matching questions gracefully
// -------------------------------------------------------------
$resZero = runQuestionsIsolated(['mode' => 'manage', 'subject' => 'QuantumMechanicsNonExistent']);
$hasZeroSpan = preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resZero['output'], $mZero);
$renderedZero = $hasZeroSpan ? (int)$mZero[1] : -1;
$hasEmptyNotice = (strpos($resZero['output'], 'No questions found for subject') !== false);

report("7. Non-matching subject handled gracefully with 0 count and user message",
    ($renderedZero === 0 && $hasEmptyNotice),
    "Count: {$renderedZero}, Empty state message displayed"
);

// -------------------------------------------------------------
// Test 8: Dynamic Update: Add test question, verify count increases, delete, verify count decreases
// -------------------------------------------------------------
$initialTotal = (int)$conn->query("SELECT COUNT(*) AS c FROM questions")->fetch_assoc()['c'];
$initialSubj = (int)$conn->query("SELECT COUNT(*) AS c FROM questions WHERE subject = 'TestSubjectSpecial'")->fetch_assoc()['c'];

// Insert test question
$insStmt = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option, subject) VALUES (125, 'Temporary Dynamic Verification Question?', 'Opt A', 'Opt B', 'Opt C', 'Opt D', 'A', 'TestSubjectSpecial')");
$insStmt->execute();
$tempQId = $conn->insert_id;
$insStmt->close();

$resAdd = runQuestionsIsolated(['mode' => 'manage']);
preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resAdd['output'], $mAdd);
$renderedAfterAdd = isset($mAdd[1]) ? (int)$mAdd[1] : -1;
$subjInDropdown = (strpos($resAdd['output'], 'TestSubjectSpecial') !== false);

$resAddFilter = runQuestionsIsolated(['mode' => 'manage', 'subject' => 'TestSubjectSpecial']);
preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resAddFilter['output'], $mAddFilter);
$renderedSubjAfterAdd = isset($mAddFilter[1]) ? (int)$mAddFilter[1] : -1;

// Delete test question
$delStmt = $conn->prepare("DELETE FROM questions WHERE id = ?");
$delStmt->bind_param("i", $tempQId);
$delStmt->execute();
$delStmt->close();

$resDel = runQuestionsIsolated(['mode' => 'manage']);
preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resDel['output'], $mDel);
$renderedAfterDel = isset($mDel[1]) ? (int)$mDel[1] : -1;
$subjRemovedFromDropdown = (strpos($resDel['output'], 'TestSubjectSpecial') === false);

$dynamicTestPassed = ($renderedAfterAdd === $initialTotal + 1)
    && ($renderedSubjAfterAdd === 1)
    && ($subjInDropdown)
    && ($renderedAfterDel === $initialTotal)
    && ($subjRemovedFromDropdown);

report("8. Adding test question dynamically increments count & dropdown; deletion decrements count",
    $dynamicTestPassed,
    "Initial: {$initialTotal} -> After Add: {$renderedAfterAdd} -> After Del: {$renderedAfterDel}"
);

// -------------------------------------------------------------
// Test 9: Active AI questions are counted; unapproved/pending are NOT counted
// -------------------------------------------------------------
$activeQuestionsInBank = (int)$conn->query("SELECT COUNT(*) AS c FROM questions")->fetch_assoc()['c'];
$pendingAiCount = (int)$conn->query("SELECT COUNT(*) AS c FROM ai_generated_questions WHERE status = 'pending'")->fetch_assoc()['c'];
$rejectedAiCount = (int)$conn->query("SELECT COUNT(*) AS c FROM ai_generated_questions WHERE status = 'rejected'")->fetch_assoc()['c'];

$resBank = runQuestionsIsolated(['mode' => 'manage']);
preg_match('/Total Questions:\s*<span>(\d+)<\/span>/i', $resBank['output'], $mBank);
$renderedBank = isset($mBank[1]) ? (int)$mBank[1] : -1;

$isolationPassed = ($renderedBank === $activeQuestionsInBank);
report("9. Count strictly reflects active question bank (pending/rejected AI questions are excluded)",
    $isolationPassed,
    "Bank: {$renderedBank}, Pending AI: {$pendingAiCount}, Rejected AI: {$rejectedAiCount}"
);

// -------------------------------------------------------------
// Test 10: Preserving existing UI elements (blue table header, edit/delete buttons)
// -------------------------------------------------------------
$resUI = runQuestionsIsolated(['mode' => 'manage']);
$hasBlueHeader = (strpos($resUI['output'], 'manage-q-th') !== false && strpos($resUI['output'], '#1976D2') !== false);
$hasEditButton = (strpos($resUI['output'], 'class="edit-link"') !== false);
$hasDeleteButton = (strpos($resUI['output'], 'class="delete-link"') !== false);
$hasActionBtns = (strpos($resUI['output'], 'class="action-btns"') !== false);

report("10. Preserves existing blue table header, Edit/Delete action buttons, and table structure",
    ($hasBlueHeader && $hasEditButton && $hasDeleteButton && $hasActionBtns),
    "Blue header, edit & delete buttons all verified"
);

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
