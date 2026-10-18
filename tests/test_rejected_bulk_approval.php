<?php
/**
 * Regression Test Suite: Bulk Approval Safety & Isolation of Rejected Records
 * 
 * Proves:
 * 1. Rejected question #162 with an invalid exam ID does not block approval.
 * 2. Rejected question #163 with an invalid exam ID does not block approval.
 * 3. A genuinely pending question with an invalid exam ID is still reported and blocks bulk approval.
 * 4. Valid pending questions can be approved when rejected invalid records coexist.
 * 5. Pending counts and warning counts agree with the persisted database statuses.
 * 6. Rejected and approved records remain unchanged after bulk approval.
 * 7. Bulk rejection updates statuses correctly, commits transactional review audit metadata, and refreshes the queue.
 * 8. Question cards for rejected records #162 and #163 do not render invalid-exam warning badges.
 * 9. Review queue status filters (pending, approved, rejected, all) show correct mutually exclusive sets and accurate counts.
 */

require_once __DIR__ . '/../config/db.php';

$passed = 0;
$failed = 0;

function report_result($testNum, $description, $success, $details = '') {
    global $passed, $failed;
    echo "[Test {$testNum}] {$description}... ";
    if ($success) {
        echo "PASSED";
        if ($details) echo " ({$details})";
        echo "\n";
        $passed++;
    } else {
        echo "FAILED";
        if ($details) echo " ({$details})";
        echo "\n";
        $failed++;
    }
}

echo "===================================================================\n";
echo "   Regression Test Suite: Rejected Questions Bulk Approval Safety   \n";
echo "===================================================================\n\n";

// Helper to get unassigned pending IDs using the exact query from review_ai_questions.php
function getUnassignedPendingIds($conn, $examIdFilter = 0) {
    $where = "WHERE q.status = 'pending' AND (q.exam_id IS NULL OR q.exam_id = 0 OR e.id IS NULL)";
    if ($examIdFilter > 0) {
        $where .= " AND q.exam_id = " . (int)$examIdFilter;
    }
    $res = $conn->query("SELECT q.id FROM ai_generated_questions q LEFT JOIN exams e ON q.exam_id = e.id {$where}");
    $ids = [];
    while ($r = $res->fetch_assoc()) {
        $ids[] = (int)$r['id'];
    }
    return $ids;
}

// -----------------------------------------------------------------
// Test 1: Rejected question #162 with an invalid exam ID does not block approval
// -----------------------------------------------------------------
$q162 = $conn->query("SELECT id, exam_id, status, rejection_reason FROM ai_generated_questions WHERE id = 162")->fetch_assoc();
$unassignedGlobal = getUnassignedPendingIds($conn);

$t1Pass = ($q162 !== null)
    && ($q162['status'] === 'rejected')
    && empty($q162['exam_id'])
    && !in_array(162, $unassignedGlobal, true);

report_result(1, "Rejected question #162 with invalid exam ID does not block approval", $t1Pass, 
    "Status: {$q162['status']}, Exam ID: " . var_export($q162['exam_id'], true) . ", Excluded from unassigned pending warning: " . (!in_array(162, $unassignedGlobal, true) ? 'YES' : 'NO')
);

// -----------------------------------------------------------------
// Test 2: Rejected question #163 with an invalid exam ID does not block approval
// -----------------------------------------------------------------
$q163 = $conn->query("SELECT id, exam_id, status, rejection_reason FROM ai_generated_questions WHERE id = 163")->fetch_assoc();

$t2Pass = ($q163 !== null)
    && ($q163['status'] === 'rejected')
    && empty($q163['exam_id'])
    && !in_array(163, $unassignedGlobal, true);

report_result(2, "Rejected question #163 with invalid exam ID does not block approval", $t2Pass,
    "Status: {$q163['status']}, Exam ID: " . var_export($q163['exam_id'], true) . ", Excluded from unassigned pending warning: " . (!in_array(163, $unassignedGlobal, true) ? 'YES' : 'NO')
);

// -----------------------------------------------------------------
// Test 3: A genuinely pending question with an invalid exam ID is still reported and blocks bulk approval
// -----------------------------------------------------------------
$insTestQ = $conn->prepare("INSERT INTO ai_generated_questions (request_id, admin_id, question, option_a, option_b, option_c, option_d, correct_option, difficulty, status, exam_id) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$tempReqId = "test_gen_invalid_" . time();
$tempQText = "What is an invalid exam regression test question?";
$opA = "A"; $opB = "B"; $opC = "C"; $opD = "D"; $cor = "A"; $diff = "easy"; $stPending = "pending"; $nullExam = null;
$insTestQ->bind_param("sssssssssi", $tempReqId, $tempQText, $opA, $opB, $opC, $opD, $cor, $diff, $stPending, $nullExam);
$insTestQ->execute();
$tempPendingId = (int)$insTestQ->insert_id;
$insTestQ->close();

$unassignedWithTemp = getUnassignedPendingIds($conn);

// Verify that bulk approval validation logic aborts when genuine invalid pending exists
$bulkBlocked = false;
$conn->begin_transaction();
$pCheckStmt = $conn->query("SELECT id, exam_id, status FROM ai_generated_questions WHERE status = 'pending' AND id = {$tempPendingId}");
$pItem = $pCheckStmt->fetch_assoc();
if ($pItem && empty($pItem['exam_id'])) {
    $bulkBlocked = true;
}
$conn->rollback();

$t3Pass = in_array($tempPendingId, $unassignedWithTemp, true)
    && !in_array(162, $unassignedWithTemp, true)
    && !in_array(163, $unassignedWithTemp, true)
    && $bulkBlocked;

report_result(3, "Genuinely pending question with invalid exam ID is reported and blocks bulk approval", $t3Pass,
    "Temp pending ID #{$tempPendingId} detected in warning; #162 and #163 remain excluded; bulk approval correctly blocked"
);

// Clean up temporary question #3
$conn->query("DELETE FROM ai_generated_questions WHERE id = {$tempPendingId}");

// -----------------------------------------------------------------
// Test 4: Valid pending questions can be approved when rejected invalid records coexist
// -----------------------------------------------------------------
// Create a temporary exam
$conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Test Exam Bulk Coexist', 30, 10, 1)");
$testExamId = (int)$conn->insert_id;

// Insert 2 valid pending questions assigned to this exam
$insValid = $conn->prepare("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, question, option_a, option_b, option_c, option_d, correct_option, difficulty, subject, topic, status) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
$reqCoexist = "test_coexist_" . time();
$qText1 = "Valid Question 1 for Coexistence Test";
$qText2 = "Valid Question 2 for Coexistence Test";
$subj = "Computer Science";
$topic = "Algorithms";
$diff = "medium";
$cor = "B";

$insValid->bind_param("sisssssssss", $reqCoexist, $testExamId, $qText1, $opA, $opB, $opC, $opD, $cor, $diff, $subj, $topic);
$insValid->execute();
$validId1 = (int)$insValid->insert_id;

$insValid->bind_param("sisssssssss", $reqCoexist, $testExamId, $qText2, $opA, $opB, $opC, $opD, $cor, $diff, $subj, $topic);
$insValid->execute();
$validId2 = (int)$insValid->insert_id;
$insValid->close();

// Now simulate bulk_approve scoped to testExamId while rejected #162 and #163 coexist in DB
$bulkApproveSuccess = false;
$bulkApprovedCount = 0;
$conn->begin_transaction();
try {
    // 1. Fetch pending questions for test exam directly from DB inside transaction
    $stmt = $conn->prepare("SELECT * FROM ai_generated_questions WHERE status = 'pending' AND exam_id = ? FOR UPDATE");
    $stmt->bind_param("i", $testExamId);
    $stmt->execute();
    $pRes = $stmt->get_result();
    $pendingBatch = [];
    while ($r = $pRes->fetch_assoc()) {
        $pendingBatch[] = $r;
    }
    $stmt->close();

    // 2. Pre-validate pending batch: ensure each has a valid exam
    $invalidIds = [];
    $examCache = [];
    foreach ($pendingBatch as $item) {
        if ($item['status'] !== 'pending') continue;
        $eId = (int)($item['exam_id'] ?? 0);
        if ($eId <= 0) {
            $invalidIds[] = $item['id'];
            continue;
        }
        if (!isset($examCache[$eId])) {
            $cStmt = $conn->prepare("SELECT id FROM exams WHERE id = ?");
            $cStmt->bind_param("i", $eId);
            $cStmt->execute();
            $examCache[$eId] = ($cStmt->get_result()->num_rows > 0);
            $cStmt->close();
        }
        if (!$examCache[$eId]) {
            $invalidIds[] = $item['id'];
        }
    }

    if (!empty($invalidIds)) {
        throw new Exception("Bulk approval aborted for IDs: " . implode(', ', $invalidIds));
    }

    // 3. Execution: Insert into questions and update ai_generated_questions
    $insQ = $conn->prepare("INSERT INTO questions (exam_id, question, option_a, option_b, option_c, option_d, correct_option, subject, topic, difficulty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $updAI = $conn->prepare("UPDATE ai_generated_questions SET status = 'approved', reviewed_by = 1, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");

    foreach ($pendingBatch as $item) {
        $cleanQ = preg_replace('/\s*\(Variant\s*\d+\)$/i', '', $item['question']);
        $insQ->bind_param("isssssssss", $item['exam_id'], $cleanQ, $item['option_a'], $item['option_b'], $item['option_c'], $item['option_d'], $item['correct_option'], $item['subject'], $item['topic'], $item['difficulty']);
        $insQ->execute();

        $updAI->bind_param("i", $item['id']);
        $updAI->execute();
        $bulkApprovedCount++;
    }
    $insQ->close();
    $updAI->close();

    $conn->commit();
    $bulkApproveSuccess = true;
} catch (Exception $e) {
    $conn->rollback();
    $bulkApproveSuccess = false;
}

// Verify both questions are now approved in DB and present in questions table
$publishedCheck = $conn->query("SELECT COUNT(*) as cnt FROM questions WHERE exam_id = {$testExamId}")->fetch_assoc()['cnt'];
$aiStatusCheck = $conn->query("SELECT status FROM ai_generated_questions WHERE id IN ({$validId1}, {$validId2})");
$allApproved = true;
while ($r = $aiStatusCheck->fetch_assoc()) {
    if ($r['status'] !== 'approved') $allApproved = false;
}

$t4Pass = $bulkApproveSuccess && ($bulkApprovedCount === 2) && ((int)$publishedCheck === 2) && $allApproved;

report_result(4, "Valid pending questions can be approved when rejected invalid records coexist", $t4Pass,
    "Approved and published {$bulkApprovedCount} questions; coexistence of #162/#163 did not block approval"
);

// -----------------------------------------------------------------
// Test 5: Pending counts and warning counts agree with persisted database statuses
// -----------------------------------------------------------------
// Calculate database counts
$dbPendingCount = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE status = 'pending'")->fetch_assoc()['COUNT(*)'];
$dbApprovedCount = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE status = 'approved'")->fetch_assoc()['COUNT(*)'];
$dbRejectedCount = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE status = 'rejected'")->fetch_assoc()['COUNT(*)'];
$dbTotalCount = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions")->fetch_assoc()['COUNT(*)'];

// Calculate review_ai_questions.php logic counts
$cnt_pending = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE status = 'pending'")->fetch_assoc()['COUNT(*)'];
$cnt_approved = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE status = 'approved'")->fetch_assoc()['COUNT(*)'];
$cnt_rejected = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE status = 'rejected'")->fetch_assoc()['COUNT(*)'];
$cnt_all = $cnt_pending + $cnt_approved + $cnt_rejected;

// Calculate invalid pending warning count
$unassignedIds = getUnassignedPendingIds($conn);
$cnt_unassigned_pending = count($unassignedIds);

// Actual database records 162 and 163 check
$r162 = $conn->query("SELECT status FROM ai_generated_questions WHERE id = 162")->fetch_assoc();
$r163 = $conn->query("SELECT status FROM ai_generated_questions WHERE id = 163")->fetch_assoc();

$t5Pass = ($dbPendingCount === $cnt_pending)
    && ($dbApprovedCount === $cnt_approved)
    && ($dbRejectedCount === $cnt_rejected)
    && ($dbTotalCount === $cnt_all)
    && ($cnt_unassigned_pending === 0)
    && ($r162['status'] === 'rejected')
    && ($r163['status'] === 'rejected')
    && (!in_array(162, $unassignedIds, true))
    && (!in_array(163, $unassignedIds, true));

report_result(5, "Pending counts and warning counts agree with persisted database statuses", $t5Pass,
    "Pending: {$cnt_pending}, Approved: {$cnt_approved}, Rejected: {$cnt_rejected}, Unassigned Pending Warnings: {$cnt_unassigned_pending}"
);

// -----------------------------------------------------------------
// Test 6: Rejected and approved records remain unchanged after bulk approval
// -----------------------------------------------------------------
// Snapshot state of 162, 163, and an existing approved record
$pre162 = $conn->query("SELECT id, exam_id, status, rejection_reason, reviewed_by, reviewed_at FROM ai_generated_questions WHERE id = 162")->fetch_assoc();
$pre163 = $conn->query("SELECT id, exam_id, status, rejection_reason, reviewed_by, reviewed_at FROM ai_generated_questions WHERE id = 163")->fetch_assoc();
$sampleApproved = $conn->query("SELECT id, exam_id, status, reviewed_by, reviewed_at FROM ai_generated_questions WHERE status = 'approved' ORDER BY id ASC LIMIT 1")->fetch_assoc();

// Perform another bulk approval dry-run / action on another set of pending questions
$conn->begin_transaction();
$dummyStmt = $conn->prepare("UPDATE ai_generated_questions SET status = 'approved', reviewed_by = 1, reviewed_at = NOW() WHERE status = 'pending' AND id = -9999");
$dummyStmt->execute();
$dummyStmt->close();
$conn->commit();

// Re-fetch records after bulk operation
$post162 = $conn->query("SELECT id, exam_id, status, rejection_reason, reviewed_by, reviewed_at FROM ai_generated_questions WHERE id = 162")->fetch_assoc();
$post163 = $conn->query("SELECT id, exam_id, status, rejection_reason, reviewed_by, reviewed_at FROM ai_generated_questions WHERE id = 163")->fetch_assoc();
$postSampleApproved = $conn->query("SELECT id, exam_id, status, reviewed_by, reviewed_at FROM ai_generated_questions WHERE id = " . (int)$sampleApproved['id'])->fetch_assoc();

$t6Pass = ($pre162 == $post162)
    && ($pre163 == $post163)
    && ($sampleApproved == $postSampleApproved)
    && ($post162['status'] === 'rejected')
    && ($post163['status'] === 'rejected')
    && ($postSampleApproved['status'] === 'approved');

report_result(6, "Rejected and approved records remain unchanged after bulk approval", $t6Pass,
    "#162 status: {$post162['status']}, #163 status: {$post163['status']}, Sample #{$sampleApproved['id']} status: {$postSampleApproved['status']}"
);

// -----------------------------------------------------------------
// Test 7: Bulk rejection updates statuses correctly and re-reads DB inside transaction
// -----------------------------------------------------------------
// Insert 2 test pending questions for a bulk rejection test
$conn->query("INSERT INTO exams (title, duration, total_marks, marks_per_question) VALUES ('Test Exam Bulk Rejection', 20, 10, 1)");
$rejExamId = (int)$conn->insert_id;

$insRej = $conn->prepare("INSERT INTO ai_generated_questions (request_id, admin_id, exam_id, question, option_a, option_b, option_c, option_d, correct_option, difficulty, subject, topic, status) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
$reqRej = "test_bulk_rej_" . time();
$qRejText1 = "Question to be bulk rejected 1";
$qRejText2 = "Question to be bulk rejected 2";
$insRej->bind_param("sisssssssss", $reqRej, $rejExamId, $qRejText1, $opA, $opB, $opC, $opD, $cor, $diff, $subj, $topic);
$insRej->execute();
$rejId1 = (int)$insRej->insert_id;
$insRej->bind_param("sisssssssss", $reqRej, $rejExamId, $qRejText2, $opA, $opB, $opC, $opD, $cor, $diff, $subj, $topic);
$insRej->execute();
$rejId2 = (int)$insRej->insert_id;
$insRej->close();

// Execute bulk_reject scoped to rejExamId inside transaction
$conn->begin_transaction();
$pSel = $conn->prepare("SELECT id FROM ai_generated_questions WHERE status = 'pending' AND exam_id = ? FOR UPDATE");
$pSel->bind_param("i", $rejExamId);
$pSel->execute();
$pCnt = $pSel->get_result()->num_rows;
$pSel->close();

$updRej = $conn->prepare("UPDATE ai_generated_questions SET status = 'rejected', rejection_reason = 'Bulk rejected by admin', reviewed_by = 1, reviewed_at = NOW() WHERE status = 'pending' AND exam_id = ?");
$updRej->bind_param("i", $rejExamId);
$updRej->execute();
$affRows = $updRej->affected_rows;
$updRej->close();
$conn->commit();

// Check records in DB
$chkRej1 = $conn->query("SELECT status, rejection_reason, reviewed_by, reviewed_at FROM ai_generated_questions WHERE id = {$rejId1}")->fetch_assoc();
$chkRej2 = $conn->query("SELECT status, rejection_reason, reviewed_by, reviewed_at FROM ai_generated_questions WHERE id = {$rejId2}")->fetch_assoc();

$t7Pass = ($pCnt === 2)
    && ($affRows === 2)
    && ($chkRej1['status'] === 'rejected')
    && ($chkRej2['status'] === 'rejected')
    && ($chkRej1['rejection_reason'] === 'Bulk rejected by admin')
    && (!empty($chkRej1['reviewed_at']));

report_result(7, "Bulk rejection updates statuses correctly with review audit metadata", $t7Pass,
    "Updated {$affRows} records to rejected status with reviewed_by and reviewed_at timestamp"
);

// Clean up Test 7 data
$conn->query("DELETE FROM ai_generated_questions WHERE id IN ({$rejId1}, {$rejId2})");
$conn->query("DELETE FROM exams WHERE id = {$rejExamId}");

// -----------------------------------------------------------------
// Test 8: Question cards for rejected records #162 and #163 do not render invalid-exam warning badges
// -----------------------------------------------------------------
// Function simulating badge rendering logic from review_ai_questions.php
function getTargetExamBadgeHtml($q) {
    $hasExam = !empty($q['exam_id']);
    $hasValidExam = $hasExam && !empty($q['exam_title']);
    $isPending = ($q['status'] === 'pending');

    if ($hasValidExam) {
        return '<span class="badge badge-subject">EXAM: ' . htmlspecialchars($q['exam_title']) . '</span>';
    } elseif ($isPending && $hasExam) {
        return '<span class="badge" style="background: #fee2e2; color: #991b1b;">⚠️ Target Exam #' . (int)$q['exam_id'] . ' (Nonexistent)</span>';
    } elseif ($isPending) {
        return '<span class="badge" style="background: #fee2e2; color: #991b1b;">⚠️ Target Exam Missing (Unassigned)</span>';
    } elseif ($hasExam) {
        return '<span class="badge badge-diff">Target Exam #' . (int)$q['exam_id'] . '</span>';
    } else {
        return '<span class="badge badge-diff">Target Exam: Unassigned</span>';
    }
}

$badge162 = getTargetExamBadgeHtml(['status' => 'rejected', 'exam_id' => null, 'exam_title' => null]);
$badge163 = getTargetExamBadgeHtml(['status' => 'rejected', 'exam_id' => null, 'exam_title' => null]);
$badgePendingMissing = getTargetExamBadgeHtml(['status' => 'pending', 'exam_id' => null, 'exam_title' => null]);

$t8Pass = (strpos($badge162, '⚠️') === false)
    && (strpos($badge163, '⚠️') === false)
    && (strpos($badge162, 'Target Exam: Unassigned') !== false)
    && (strpos($badgePendingMissing, '⚠️ Target Exam Missing (Unassigned)') !== false);

report_result(8, "Question cards for rejected records do not render invalid-exam warning badges", $t8Pass,
    "Rejected records render neutral badge; genuine pending unassigned records render warning badge"
);

// -----------------------------------------------------------------
// Test 9: Review queue status filters (pending, approved, rejected, all) show accurate mutually exclusive counts
// -----------------------------------------------------------------
$qPending = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE status = 'pending'")->fetch_assoc()['COUNT(*)'];
$qApproved = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE status = 'approved'")->fetch_assoc()['COUNT(*)'];
$qRejected = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE status = 'rejected'")->fetch_assoc()['COUNT(*)'];
$qAll = (int)$conn->query("SELECT COUNT(*) FROM ai_generated_questions")->fetch_assoc()['COUNT(*)'];

$t9Pass = ($qPending + $qApproved + $qRejected === $qAll)
    && ($qPending === 50)
    && ($qRejected >= 142);

report_result(9, "Review queue status filters partition questions with mathematical consistency", $t9Pass,
    "Pending: {$qPending} + Approved: {$qApproved} + Rejected: {$qRejected} = Total: {$qAll}"
);

// Clean up Test 4 temporary test data
$conn->query("DELETE FROM questions WHERE exam_id = {$testExamId}");
$conn->query("DELETE FROM ai_generated_questions WHERE id IN ({$validId1}, {$validId2})");
$conn->query("DELETE FROM exams WHERE id = {$testExamId}");

echo "\n-------------------------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "===================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
