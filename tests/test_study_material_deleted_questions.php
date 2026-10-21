<?php
/**
 * Test Suite: Exclusion of Deleted & Historical Questions from AI Study Material Generation
 *
 * Covers all required regression scenarios:
 * 1. Five eligible questions with five deleted historical questions: only five are included.
 * 2. Pending and rejected questions are strictly excluded.
 * 3. Questions from another subject or exam are strictly excluded.
 * 4. Filename and displayed count match the actual number of included questions.
 * 5. Regeneration after deleting questions does not restore deleted questions from stale data.
 * 6. Empty eligible-question sets are handled safely without corrupting documents.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';
require_once __DIR__ . '/../config/study_material_service.php';

echo "====================================================================\n";
echo " Regression Test Suite: Deleted Questions Exclusion from Study Notes\n";
echo "====================================================================\n\n";

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

StudyMaterialService::ensureSchema($conn);

$createdExams = [];
$createdDocs = [];
$createdFiles = [];

try {
    // --------------------------------------------------------------------------
    // SETUP: Create primary test exam and 10 AI questions (5 active, 5 deleted)
    // --------------------------------------------------------------------------
    $testSubj = "RegExSubj_" . uniqid();
    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Regression Test Exam Primary', 30, 10, 1)");
    $primaryExamId = (int)$conn->insert_id;
    $createdExams[] = $primaryExamId;

    $activeAiIds = [];
    $activeQuestionIds = [];
    $deletedAiIds = [];

    // Create 5 questions that remain ACTIVE in both ai_generated_questions and questions
    for ($i = 1; $i <= 5; $i++) {
        $qText = "Eligible Active Question {$i} for {$testSubj}";
        $optA = "Option A{$i}";
        $optB = "Option B{$i}";
        $optC = "Option C{$i}";
        $optD = "Option D{$i}";
        $correct = "A";
        $exp = "Official explanation for active question {$i}";

        $stmtAi = $conn->prepare("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_reg_act', 1, ?, ?, 'CoreModule', ?, ?, ?, ?, ?, ?, ?, 'medium', 'approved')");
        $stmtAi->bind_param("issssssss", $primaryExamId, $testSubj, $qText, $optA, $optB, $optC, $optD, $correct, $exp);
        $stmtAi->execute();
        $aiId = (int)$stmtAi->insert_id;
        $activeAiIds[] = $aiId;
        $stmtAi->close();

        $stmtAct = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtAct->bind_param("issssss", $primaryExamId, $qText, $optA, $optB, $optC, $optD, $correct);
        $stmtAct->execute();
        $activeQuestionIds[] = (int)$stmtAct->insert_id;
        $stmtAct->close();
    }

    // Create 5 HISTORICAL questions that are marked approved in ai_generated_questions
    // but were DELETED from questions table (simulating deleted questions)
    for ($i = 6; $i <= 10; $i++) {
        $qText = "Deleted Historical Question {$i} for {$testSubj}";
        $stmtAi = $conn->prepare("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_reg_del', 1, ?, ?, 'OldModule', ?, 'A', 'B', 'C', 'D', 'A', 'Historical exp', 'medium', 'approved')");
        $stmtAi->bind_param("iss", $primaryExamId, $testSubj, $qText);
        $stmtAi->execute();
        $deletedAiIds[] = (int)$stmtAi->insert_id;
        $stmtAi->close();
    }

    // --------------------------------------------------------------------------
    // SCENARIO 1: 5 eligible questions with 5 deleted historical questions
    // --------------------------------------------------------------------------
    $approvedQuestions = StudyMaterialService::getApprovedQuestionsForSubject($conn, $testSubj);
    $approvedIds = array_column($approvedQuestions, 'id');
    sort($approvedIds);
    sort($activeAiIds);

    $allActiveIncluded = ($approvedIds === $activeAiIds);
    $noDeletedIncluded = (count(array_intersect($approvedIds, $deletedAiIds)) === 0);

    // Verify dropdown count
    $dropdownSubjects = StudyMaterialService::getSubjectsWithApprovedQuestions($conn);
    $displayedCount = 0;
    foreach ($dropdownSubjects as $ds) {
        if ($ds['subject'] === $testSubj) {
            $displayedCount = $ds['count'];
            break;
        }
    }

    report(
        "1. Five eligible questions with five deleted historical questions: only five are included",
        $allActiveIncluded && $noDeletedIncluded && ($displayedCount === 5) && (count($approvedQuestions) === 5),
        "Retrieved exactly 5 active questions, 0 deleted historical questions; displayed count is {$displayedCount}"
    );

    // --------------------------------------------------------------------------
    // SCENARIO 2: Pending and rejected questions are strictly excluded
    // --------------------------------------------------------------------------
    $pendText = "Pending Question for {$testSubj}";
    $conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_reg_p', 1, {$primaryExamId}, '{$testSubj}', 'CoreModule', '{$pendText}', 'A', 'B', 'C', 'D', 'A', 'Exp', 'medium', 'pending')");
    $pendAiId = (int)$conn->insert_id;

    $rejtText = "Rejected Question for {$testSubj}";
    $conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_reg_r', 1, {$primaryExamId}, '{$testSubj}', 'CoreModule', '{$rejtText}', 'A', 'B', 'C', 'D', 'A', 'Exp', 'medium', 'rejected')");
    $rejtAiId = (int)$conn->insert_id;

    $afterPendingCheck = StudyMaterialService::getApprovedQuestionsForSubject($conn, $testSubj);
    $afterIds = array_column($afterPendingCheck, 'id');

    $pendingExcluded = !in_array($pendAiId, $afterIds, true);
    $rejectedExcluded = !in_array($rejtAiId, $afterIds, true);

    report(
        "2. Pending and rejected questions are strictly excluded",
        $pendingExcluded && $rejectedExcluded && (count($afterPendingCheck) === 5),
        "Pending and rejected questions excluded; approved count remains 5"
    );

    // --------------------------------------------------------------------------
    // SCENARIO 3: Questions from another subject or exam are strictly excluded
    // --------------------------------------------------------------------------
    $otherSubj = "OtherSubj_" . uniqid();
    $conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Regression Test Exam Secondary', 30, 10, 1)");
    $secondExamId = (int)$conn->insert_id;
    $createdExams[] = $secondExamId;

    $otherQText = "Other Subject Question for {$otherSubj}";
    $conn->query("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_reg_oth', 1, {$secondExamId}, '{$otherSubj}', 'OtherTopic', '{$otherQText}', 'A', 'B', 'C', 'D', 'A', 'Exp', 'medium', 'approved')");
    $otherAiId = (int)$conn->insert_id;
    $conn->query("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES ({$secondExamId}, '{$otherQText}', 'A', 'B', 'C', 'D', 'A')");

    $scopedQuestions = StudyMaterialService::getApprovedQuestionsForSubject($conn, $testSubj);
    $scopedOtherFound = false;
    foreach ($scopedQuestions as $sq) {
        if ($sq['subject'] !== $testSubj || (int)$sq['exam_id'] !== $primaryExamId) {
            $scopedOtherFound = true;
        }
    }

    report(
        "3. Questions from another subject or exam are strictly excluded",
        !$scopedOtherFound && (count($scopedQuestions) === 5),
        "All questions belong exclusively to {$testSubj} and Exam #{$primaryExamId}"
    );

    // --------------------------------------------------------------------------
    // SCENARIO 4: Filename and displayed count match actual number of included questions
    // --------------------------------------------------------------------------
    $genRes = StudyMaterialService::generateSubjectStudyMaterial($conn, $testSubj, 1);
    $docId = (int)($genRes['doc_id'] ?? 0);
    if ($docId > 0) {
        $createdDocs[] = $docId;
    }

    $docRow = $conn->query("SELECT * FROM ai_documents WHERE id = {$docId}")->fetch_assoc();
    $filePath = $docRow['file_path'] ?? '';
    if (!empty($filePath)) {
        $createdFiles[] = $filePath;
    }

    $fileContent = (file_exists($filePath)) ? file_get_contents($filePath) : '';

    $expectedFilename = "Study Notes - {$testSubj} (5 Questions).md";
    $filenameMatches = ($docRow['original_name'] === $expectedFilename);
    $contentDeclaresCount = str_contains($fileContent, "**Total Approved Questions:** 5 Questions");
    $ragChunksValid = ((int)$docRow['total_chunks'] > 0 && $docRow['status'] === 'ingested');
    $sourceIdsMatches = ($docRow['source_question_ids'] === implode(',', $activeAiIds));

    report(
        "4. Filename and displayed count match the actual number of included questions",
        $filenameMatches && $contentDeclaresCount && $ragChunksValid && $sourceIdsMatches,
        "Original Name: '{$docRow['original_name']}', Chunks: {$docRow['total_chunks']}, Status: {$docRow['status']}"
    );

    // --------------------------------------------------------------------------
    // SCENARIO 5: Regeneration after deleting questions does not restore deleted questions
    // --------------------------------------------------------------------------
    // Delete 2 of the active questions from `questions` table (leaving 3 eligible questions)
    $qToDel1 = $activeQuestionIds[3];
    $qToDel2 = $activeQuestionIds[4];
    $conn->query("DELETE FROM questions WHERE id IN ({$qToDel1}, {$qToDel2})");

    $remainingEligible = StudyMaterialService::getApprovedQuestionsForSubject($conn, $testSubj);
    $remainingCount = count($remainingEligible);

    // Regenerate study notes
    $regenRes = StudyMaterialService::generateSubjectStudyMaterial($conn, $testSubj, 1);
    $regenDocRow = $conn->query("SELECT * FROM ai_documents WHERE id = {$docId}")->fetch_assoc();
    $regenContent = (file_exists($filePath)) ? file_get_contents($filePath) : '';

    $expectedRegenName = "Study Notes - {$testSubj} (3 Questions).md";
    $regenNameCorrect = ($regenDocRow['original_name'] === $expectedRegenName);
    $regenContentCorrect = str_contains($regenContent, "**Total Approved Questions:** 3 Questions");
    $oldQuestionsGone = (!str_contains($regenContent, "Eligible Active Question 4") && !str_contains($regenContent, "Eligible Active Question 5"));
    $deletedHistoricalStillGone = !str_contains($regenContent, "Deleted Historical Question");
    $inPlaceUpdate = ($regenDocRow['id'] == $docId && !empty($regenRes['updated']));

    report(
        "5. Regeneration after deleting questions does not restore deleted questions from stale data",
        $remainingCount === 3 && $regenNameCorrect && $regenContentCorrect && $oldQuestionsGone && $deletedHistoricalStillGone && $inPlaceUpdate,
        "Regenerated in-place to '{$expectedRegenName}' containing strictly 3 current questions without stale data"
    );

    // --------------------------------------------------------------------------
    // SCENARIO 6: Empty eligible-question sets are handled safely
    // --------------------------------------------------------------------------
    // Delete the remaining 3 questions from `questions` table (leaving 0 eligible questions)
    $conn->query("DELETE FROM questions WHERE exam_id = {$primaryExamId}");

    // Verify dropdown excludes the subject now
    $emptySubjects = StudyMaterialService::getSubjectsWithApprovedQuestions($conn);
    $dropdownExcludesEmpty = !in_array($testSubj, array_column($emptySubjects, 'subject'), true);

    // Call generate on the empty subject
    $emptyRes = StudyMaterialService::generateSubjectStudyMaterial($conn, $testSubj, 1);

    // Verify document was not corrupted or overwritten with empty content
    $postEmptyDocRow = $conn->query("SELECT * FROM ai_documents WHERE id = {$docId}")->fetch_assoc();
    $docNotOverwritten = ($postEmptyDocRow['original_name'] === $expectedRegenName);

    $safelyAborted = (
        $emptyRes['success'] === false &&
        !empty($emptyRes['empty']) &&
        $emptyRes['count'] === 0 &&
        str_contains($emptyRes['message'], 'No approved AI-generated questions found linked to valid exams')
    );

    report(
        "6. Empty eligible-question sets are handled safely without corrupting documents",
        $dropdownExcludesEmpty && $safelyAborted && $docNotOverwritten,
        "Generation safely aborted with message: '{$emptyRes['message']}'; existing document preserved"
    );

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    $failed++;
} finally {
    // --------------------------------------------------------------------------
    // TEARDOWN: Clean up all test data, vector embeddings, and files
    // --------------------------------------------------------------------------
    foreach ($createdDocs as $dId) {
        StudyMaterialService::deleteMaterial($conn, $dId);
        $conn->query("DELETE FROM ai_documents WHERE id = {$dId}");
    }
    foreach ($createdExams as $eId) {
        $conn->query("DELETE FROM questions WHERE exam_id = {$eId}");
        $conn->query("DELETE FROM ai_generated_questions WHERE exam_id = {$eId}");
        $conn->query("DELETE FROM exams WHERE id = {$eId}");
    }
    foreach ($createdFiles as $fPath) {
        if (file_exists($fPath)) {
            @unlink($fPath);
        }
    }
}

echo "\n--------------------------------------------------------------------\n";
echo "Regression Suite Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
} else {
    exit(0);
}
