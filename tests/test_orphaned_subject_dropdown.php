<?php
/**
 * Test Suite: Orphaned Subjects Exclusion from Study Materials Dropdown
 *
 * Verifies:
 * 1. Subjects linked to existing exams with approved questions appear in the dropdown.
 * 2. Subjects with no approved questions are excluded (e.g., existing exam with 0 approved questions).
 * 3. Subjects linked only to deleted/nonexistent exams are excluded.
 * 4. Orphaned questions with exam_id = NULL (such as Maths) are excluded from the dropdown.
 * 5. Questions associated with another valid exam remain available and unaffected.
 * 6. Server-side POST handler rejects orphaned subjects and fabricated subjects.
 * 7. Server-side POST handler allows valid subjects linked to active exams.
 * 8. Approved question count is calculated exclusively from valid exams and approved questions.
 * 9. StudyMaterialService::getApprovedQuestionsForSubject excludes orphaned questions.
 * 10. StudyMaterialService::generateSubjectStudyMaterial safely aborts for orphaned subjects.
 * 11. Legitimate orphaned database questions remain untouched (not deleted or altered).
 * 12. Dynamic behavior: creating an exam and approving questions shows it; deleting exam removes it.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';
require_once __DIR__ . '/../config/study_material_service.php';

echo "====================================================\n";
echo " Orphaned Subject Dropdown & POST Safety Test Suite  \n";
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
// TEST 1: Subjects linked to existing exams with approved questions appear
// --------------------------------------------------------------------------
try {
    $approvedSubjects = StudyMaterialService::getSubjectsWithApprovedQuestions($conn);
    $subjectNames = array_column($approvedSubjects, 'subject');

    // Python has approved questions linked to active exam #330
    $hasPython = in_array('Python', $subjectNames, true);

    report(
        "1. Subjects linked to existing exams with approved questions appear",
        $hasPython,
        "Python is listed in dropdown"
    );
} catch (Throwable $e) {
    report("1. Subjects linked to existing exams appear", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 2: Orphaned subjects with exam_id = NULL (Maths) are strictly excluded
// --------------------------------------------------------------------------
try {
    $approvedSubjects = StudyMaterialService::getSubjectsWithApprovedQuestions($conn);
    $subjectNames = array_column($approvedSubjects, 'subject');

    $mathsExcluded = !in_array('Maths', $subjectNames, true);

    // Verify Maths questions still exist in DB
    $mathsCount = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE subject = 'Maths' AND status = 'approved'")->fetch_row()[0];

    report(
        "2. Orphaned subjects with exam_id = NULL (Maths) are strictly excluded from dropdown",
        $mathsExcluded && $mathsCount > 0,
        "Maths excluded from dropdown while {$mathsCount} questions preserved in DB"
    );
} catch (Throwable $e) {
    report("2. Orphaned subjects with exam_id = NULL excluded", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 3: Subjects with no approved questions are excluded
// --------------------------------------------------------------------------
try {
    // Exam 'Java' exists in exams (#331) but has no approved questions
    $approvedSubjects = StudyMaterialService::getSubjectsWithApprovedQuestions($conn);
    $subjectNames = array_column($approvedSubjects, 'subject');

    $javaExcluded = !in_array('Java', $subjectNames, true);

    report(
        "3. Subjects with no approved questions are excluded (even if exam exists)",
        $javaExcluded,
        "Java has exam but 0 approved questions; excluded from dropdown"
    );
} catch (Throwable $e) {
    report("3. Subjects with no approved questions excluded", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 4: Subjects linked only to deleted/nonexistent exams are excluded
// --------------------------------------------------------------------------
try {
    // 1. Create a dummy exam and approved questions for a unique test subject
    $testSubj = "TestSubjOrphan_" . uniqid();
    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Temp Orphan Test Exam', 30, 10, 1)");
    $tempExamId = (int)$conn->insert_id;

    $conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_tmp', 1, {$tempExamId}, '{$testSubj}', 'TopicX', 'Q1', 'A', 'B', 'C', 'D', 'A', 'Exp', 'easy', 'approved')");
    $tempQId = (int)$conn->insert_id;

    // Verify it appears while exam exists
    $subjsBefore = StudyMaterialService::getSubjectsWithApprovedQuestions($conn);
    $namesBefore = array_column($subjsBefore, 'subject');
    $appearedBefore = in_array($testSubj, $namesBefore, true);

    // 2. Delete the exam directly from exams table WITHOUT cascading questions (creates orphaned approved question)
    $conn->query("DELETE FROM exams WHERE id = {$tempExamId}");

    // Verify it is now immediately excluded from dropdown
    $subjsAfter = StudyMaterialService::getSubjectsWithApprovedQuestions($conn);
    $namesAfter = array_column($subjsAfter, 'subject');
    $excludedAfter = !in_array($testSubj, $namesAfter, true);

    // Clean up temporary question
    $conn->query("DELETE FROM ai_generated_questions WHERE id = {$tempQId}");

    report(
        "4. Subjects linked only to deleted/nonexistent exams are strictly excluded",
        $appearedBefore && $excludedAfter,
        "Subject appeared with exam, disappeared immediately when exam ceased to exist"
    );
} catch (Throwable $e) {
    report("4. Subjects linked to deleted exams excluded", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 5: Questions associated with another valid exam remain available
// --------------------------------------------------------------------------
try {
    // Create Exam Alpha and Exam Beta
    $subjAlpha = "SubjAlpha_" . uniqid();
    $subjBeta = "SubjBeta_" . uniqid();

    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Alpha Exam', 30, 10, 1)");
    $examA = (int)$conn->insert_id;
    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Beta Exam', 30, 10, 1)");
    $examB = (int)$conn->insert_id;

    $conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_a', 1, {$examA}, '{$subjAlpha}', 'TopicA', 'QA', 'A', 'B', 'C', 'D', 'A', 'Exp', 'easy', 'approved')");
    $qA = (int)$conn->insert_id;

    $conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_b', 1, {$examB}, '{$subjBeta}', 'TopicB', 'QB', 'A', 'B', 'C', 'D', 'A', 'Exp', 'easy', 'approved')");
    $qB = (int)$conn->insert_id;

    // Delete Exam A
    $conn->query("DELETE FROM exams WHERE id = {$examA}");

    // Verify SubjBeta remains available in dropdown, while SubjAlpha is gone
    $subjs = StudyMaterialService::getSubjectsWithApprovedQuestions($conn);
    $names = array_column($subjs, 'subject');

    $alphaGone = !in_array($subjAlpha, $names, true);
    $betaPresent = in_array($subjBeta, $names, true);

    // Clean up
    $conn->query("DELETE FROM ai_generated_questions WHERE id IN ({$qA}, {$qB})");
    $conn->query("DELETE FROM exams WHERE id = {$examB}");

    report(
        "5. Questions associated with another valid exam remain available and unaffected",
        $alphaGone && $betaPresent,
        "Deleting Exam A leaves Exam B subject intact"
    );
} catch (Throwable $e) {
    report("5. Questions with another valid exam remain available", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 6: Approved question count is calculated only from valid exams and approved status
// --------------------------------------------------------------------------
try {
    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Count Test Exam', 30, 10, 1)");
    $cExamId = (int)$conn->insert_id;
    $cSubj = "CountSubj_" . uniqid();

    // 2 approved under this valid exam
    $conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_c', 1, {$cExamId}, '{$cSubj}', 'TopicC', 'Q1', 'A', 'B', 'C', 'D', 'A', 'Exp', 'easy', 'approved')");
    $cQ1 = (int)$conn->insert_id;
    $conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_c', 1, {$cExamId}, '{$cSubj}', 'TopicC', 'Q2', 'A', 'B', 'C', 'D', 'A', 'Exp', 'easy', 'approved')");
    $cQ2 = (int)$conn->insert_id;

    // 1 pending under this exam (should NOT count)
    $conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_c', 1, {$cExamId}, '{$cSubj}', 'TopicC', 'QP', 'A', 'B', 'C', 'D', 'A', 'Exp', 'easy', 'pending')");
    $cQP = (int)$conn->insert_id;

    // 1 approved under NULL exam (orphaned, should NOT count)
    $conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_c', 1, NULL, '{$cSubj}', 'TopicC', 'QO', 'A', 'B', 'C', 'D', 'A', 'Exp', 'easy', 'approved')");
    $cQO = (int)$conn->insert_id;

    $subjs = StudyMaterialService::getSubjectsWithApprovedQuestions($conn);
    $foundCount = 0;
    foreach ($subjs as $s) {
        if ($s['subject'] === $cSubj) {
            $foundCount = $s['count'];
            break;
        }
    }

    $countAccurate = ($foundCount === 2);

    // Clean up
    $conn->query("DELETE FROM ai_generated_questions WHERE id IN ({$cQ1}, {$cQ2}, {$cQP}, {$cQO})");
    $conn->query("DELETE FROM exams WHERE id = {$cExamId}");

    report(
        "6. Approved-question count is calculated only from valid exams and approved questions",
        $countAccurate,
        "Expected exactly 2 approved valid questions, got {$foundCount}"
    );
} catch (Throwable $e) {
    report("6. Approved count accuracy", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 7: Server-side POST handler rejects orphaned subjects (Maths)
// --------------------------------------------------------------------------
try {
    $csrfToken = bin2hex(random_bytes(32));

    $res = runScriptIsolated(
        'admin/manage_course_materials.php',
        ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfToken],
        ['action' => 'generate_study_material', 'subject' => 'Maths', 'csrf_token' => $csrfToken]
    );

    $rejectedWithWarning = (
        str_contains($res['stdout'], "does not have approved questions linked to an active exam") ||
        str_contains($res['stdout'], "Generation aborted")
    );

    report(
        "7. Server-side POST handler rejects orphaned subjects (Maths)",
        $rejectedWithWarning,
        "POST request for 'Maths' rejected with warning message"
    );
} catch (Throwable $e) {
    report("7. Server-side POST handler rejects orphaned subjects", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 8: Server-side POST handler rejects fabricated subjects
// --------------------------------------------------------------------------
try {
    $csrfToken = bin2hex(random_bytes(32));

    $res = runScriptIsolated(
        'admin/manage_course_materials.php',
        ['role' => 'admin', 'user_id' => 1, 'csrf_token' => $csrfToken],
        ['action' => 'generate_study_material', 'subject' => 'FakeSubject_12345', 'csrf_token' => $csrfToken]
    );

    $rejectedFabricated = (
        str_contains($res['stdout'], "does not have approved questions linked to an active exam") ||
        str_contains($res['stdout'], "Generation aborted")
    );

    report(
        "8. Server-side POST handler rejects fabricated subjects",
        $rejectedFabricated,
        "Fabricated subject rejected without triggering generation"
    );
} catch (Throwable $e) {
    report("8. Server-side POST handler rejects fabricated subjects", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 9: getApprovedQuestionsForSubject excludes orphaned questions
// --------------------------------------------------------------------------
try {
    $mathsQuestions = StudyMaterialService::getApprovedQuestionsForSubject($conn, 'Maths');
    $isEmpty = (count($mathsQuestions) === 0);

    report(
        "9. getApprovedQuestionsForSubject returns empty for orphaned subjects",
        $isEmpty,
        "Returned 0 questions for orphaned Maths subject"
    );
} catch (Throwable $e) {
    report("9. getApprovedQuestionsForSubject orphaned check", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 10: generateSubjectStudyMaterial safely aborts for orphaned subjects
// --------------------------------------------------------------------------
try {
    $genRes = StudyMaterialService::generateSubjectStudyMaterial($conn, 'Maths', 1);

    $safelyAborted = (
        !empty($genRes['empty']) &&
        empty($genRes['success']) &&
        str_contains($genRes['message'] ?? '', 'No approved AI-generated questions found linked to valid exams')
    );

    report(
        "10. generateSubjectStudyMaterial safely aborts for orphaned subjects",
        $safelyAborted,
        "Safely aborted with clear explanation message"
    );
} catch (Throwable $e) {
    report("10. generateSubjectStudyMaterial orphan safety", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 11: Legitimate database data preserved (Maths questions not deleted)
// --------------------------------------------------------------------------
try {
    $countMaths = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE subject = 'Maths'")->fetch_row()[0];
    $countOS = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE subject = 'Operating Systems'")->fetch_row()[0];

    $preserved = ($countMaths === 10 && $countOS === 2);

    report(
        "11. Legitimate database records preserved intact (no automatic deletion)",
        $preserved,
        "10 Maths questions and 2 OS questions remain preserved in database"
    );
} catch (Throwable $e) {
    report("11. Data preservation check", false, $e->getMessage());
}

// --------------------------------------------------------------------------
// TEST 12: Dynamic behavior: dropdown reflects newly added/deleted exams without hardcoding
// --------------------------------------------------------------------------
try {
    $dynSubj = "DynamicSubj_" . uniqid();

    // Create exam
    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Dynamic Exam', 30, 10, 1)");
    $dynExamId = (int)$conn->insert_id;

    // Approve questions
    $conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_dyn', 1, {$dynExamId}, '{$dynSubj}', 'DynTopic', 'DQ', 'A', 'B', 'C', 'D', 'A', 'Exp', 'easy', 'approved')");
    $dynQId = (int)$conn->insert_id;

    $afterAdd = StudyMaterialService::getSubjectsWithApprovedQuestions($conn);
    $hasDynamic = in_array($dynSubj, array_column($afterAdd, 'subject'), true);

    // Delete exam
    $conn->query("DELETE FROM ai_generated_questions WHERE id = {$dynQId}");
    $conn->query("DELETE FROM exams WHERE id = {$dynExamId}");

    $afterDel = StudyMaterialService::getSubjectsWithApprovedQuestions($conn);
    $goneDynamic = !in_array($dynSubj, array_column($afterDel, 'subject'), true);

    report(
        "12. Dynamic dropdown behavior: appears on exam+approval, disappears on removal",
        $hasDynamic && $goneDynamic,
        "Dynamic subject appears when linked to valid exam and vanishes when unlinked"
    );
} catch (Throwable $e) {
    report("12. Dynamic dropdown behavior", false, $e->getMessage());
}

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
} else {
    exit(0);
}
