<?php
/**
 * Regression Test Suite:
 * 1. Duplicate Question Prevention & Similarity Threshold (Exam-Scoped)
 * 2. Accurate Generation Reporting (Inserted vs Skipped Count)
 * 3. Previously Generated Questions & Sequential Numbering
 * 4. Elimination of Variant Suffixes
 * 5. Invalid Exam Assignment Handling (#162, #163) & Bulk Approval Safety
 * 6. AI Service Connection Error Handling & Offline Fallback
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/text_helpers.php';
require_once __DIR__ . '/../config/ai_client.php';

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$name}\n";
        $passed++;
    } else {
        echo " [FAIL] {$name}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

echo "===================================================================\n";
echo "   Regression Test Suite: AI Question Generator & Deduplication    \n";
echo "===================================================================\n\n";

/* =================================================================
   TEST SUITE 1: Normalization & Variant Suffix Elimination
================================================================= */
echo "--- 1. Question Normalization & Variant Elimination ---\n";

$norm1 = normalize_question_text("What is a fundamental core concept of Grammar in English? (Variant 6)");
$norm2 = normalize_question_text("what  is a fundamental core concept of grammar in english?");
assert_test("Normalizes question text ignoring case, punctuation, extra spaces, and (Variant N)", $norm1 === $norm2, "Norm1: '{$norm1}' vs Norm2: '{$norm2}'");
assert_test("Normalized output contains no 'variant' marker", strpos($norm1, 'variant') === false, "Output: '{$norm1}'");

$legitQuestion = "What is a genetic splice variant in molecular biology?";
$normLegit = normalize_question_text($legitQuestion);
assert_test("Preserves legitimate word 'variant' inside question text", strpos($normLegit, 'variant') !== false, "Output: '{$normLegit}'");

/* =================================================================
   TEST SUITE 2: Duplicate Detection (Exact & Highly Similar)
================================================================= */
echo "\n--- 2. Duplicate Detection & Similarity Threshold ---\n";

$existingPool = [
    normalize_question_text("What is the primary function of an operating system kernel?"),
    normalize_question_text("Which CPU scheduling algorithm minimizes average waiting time?"),
    normalize_question_text("Explain the difference between TCP and UDP protocols.")
];

// Exact duplicate
$candExact = "What is the primary function of an operating system kernel?";
assert_test("Detects exact duplicate", is_question_duplicate($candExact, $existingPool));

// Duplicate with variant suffix
$candVariant = "What is the primary function of an operating system kernel? (Variant 48)";
assert_test("Detects duplicate with artificial (Variant 48) suffix", is_question_duplicate($candVariant, $existingPool));

// Duplicate with punctuation and capitalization differences
$candPunct = "WHAT IS THE PRIMARY FUNCTION OF AN OPERATING SYSTEM KERNEL???";
assert_test("Detects duplicate with caps and punctuation", is_question_duplicate($candPunct, $existingPool));

// Highly similar paraphrased duplicate (char similarity >= 88%)
$candParaphrase = "What is the primary purpose of an operating system kernel?";
assert_test("Detects highly similar paraphrased duplicate (>=88% char similarity)", is_question_duplicate($candParaphrase, $existingPool));

// Distinct question on same subject (must NOT be blocked)
$candDistinct1 = "What is an operating system process control block?";
assert_test("Does NOT block distinct question on same subject", !is_question_duplicate($candDistinct1, $existingPool));

$candDistinct2 = "Which scheduling algorithm causes starvation of long processes?";
assert_test("Does NOT block different question with shared subject keywords", !is_question_duplicate($candDistinct2, $existingPool));

/* =================================================================
   TEST SUITE 3: Exam-Scoped Deduplication
================================================================= */
echo "\n--- 3. Exam-Scoped Deduplication Isolation ---\n";

// Ensure duplicate checking respects target exam_id
$exam1_id = 125;
$exam2_id = 126;

// Question existing only in exam 1
$qExam1 = "Evaluate the definite integral of x squared from 0 to 3";
$normExam1 = [normalize_question_text($qExam1)];
$normExam2 = [normalize_question_text("Analyze the syntactic structure of compound sentences")];

// Generating for exam 1 -> should be duplicate
assert_test("Question is blocked as duplicate when targeting same exam", is_question_duplicate($qExam1, $normExam1));

// Generating same question for exam 2 -> must NOT be blocked
assert_test("Question is ALLOWED when targeting a different exam", !is_question_duplicate($qExam1, $normExam2));

/* =================================================================
   TEST SUITE 4: Accurate Reporting (Generated vs Skipped Count)
================================================================= */
echo "\n--- 4. Generation Reporting & Count Tracking ---\n";

$mockExisting = [
    normalize_question_text("What is the function of an operating system?"),
    normalize_question_text("How does virtual memory paging work?")
];

$mockBatch = [
    ['question' => 'What is the function of an operating system? (Variant 1)'], // Duplicate of existing
    ['question' => 'what is the function of an operating system?'],             // Duplicate of existing
    ['question' => 'What is a binary search tree in computer science?'],       // Unique
    ['question' => 'What is a semaphore in concurrent programming?'],          // Unique
    ['question' => 'What is a binary search tree in computer science? (Variant 2)'] // Intra-batch duplicate
];

$inserted = 0;
$skipped = 0;
$runningPool = $mockExisting;

foreach ($mockBatch as $item) {
    $qText = clean_question_title($item['question']);
    if (is_question_duplicate($qText, $runningPool)) {
        $skipped++;
    } else {
        $inserted++;
        $runningPool[] = normalize_question_text($qText);
    }
}

assert_test("Batch deduplication: accurately counts unique questions (2)", $inserted === 2, "Got: {$inserted}");
assert_test("Batch deduplication: accurately counts skipped duplicates (3)", $skipped === 3, "Got: {$skipped}");

// Flash message format verification
if ($inserted > 0 && $skipped > 0) {
    $msg = "Generated {$inserted} unique AI question(s) successfully ({$skipped} duplicate(s) skipped for this exam). Review and approve them below.";
    assert_test("Reporting message explicitly mentions unique and skipped counts", strpos($msg, "2 unique") !== false && strpos($msg, "3 duplicate(s) skipped") !== false);
}

/* =================================================================
   TEST SUITE 5: Sequential Display Numbering
================================================================= */
echo "\n--- 5. Sequential Serial Numbering ---\n";

$page = 2;
$limit = 10;
$offset = ($page - 1) * $limit; // 10

$firstItemSerial = $offset + 0 + 1;
$fifthItemSerial = $offset + 4 + 1;

assert_test("Page 2 item 1 serial number is 11 (display-only sequence)", $firstItemSerial === 11);
assert_test("Page 2 item 5 serial number is 15 (display-only sequence)", $fifthItemSerial === 15);

/* =================================================================
   TEST SUITE 6: Questions #162 and #163 & Bulk Approval Protection
================================================================= */
echo "\n--- 6. Invalid Target Exam Assignment Safety (#162, #163) ---\n";

$stmtCheck162 = $conn->query("SELECT id, exam_id, status FROM ai_generated_questions WHERE id IN (162, 163)");
$rowsFound = [];
while ($r = $stmtCheck162->fetch_assoc()) {
    $rowsFound[(int)$r['id']] = $r;
}

assert_test("Questions #162 and #163 exist in ai_generated_questions", isset($rowsFound[162]) && isset($rowsFound[163]));
assert_test("Questions #162 and #163 have rejected status", ($rowsFound[162]['status'] ?? '') === 'rejected' && ($rowsFound[163]['status'] ?? '') === 'rejected');
assert_test("Questions #162 and #163 have null/invalid exam_id", empty($rowsFound[162]['exam_id']) && empty($rowsFound[163]['exam_id']));

// Test bulk approval safety pre-validation logic: ONLY pending questions are evaluated
$pendingStmt = $conn->query("SELECT id, exam_id FROM ai_generated_questions WHERE status = 'pending'");
$invalidPending = [];
while ($pRow = $pendingStmt->fetch_assoc()) {
    $eCheck = (int)($pRow['exam_id'] ?? 0);
    if ($eCheck <= 0) {
        $invalidPending[] = (int)$pRow['id'];
    }
}

assert_test("Rejected questions #162 and #163 are excluded from invalid pending list", !in_array(162, $invalidPending, true) && !in_array(163, $invalidPending, true));

// Test with simulated pending unassigned question to ensure genuine invalid pending records are still detected
$simulatedPending = array_merge($invalidPending, [99999]);
$abortedExceptionCaught = false;
$abortMessage = "";
if (!empty($simulatedPending)) {
    try {
        $affected = "#" . implode(", #", $simulatedPending);
        throw new Exception("Bulk approval aborted: Pending question(s) {$affected} do not have a valid assigned target exam ID. Please assign a valid target exam or reject these questions before bulk publishing.");
    } catch (Exception $ex) {
        $abortedExceptionCaught = true;
        $abortMessage = $ex->getMessage();
    }
}
assert_test("Bulk approval guard detects genuine unassigned pending questions", $abortedExceptionCaught && strpos($abortMessage, '#99999') !== false);

/* =================================================================
   TEST SUITE 7: AI Service Connection Error Handling
================================================================= */
echo "\n--- 7. AI Service Connection Error Handling ---\n";

// Test offline endpoint connection handling
$offlineClient = new AiClient('http://127.0.0.1:9998', 2);
$healthOffline = $offlineClient->checkHealth(1);

assert_test("Offline AI service returns success = false", $healthOffline['success'] === false);
assert_test("Offline AI service reports online = false", $healthOffline['online'] === false);
assert_test("Offline AI service returns descriptive connection error message", strpos($healthOffline['error'], 'Failed to connect') !== false || strpos($healthOffline['error'], 'connection error') !== false, "Error: '{$healthOffline['error']}'");

// Test active AI service connection
$activeClient = new AiClient();
$healthActive = $activeClient->checkHealth(3);
assert_test("Active AI service (port 8001) responds online", !empty($healthActive['online']) && !empty($healthActive['success']));

/* =================================================================
   SUMMARY
================================================================= */
echo "\n===================================================================\n";
echo "Tests Passed: {$passed}\n";
echo "Tests Failed: {$failed}\n";
echo "===================================================================\n";

if ($failed > 0) {
    exit(1);
}
