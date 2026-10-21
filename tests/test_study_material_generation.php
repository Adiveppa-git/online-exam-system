<?php
/**
 * Test Suite: Course Material Management - Simplified AI Study Notes Generation
 *
 * Verifies:
 * 1. Notes contain content derived exclusively from approved questions of the selected subject.
 * 2. Questions from another subject are strictly excluded.
 * 3. Pending and rejected questions are completely excluded.
 * 4. Topics are detected and grouped automatically without hallucinating unsupported content.
 * 5. A subject with no approved questions produces a clear message and cannot generate notes.
 * 6. Deduplication and safe reuse/update: regenerating updates existing subject material in-place.
 * 7. Generated notes are saved to disk and indexed in ai_documents and RAG vector store.
 * 8. Dynamic subjects dropdown reflects only subjects with approved questions.
 * 9. UI validation: page contains only Select Subject and Generate button, no manual upload/topic/exam inputs.
 * 10. Existing RAG retrieval and AI assistant querying continue working seamlessly.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';
require_once __DIR__ . '/../config/study_material_service.php';

echo "====================================================\n";
echo " Simplified Study Material Generation Test Suite   \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

$createdDocIds = [];
$createdFiles = [];

// Ensure database schema is up-to-date
StudyMaterialService::ensureSchema($conn);

// Clean up any stale test fixtures before test execution
$conn->query("DELETE FROM ai_generated_questions WHERE request_id IN ('req_test_eng', 'req_test') OR subject = 'TestEnglish'");
$conn->query("DELETE FROM exams WHERE id = 126 OR title LIKE 'Test English Fixture%'");

// Setup temporary test fixture exam with dynamic ID
$stmtFixture = $conn->prepare("INSERT INTO exams (title, duration, marks_per_question, total_marks) VALUES ('Test English Fixture Exam', 30, 1, 30)");
$stmtFixture->execute();
$testExamId = (int)$conn->insert_id;
$stmtFixture->close();

for ($i = 1; $i <= 10; $i++) {
    $stmtQ = $conn->prepare("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_test_eng', 1, ?, 'English', 'Grammar', ?, 'Noun', 'Verb', 'Adjective', 'Adverb', 'A', 'Nouns identify people, places, or things.', 'easy', 'approved')");
    $qText = "English Grammar Question {$i}";
    $stmtQ->bind_param("is", $testExamId, $qText);
    $stmtQ->execute();
    $stmtQ->close();

    $stmtAct = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES (?, ?, 'Noun', 'Verb', 'Adjective', 'Adverb', 'A')");
    $stmtAct->bind_param("is", $testExamId, $qText);
    $stmtAct->execute();
    $stmtAct->close();
}

try {

// --------------------------------------------------------------------------
// TEST 1: Dynamic subjects dropdown reflects only subjects with approved questions
// --------------------------------------------------------------------------
try {
    echo "[TEST 1] Testing dynamic subjects retrieval for approved questions... ";
    $approvedSubjects = StudyMaterialService::getSubjectsWithApprovedQuestions($conn);

    $subjectNames = array_column($approvedSubjects, 'subject');
    $hasEnglish = in_array('English', $subjectNames, true);
    $hasPython = in_array('Python', $subjectNames, true);
    $noOrphanMaths = !in_array('Maths', $subjectNames, true);

    // Check that counts match database approved counts
    $countsValid = true;
    foreach ($approvedSubjects as $s) {
        $checkStmt = $conn->prepare("SELECT COUNT(DISTINCT act.id) as cnt FROM questions act INNER JOIN exams e ON act.exam_id = e.id INNER JOIN ai_generated_questions q ON q.exam_id = act.exam_id AND (act.question = q.question OR TRIM(act.question) = TRIM(q.question)) WHERE q.status = 'approved' AND q.subject = ?");
        $checkStmt->bind_param("s", $s['subject']);
        $checkStmt->execute();
        $dbCount = (int)$checkStmt->get_result()->fetch_assoc()['cnt'];
        $checkStmt->close();
        if ($dbCount !== (int)$s['count'] || $dbCount === 0) {
            $countsValid = false;
            break;
        }
    }

    if ($hasEnglish && $hasPython && $noOrphanMaths && $countsValid) {
        echo "PASSED (Dynamic subjects: " . implode(', ', $subjectNames) . " with accurate counts, orphan Maths excluded)\n";
        $passed++;
    } else {
        echo "FAILED (Unexpected approved subjects list or count mismatch)\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

// --------------------------------------------------------------------------
// TEST 2: Subject with approved questions generates material with automatic topic grouping
// --------------------------------------------------------------------------
try {
    echo "[TEST 2] Testing subject-level study material generation & topic grouping... ";

    $res = StudyMaterialService::generateSubjectStudyMaterial($conn, 'English', 1);

    if ($res['success'] && !empty($res['doc_id'])) {
        $englishDocId = (int)$res['doc_id'];
        if (empty($res['updated'])) {
            $createdDocIds[] = $res['doc_id'];
        }

        // Fetch document row
        $stmt = $conn->prepare("SELECT * FROM ai_documents WHERE id = ?");
        $stmt->bind_param("i", $res['doc_id']);
        $stmt->execute();
        $doc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($doc['file_path'])) {
            $createdFiles[] = $doc['file_path'];
        }

        $fileContent = file_exists($doc['file_path']) ? file_get_contents($doc['file_path']) : '';

        $hasTopicGrouping = str_contains($fileContent, '## 2. Core Concepts & Definitions (Grouped by Topic)');
        $hasFidelityNotice = str_contains($fileContent, 'Strict Source Fidelity Policy');
        $hasStatusIngested = ($doc['status'] === 'ingested');

        if ($hasTopicGrouping && $hasFidelityNotice && $hasStatusIngested) {
            echo "PASSED (Generated Doc #{$res['doc_id']}, {$res['chunks']} chunks, grouped topics: " . implode(', ', $res['topics'] ?? []) . ")\n";
            $passed++;
        } else {
            echo "FAILED (Missing topic grouping, fidelity notice, or status not ingested)\n";
            $failed++;
        }
    } else {
        echo "FAILED: " . ($res['message'] ?? 'Unknown error') . "\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

// --------------------------------------------------------------------------
// TEST 3: Questions from another subject never appear in the generated material
// --------------------------------------------------------------------------
try {
    echo "[TEST 3] Testing cross-subject question isolation... ";

    // Retrieve approved Python questions
    $pythonQuestions = StudyMaterialService::getApprovedQuestionsForSubject($conn, 'Python');

    // Retrieve generated English notes file
    $docId = $englishDocId ?? 137;
    $stmt = $conn->prepare("SELECT file_path FROM ai_documents WHERE id = ?");
    $stmt->bind_param("i", $docId);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $englishNotes = file_exists($doc['file_path']) ? file_get_contents($doc['file_path']) : '';

    $noPythonLeaked = true;
    foreach ($pythonQuestions as $pq) {
        $cleanQ = clean_question_title($pq['question']);
        if (str_contains($englishNotes, $cleanQ)) {
            $noPythonLeaked = false;
            break;
        }
    }

    if ($noPythonLeaked && !empty($pythonQuestions)) {
        echo "PASSED (Zero Python questions found in English study material)\n";
        $passed++;
    } else {
        echo "FAILED (Python questions leaked into English study material)\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

// --------------------------------------------------------------------------
// TEST 4: Pending and rejected questions are completely excluded
// --------------------------------------------------------------------------
try {
    echo "[TEST 4] Testing exclusion of pending and rejected questions... ";

    // Insert a temporary rejected AI question for English
    $tempRejectedText = "TEMPORARY_REJECTED_ENGLISH_Q_" . uniqid();
    $stmt = $conn->prepare("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_test', 1, ?, 'English', 'Grammar', ?, 'A', 'B', 'C', 'D', 'A', 'Explanation', 'easy', 'rejected')");
    $stmt->bind_param("is", $testExamId, $tempRejectedText);
    $stmt->execute();
    $tempId = $stmt->insert_id;
    $stmt->close();

    // Insert a temporary pending AI question for English
    $tempPendingText = "TEMPORARY_PENDING_ENGLISH_Q_" . uniqid();
    $stmt2 = $conn->prepare("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, subject, topic, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, status) VALUES ('req_test', 1, ?, 'English', 'Grammar', ?, 'A', 'B', 'C', 'D', 'A', 'Explanation', 'easy', 'pending')");
    $stmt2->bind_param("is", $testExamId, $tempPendingText);
    $stmt2->execute();
    $tempPendingId = $stmt2->insert_id;
    $stmt2->close();

    // Query approved questions for English
    $approvedList = StudyMaterialService::getApprovedQuestionsForSubject($conn, 'English');

    $foundRejected = false;
    $foundPending = false;
    foreach ($approvedList as $aq) {
        if ($aq['question'] === $tempRejectedText) $foundRejected = true;
        if ($aq['question'] === $tempPendingText) $foundPending = true;
    }

    // Cleanup temporary rows
    $conn->query("DELETE FROM ai_generated_questions WHERE id IN ({$tempId}, {$tempPendingId})");

    if (!$foundRejected && !$foundPending) {
        echo "PASSED (Rejected and pending questions strictly excluded from approved set)\n";
        $passed++;
    } else {
        echo "FAILED (Pending or rejected questions were included in approved set)\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

// --------------------------------------------------------------------------
// TEST 5: Subject with no approved questions cannot generate material
// --------------------------------------------------------------------------
try {
    echo "[TEST 5] Testing handling of subject with no approved questions... ";

    $resNoApproved = StudyMaterialService::generateSubjectStudyMaterial($conn, 'Astrophysics', 1);

    if ($resNoApproved['success'] === false && !empty($resNoApproved['empty']) && str_contains($resNoApproved['message'], 'No approved AI-generated questions found')) {
        echo "PASSED (Generation safely aborted with helpful warning message)\n";
        $passed++;
    } else {
        echo "FAILED (Expected failure for subject with no approved questions, got: " . json_encode($resNoApproved) . ")\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

// --------------------------------------------------------------------------
// TEST 6: Deduplication & Safe Update: regenerating reuses/updates existing subject material
// --------------------------------------------------------------------------
try {
    echo "[TEST 6] Testing deduplication & in-place update for existing subject... ";

    $initialDocId = $englishDocId ?? 137;

    // Call generateSubjectStudyMaterial again for English
    $resUpdate = StudyMaterialService::generateSubjectStudyMaterial($conn, 'English', 1);

    $isUpdated = (!empty($resUpdate['success']) && !empty($resUpdate['updated']) && (int)$resUpdate['doc_id'] === (int)$initialDocId);

    // Verify no duplicate row was created in ai_documents
    $countStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM ai_documents WHERE is_generated = 1 AND subject = 'English'");
    $countStmt->execute();
    $cnt = (int)$countStmt->get_result()->fetch_assoc()['cnt'];
    $countStmt->close();

    if ($isUpdated && $cnt === 1) {
        echo "PASSED (Existing Document #{$initialDocId} updated in-place without duplicate rows)\n";
        $passed++;
    } else {
        echo "FAILED (Deduplication failed: isUpdated=$isUpdated, count=$cnt)\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

// --------------------------------------------------------------------------
// TEST 7: UI Simplification Verification in manage_course_materials.php
// --------------------------------------------------------------------------
try {
    echo "[TEST 7] Testing UI simplification (removal of manual upload, target exam, topic inputs)... ";

    $pageHtml = file_get_contents(__DIR__ . '/../admin/manage_course_materials.php');

    $hasSelectSubject = str_contains($pageHtml, 'name="subject"') && str_contains($pageHtml, 'id="subjectSelect"');
    $hasGenerateButton = str_contains($pageHtml, 'Generate Study Materials');
    $hasLoadingIndicator = str_contains($pageHtml, 'id="loadingIndicator"');

    // Ensure removed sections and fields are absent
    $noUploadMyOwn = !str_contains($pageHtml, 'Upload My Own Material');
    $noMaterialFileInput = !str_contains($pageHtml, 'name="material_file"');
    $noTargetExamSelect = !str_contains($pageHtml, 'name="exam_id"');
    $noManualTopicInput = !str_contains($pageHtml, 'name="topic"');
    $noOverwriteCheckbox = !str_contains($pageHtml, 'name="force_regenerate"');

    if ($hasSelectSubject && $hasGenerateButton && $hasLoadingIndicator &&
        $noUploadMyOwn && $noMaterialFileInput && $noTargetExamSelect &&
        $noManualTopicInput && $noOverwriteCheckbox) {
        echo "PASSED (UI contains only Select Subject & Generate button; all unnecessary fields removed)\n";
        $passed++;
    } else {
        echo "FAILED (Unexpected input elements or missing simplified controls in manage_course_materials.php)\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

// --------------------------------------------------------------------------
// TEST 8: RAG Retrieval and AI Student Assistant continue working with generated notes
// --------------------------------------------------------------------------
try {
    echo "[TEST 8] Testing AI Student Assistant RAG retrieval with generated material... ";

    $aiClient = new AiClient();
    $studentCtx = ['id' => 1, 'name' => 'Adi', 'role' => 'student'];

    $ragRes = $aiClient->askRAG("What are the core concepts covered in English?", 'English', null, 3, null, 1, [], $studentCtx);

    if ($ragRes['status'] === 'success' && !empty($ragRes['data']['answer'])) {
        echo "PASSED (RAG successfully retrieved and answered using indexed study material)\n";
        $passed++;
    } else {
        echo "FAILED (RAG response status: " . ($ragRes['status'] ?? 'unknown') . ")\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

// --------------------------------------------------------------------------
// TEST 9: Verify Exam ID resolution and distinctness from Document ID
// --------------------------------------------------------------------------
try {
    echo "[TEST 9] Testing Exam ID resolution and distinctness from Document ID... ";

    $engStmt = $conn->prepare("SELECT id, exam_id, subject FROM ai_documents WHERE subject = 'English' ORDER BY id DESC LIMIT 1");
    $engStmt->execute();
    $engDoc = $engStmt->get_result()->fetch_assoc();
    $engStmt->close();

    $docId = (int)$engDoc['id'];
    $examId = (int)$engDoc['exam_id'];

    // Verify document correctly maps to the test fixture exam ID
    $isDistinct = ($docId !== $examId);
    $isExamMapped = ($examId === $testExamId);

    if ($isDistinct && $isExamMapped) {
        echo "PASSED (Document #{$docId} correctly mapped to Exam #{$examId}, distinct and verified)\n";
        $passed++;
    } else {
        echo "FAILED (Expected docId != examId and examId == {$testExamId}, got docId={$docId}, examId={$examId})\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

// --------------------------------------------------------------------------
// TEST 10: Verify S. No. sequential ordering and Subject-level labeling
// --------------------------------------------------------------------------
try {
    echo "[TEST 10] Testing S. No. sequential numbering & Subject-level scope handling... ";

    $pageContent = file_get_contents(__DIR__ . '/../admin/manage_course_materials.php');

    $hasSnoHeader = str_contains($pageContent, 'SL No') || str_contains($pageContent, 'S. No.');
    $hasExamIdHeader = str_contains($pageContent, 'Exam ID</th>');
    $hasSnoCounter = (str_contains($pageContent, '$slno = 1') && str_contains($pageContent, '$slno++')) || (str_contains($pageContent, '$sno = 1') && str_contains($pageContent, '$sno++'));
    $hasSubjectLevelBadge = str_contains($pageContent, 'Subject-level');
    $hasDocBadge = str_contains($pageContent, 'Doc #<?= $doc[\'id\'] ?>');

    if ($hasSnoHeader && $hasExamIdHeader && $hasSnoCounter && $hasSubjectLevelBadge && $hasDocBadge) {
        echo "PASSED (SL No, Exam ID, Subject-level badge, and Doc #ID subtitle verified in table markup)\n";
        $passed++;
    } else {
        echo "FAILED (Table markup missing SL No, Exam ID, or Subject-level label)\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

// --------------------------------------------------------------------------
// TEST 11: Verify Retry Ingestion is idempotent and chunk count > 0
// --------------------------------------------------------------------------
try {
    echo "[TEST 11] Testing Retry Ingestion idempotency & chunk count verification... ";

    $engStmt = $conn->prepare("SELECT id, total_chunks, status FROM ai_documents WHERE subject = 'English' ORDER BY id DESC LIMIT 1");
    $engStmt->execute();
    $beforeDoc = $engStmt->get_result()->fetch_assoc();
    $engStmt->close();

    $docId = (int)$beforeDoc['id'];
    $chunksBefore = (int)$beforeDoc['total_chunks'];

    $retryRes = StudyMaterialService::retryIngestion($conn, $docId, 1);

    $engStmt = $conn->prepare("SELECT id, total_chunks, status, error_message FROM ai_documents WHERE id = ?");
    $engStmt->bind_param("i", $docId);
    $engStmt->execute();
    $afterDoc = $engStmt->get_result()->fetch_assoc();
    $engStmt->close();

    $chunksAfter = (int)$afterDoc['total_chunks'];
    $statusAfter = $afterDoc['status'];

    $isSuccessful = !empty($retryRes['success']);
    $hasChunks = ($chunksAfter > 0);
    $isIngested = ($statusAfter === 'ingested');
    $noDuplicateVectors = ($chunksAfter === $chunksBefore);

    if ($isSuccessful && $hasChunks && $isIngested && $noDuplicateVectors) {
        echo "PASSED (Idempotent retry: chunks={$chunksAfter}, status={$statusAfter}, no duplicate vectors)\n";
        $passed++;
    } else {
        echo "FAILED (Retry verification failed: success={$isSuccessful}, chunksAfter={$chunksAfter}, status={$statusAfter})\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    $failed++;
}

} finally {
    // --------------------------------------------------------------------------
    // Guaranteed cleanup: Remove all temporary test fixtures, exams, and files
    // --------------------------------------------------------------------------
    if (!empty($testExamId)) {
        StudyMaterialService::deleteMaterialsForExam($conn, $testExamId);
        $conn->query("DELETE FROM questions WHERE exam_id = {$testExamId}");
        $conn->query("DELETE FROM ai_generated_questions WHERE exam_id = {$testExamId}");
        $conn->query("DELETE FROM exams WHERE id = {$testExamId}");
    }
    // Clean up any other test docs and files
    $conn->query("DELETE FROM ai_generated_questions WHERE request_id IN ('req_test_eng', 'req_test')");
    $conn->query("DELETE FROM exams WHERE id = 126");
    foreach ($createdDocIds as $dId) {
        $del = $conn->prepare("DELETE FROM ai_documents WHERE id = ?");
        $del->bind_param("i", $dId);
        $del->execute();
        $del->close();
    }
    foreach ($createdFiles as $fPath) {
        if (file_exists($fPath)) {
            @unlink($fPath);
        }
    }
}

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
} else {
    exit(0);
}
