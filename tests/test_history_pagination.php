<?php
/**
 * Test Suite: History Question List Pagination and Filter Preservation
 * Target: admin/ai_question_generator.php
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SESSION['csrf_token'] = 'test_token_123';

require_once __DIR__ . "/../config/db.php";

echo "========================================================\n";
echo "TEST SUITE: AI Question History Pagination & Filter Tests\n";
echo "========================================================\n\n";

$allPassed = true;

function assertCondition($name, $condition, $details = '') {
    global $allPassed;
    if ($condition) {
        echo " [PASS] $name\n";
        if ($details) echo "        -> $details\n";
    } else {
        echo " [FAIL] $name\n";
        if ($details) echo "        -> $details\n";
        $allPassed = false;
    }
}

// 1. Identify File
$targetFile = realpath(__DIR__ . "/../admin/ai_question_generator.php");
assertCondition("1. Responsible PHP file identified", file_exists($targetFile) && basename($targetFile) === 'ai_question_generator.php', $targetFile);

// Helper mimicking pagination query logic from ai_question_generator.php
function execute_pagination_query($conn, $examId, $subject = '', $status = 'all', $search = '', $rawPage = 1, $limit = 10) {
    $whereClauses = ["gq.exam_id = ?"];
    $whereParams = [$examId];
    $whereTypes = "i";

    if ($subject !== '') {
        $whereClauses[] = "gq.subject = ?";
        $whereParams[] = $subject;
        $whereTypes .= "s";
    }

    if ($status !== 'all') {
        $whereClauses[] = "gq.status = ?";
        $whereParams[] = $status;
        $whereTypes .= "s";
    }

    if ($search !== '') {
        $whereClauses[] = "(gq.question LIKE ? OR gq.topic LIKE ?)";
        $searchWild = '%' . $search . '%';
        $whereParams[] = $searchWild;
        $whereParams[] = $searchWild;
        $whereTypes .= "ss";
    }

    $whereSql = "WHERE " . implode(" AND ", $whereClauses);

    // Count
    $cntStmt = $conn->prepare("SELECT COUNT(*) AS total FROM ai_generated_questions gq {$whereSql}");
    $cntStmt->bind_param($whereTypes, ...$whereParams);
    $cntStmt->execute();
    $totalCount = (int)($cntStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $cntStmt->close();

    $totalPages = $totalCount > 0 ? (int)ceil($totalCount / $limit) : 0;
    $page = $totalCount > 0 ? max(1, min($totalPages, $rawPage)) : 1;
    $offset = (int)(($page - 1) * $limit);

    $questions = [];
    if ($totalCount > 0) {
        $listSql = "
            SELECT gq.*, e.title AS exam_title
            FROM ai_generated_questions gq
            LEFT JOIN exams e ON gq.exam_id = e.id
            {$whereSql}
            ORDER BY gq.id DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $conn->prepare($listSql);
        $types = $whereTypes . "ii";
        $params = array_merge($whereParams, [$limit, $offset]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $questions[] = $row;
        }
        $stmt->close();
    }

    return [
        'total_count' => $totalCount,
        'total_pages' => $totalPages,
        'page' => $page,
        'offset' => $offset,
        'questions' => $questions
    ];
}

// 2. Test Page 1 for Exam 126 (Total 240 questions)
$p1 = execute_pagination_query($conn, 126, '', 'all', '', 1, 10);
assertCondition("2. Page 1 returns exactly 10 questions and total 240 records", 
    $p1['total_count'] === 240 && $p1['total_pages'] === 24 && count($p1['questions']) === 10,
    "Count: {$p1['total_count']}, Pages: {$p1['total_pages']}, Returned: " . count($p1['questions'])
);
$p1Ids = array_column($p1['questions'], 'id');
$p1Serials = range($p1['offset'] + 1, $p1['offset'] + count($p1['questions']));
assertCondition("2b. Page 1 sequential numbering is #1 through #10", 
    $p1Serials === [1,2,3,4,5,6,7,8,9,10],
    "First: #{$p1Serials[0]}, Last: #" . end($p1Serials)
);

// Verify fields exist and are populated
$qSample = $p1['questions'][0];
assertCondition("2c. Question cards contain text, options, correct answer, difficulty, status",
    !empty($qSample['question']) && !empty($qSample['option_a']) && !empty($qSample['correct_option']) && !empty($qSample['difficulty']) && !empty($qSample['status']),
    "Q: {$qSample['question']} | Diff: {$qSample['difficulty']} | Status: {$qSample['status']}"
);

// 3. Test Middle Page (Page 12)
$p12 = execute_pagination_query($conn, 126, '', 'all', '', 12, 10);
$p12Ids = array_column($p12['questions'], 'id');
$p12Serials = range($p12['offset'] + 1, $p12['offset'] + count($p12['questions']));
$overlap1_12 = array_intersect($p1Ids, $p12Ids);
assertCondition("3. Middle Page (Page 12) returns 10 questions with offset 110 and no duplicate IDs",
    $p12['page'] === 12 && $p12['offset'] === 110 && count($p12['questions']) === 10 && empty($overlap1_12),
    "Offset: {$p12['offset']}, Serials #{$p12Serials[0]}-#" . end($p12Serials) . ", Overlap with P1: " . count($overlap1_12)
);

// 4. Test Last Page (Page 24)
$p24 = execute_pagination_query($conn, 126, '', 'all', '', 24, 10);
$p24Ids = array_column($p24['questions'], 'id');
$p24Serials = range($p24['offset'] + 1, $p24['offset'] + count($p24['questions']));
$overlap1_24 = array_intersect($p1Ids, $p24Ids);
$overlap12_24 = array_intersect($p12Ids, $p24Ids);
assertCondition("4. Last Page (Page 24) returns 10 questions with offset 230 and sequential #231-#240",
    $p24['page'] === 24 && $p24['offset'] === 230 && count($p24['questions']) === 10 && empty($overlap1_24) && empty($overlap12_24),
    "Offset: {$p24['offset']}, Serials #{$p24Serials[0]}-#" . end($p24Serials)
);

// 5. Test Bounds and Clamping
$pOverflow = execute_pagination_query($conn, 126, '', 'all', '', 999, 10);
assertCondition("5a. Page 999 clamps to valid last page 24",
    $pOverflow['page'] === 24 && $pOverflow['offset'] === 230 && count($pOverflow['questions']) === 10,
    "Clamped page: {$pOverflow['page']}, Returned: " . count($pOverflow['questions'])
);
$pUnderflow = execute_pagination_query($conn, 126, '', 'all', '', -5, 10);
assertCondition("5b. Page -5 clamps to valid first page 1",
    $pUnderflow['page'] === 1 && $pUnderflow['offset'] === 0 && count($pUnderflow['questions']) === 10,
    "Clamped page: {$pUnderflow['page']}, Returned: " . count($pUnderflow['questions'])
);

// 6. Test Filter Preservation (Status Filter: Pending = 50 rows -> 5 pages)
$pPending = execute_pagination_query($conn, 126, '', 'pending', '', 1, 10);
assertCondition("6a. Pending status filter calculates exact count (50) and 5 total pages (not 24)",
    $pPending['total_count'] === 50 && $pPending['total_pages'] === 5 && count($pPending['questions']) === 10,
    "Total Count: {$pPending['total_count']}, Total Pages: {$pPending['total_pages']}"
);
$allPending = true;
foreach ($pPending['questions'] as $q) {
    if ($q['status'] !== 'pending') $allPending = false;
}
assertCondition("6b. All returned records match the 'pending' status filter", $allPending);

// Test URL generator helper
ob_start();
require_once __DIR__ . "/../admin/ai_question_generator.php";
ob_end_clean();
$url = get_history_page_url(3, 126, 'English', 'pending', 'grammar');
assertCondition("6c. get_history_page_url preserves exam, page, subject, status, and search filters",
    strpos($url, 'history_exam_id=126') !== false &&
    strpos($url, 'page=3') !== false &&
    strpos($url, 'history_subject=English') !== false &&
    strpos($url, 'history_status=pending') !== false &&
    strpos($url, 'history_search=grammar') !== false,
    $url
);

// 7. Test Empty States
// Exam 125 has 0 questions
$pEmptyExam = execute_pagination_query($conn, 125, '', 'all', '', 1, 10);
assertCondition("7a. Exam 125 with 0 questions yields total_pages=0, questions=0",
    $pEmptyExam['total_count'] === 0 && $pEmptyExam['total_pages'] === 0 && count($pEmptyExam['questions']) === 0,
    "Count: {$pEmptyExam['total_count']}, Pages: {$pEmptyExam['total_pages']}"
);

// Exam 126 with non-matching search filter
$pEmptySearch = execute_pagination_query($conn, 126, '', 'all', 'NON_EXISTENT_QUERY_XYZ_12345', 1, 10);
assertCondition("7b. Filter with 0 matches yields total_pages=0 and 0 questions (NOT 24 pages!)",
    $pEmptySearch['total_count'] === 0 && $pEmptySearch['total_pages'] === 0 && count($pEmptySearch['questions']) === 0,
    "Count: {$pEmptySearch['total_count']}, Pages: {$pEmptySearch['total_pages']}"
);

// 8. End-to-End Render Simulation
ob_start();
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SESSION['csrf_token'] = 'test_token_123';
$_GET['history_exam_id'] = 126;
$_GET['page'] = 2;
$_GET['history_status'] = 'pending';
include $targetFile;
$renderedHtml = ob_get_clean();

assertCondition("8a. Rendered Page 2 displays questions #11 to #20 with pending badge",
    strpos($renderedHtml, '#11') !== false &&
    strpos($renderedHtml, '#20') !== false &&
    strpos($renderedHtml, 'PENDING') !== false,
    "Page 2 contains '#11', '#20', 'PENDING'"
);
assertCondition("8b. Rendered pagination preserves status=pending on page links",
    strpos($renderedHtml, 'history_status=pending') !== false &&
    strpos($renderedHtml, 'page=1') !== false &&
    strpos($renderedHtml, 'page=3') !== false,
    "Links preserve history_status=pending"
);

// Render empty state with non-matching filter
ob_start();
$_GET['history_exam_id'] = 126;
$_GET['page'] = 1;
$_GET['history_search'] = 'NON_EXISTENT_QUERY_XYZ_12345';
include $targetFile;
$emptyRenderHtml = ob_get_clean();

assertCondition("8c. Empty filtered state renders 'No questions found for this exam.'",
    strpos($emptyRenderHtml, 'No questions found for this exam.') !== false,
    "Message displayed correctly"
);
assertCondition("8d. Empty filtered state does NOT render 24 page buttons",
    strpos($emptyRenderHtml, '&raquo; Next') === false &&
    strpos($emptyRenderHtml, 'Page <strong>') === false,
    "No pagination controls rendered when matching questions count is 0"
);

echo "\n========================================================\n";
if ($allPassed) {
    echo ">>> ALL PAGINATION & FILTER TESTS PASSED SUCCESSFULLY! <<<\n";
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
}
echo "========================================================\n";
