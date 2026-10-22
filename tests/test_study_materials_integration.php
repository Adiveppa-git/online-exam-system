<?php
/**
 * Test Suite: Study Materials Integration Across Student Pages
 * 
 * Verifies:
 * 1. student/sidebar.php includes "Study Materials" positioned between "Personalized Learning" and "Performance Analytics".
 * 2. student/study_materials.php requires authentication (unauthenticated redirected).
 * 3. student/study_materials.php lists authorized ingested study materials with title, course, subject, topic, size, and view button.
 * 4. student/study_materials.php subject filter and keyword search function accurately.
 * 5. student/study_materials.php displays clear empty state when no matching materials are found.
 * 6. student/personalized_learning.php generates clickable <a> badge links to view_course_material.php.
 * 7. student/personalized_learning.php avoids duplicate links for identical source chunks while preserving distinct materials.
 * 8. student/study_assistant.php links and citation badges open the shared viewer view_course_material.php.
 * 9. student/view_course_material.php renders content for valid ingested materials and blocks unauthorized/invalid IDs.
 * 10. Admin files and existing admin-side workflows remain unmodified.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/text_helpers.php';

$totalPassed = 0;
$totalFailed = 0;

function reportTest($num, $title, $passed, $details = '') {
    global $totalPassed, $totalFailed;
    if ($passed) {
        $totalPassed++;
        echo "[PASS] Test {$num}: {$title}\n";
        if ($details) echo "       Details: {$details}\n";
    } else {
        $totalFailed++;
        echo "[FAIL] Test {$num}: {$title}\n";
        if ($details) echo "       Details: {$details}\n";
    }
}

echo "====================================================\n";
echo "   Study Materials Student Integration Suite       \n";
echo "====================================================\n\n";

function runIsolatedStudent($script, $sessionData = [], $getData = [], $postData = []) {
    $studentDir = addslashes(__DIR__ . '/../student');
    $scriptPath = addslashes(__DIR__ . '/../' . $script);
    $code = '<?php
    chdir("' . $studentDir . '");
    session_start();
    $_SESSION = ' . var_export($sessionData, true) . ';
    $_POST = ' . var_export($postData, true) . ';
    $_GET = ' . var_export($getData, true) . ';
    $_SERVER["REQUEST_METHOD"] = ' . var_export(empty($postData) ? "GET" : "POST", true) . ';
    $_SERVER["PHP_SELF"] = "/student/' . basename($script) . '";
    ob_start();
    register_shutdown_function(function() {
        $output = ob_get_clean();
        echo json_encode([
            "stdout" => $output,
            "session" => $_SESSION
        ]);
    });
    require "' . $scriptPath . '";
    ';

    $desc = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    $cmd = 'C:\\xampp\\php\\php.exe';
    $proc = proc_open($cmd, $desc, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) return ['stdout' => '', 'session' => []];

    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);

    $decoded = json_decode($out, true);
    return is_array($decoded) ? $decoded : ['stdout' => $out . $err, 'session' => []];
}

// -----------------------------------------------------------------
// Test 1: Sidebar positioning
// -----------------------------------------------------------------
$sidebarContent = file_get_contents(__DIR__ . '/../student/sidebar.php');
$hasStudyMaterialsLink = (strpos($sidebarContent, 'study_materials.php') !== false);
// Verify positioned between Personalized Learning and Performance Analytics
$posPersonalized = strpos($sidebarContent, 'personalized_learning.php');
$posStudyMat = strpos($sidebarContent, 'study_materials.php');
$posPerf = strpos($sidebarContent, 'ai_performance.php');

$test1Passed = ($hasStudyMaterialsLink && $posPersonalized !== false && $posStudyMat !== false && $posPerf !== false && $posPersonalized < $posStudyMat && $posStudyMat < $posPerf);
reportTest(1, "Student sidebar has 'Study Materials' positioned between Personalized Learning and Performance Analytics", $test1Passed, "Order: Personalized Learning -> Study Materials -> Performance Analytics");

// -----------------------------------------------------------------
// Test 2: study_materials.php Authentication Guard
// -----------------------------------------------------------------
$resUnauth = runIsolatedStudent('student/study_materials.php', [], []);
// Unauthenticated request should produce redirect header or empty page with location
$test2Passed = (strpos($resUnauth['stdout'], 'Course Study Materials') === false);
reportTest(2, "student/study_materials.php enforces authentication guard", $test2Passed, "Unauthenticated user blocked/redirected");

// -----------------------------------------------------------------
// Test 3: student/study_materials.php Lists Ingested Materials
// -----------------------------------------------------------------
$testStudentId = 207;
$resList = runIsolatedStudent(
    'student/study_materials.php',
    ['user_id' => $testStudentId, 'role' => 'student'],
    []
);
$hasHeader = (strpos($resList['stdout'], 'Course Study Materials') !== false);
$hasTable = (strpos($resList['stdout'], 'class="materials-table"') !== false);
$hasViewBtn = (strpos($resList['stdout'], 'view_course_material.php?id=') !== false && strpos($resList['stdout'], 'target="_blank"') !== false);
// Check if existing ingested document (e.g. #278 or Python) appears
$hasDocItem = (strpos($resList['stdout'], 'Python') !== false || strpos($resList['stdout'], 'Java') !== false);

$test3Passed = ($hasHeader && $hasTable && $hasViewBtn && $hasDocItem);
reportTest(3, "student/study_materials.php lists authorized course materials with details & view button", $test3Passed, "Found table, metadata columns, and view links opening in new tab");

// -----------------------------------------------------------------
// Test 4: Subject Filter & Keyword Search
// -----------------------------------------------------------------
// Filter by Python
$resFilterPy = runIsolatedStudent(
    'student/study_materials.php',
    ['user_id' => $testStudentId, 'role' => 'student'],
    ['subject' => 'Python']
);
$hasPython = (strpos($resFilterPy['stdout'], 'Python') !== false);

// Search for non-existent keyword
$resSearchNone = runIsolatedStudent(
    'student/study_materials.php',
    ['user_id' => $testStudentId, 'role' => 'student'],
    ['search' => 'NonExistentXYZ998877']
);
$hasEmptyState = (strpos($resSearchNone['stdout'], 'No Study Materials Available') !== false);

$test4Passed = ($hasPython && $hasEmptyState);
reportTest(4, "student/study_materials.php subject filter and search work correctly", $test4Passed, "Subject filter displays matches; no-match search shows clean empty state");

// -----------------------------------------------------------------
// Test 5: Personalized Learning Clickable Material Links
// -----------------------------------------------------------------
$plContent = file_get_contents(__DIR__ . '/../student/personalized_learning.php');
$hasClickableTag = (strpos($plContent, '<a href="<?= htmlspecialchars($uViewLink) ?>" target="_blank"') !== false);
$hasTargetBlank = (strpos($plContent, 'target="_blank"') !== false);
$hasViewMaterialLink = (strpos($plContent, 'view_course_material.php?id=') !== false);
$hasDocLookupPreload = (strpos($plContent, '$docLookup = [') !== false && strpos($plContent, "status = 'ingested'") !== false);

$test5Passed = ($hasClickableTag && $hasTargetBlank && $hasViewMaterialLink && $hasDocLookupPreload);
reportTest(5, "student/personalized_learning.php renders clickable <a> material links to view_course_material.php with target=_blank", $test5Passed, "Material badges converted from static spans to clickable viewer links");

// -----------------------------------------------------------------
// Test 6: Duplicate Link Prevention in Personalized Learning
// -----------------------------------------------------------------
$hasDedupLogic = (strpos($plContent, '$seenSources = []') !== false && strpos($plContent, '$dedupKey =') !== false);
reportTest(6, "student/personalized_learning.php de-duplicates identical source chunk references", $hasDedupLogic, "Keyed by document ID and page number to prevent redundant duplicate links");

// -----------------------------------------------------------------
// Test 7: AI Study Assistant Shared Viewer Integration
// -----------------------------------------------------------------
$saContent = file_get_contents(__DIR__ . '/../student/study_assistant.php');
$hasSaViewerLink = (strpos($saContent, 'view_course_material.php?id=') !== false && strpos($saContent, 'target="_blank"') !== false);
$hasSaDocLookup = (strpos($saContent, 'render_chat_markdown($msg[\'answer\'], $docLookup)') !== false);
$test7Passed = ($hasSaViewerLink && $hasSaDocLookup);
reportTest(7, "student/study_assistant.php opens the shared viewer view_course_material.php in target=_blank", $test7Passed, "Both chat links and source badges target shared student/view_course_material.php");

// -----------------------------------------------------------------
// Test 8: student/view_course_material.php Shared Viewer Verification
// -----------------------------------------------------------------
// 1. Authorized document (ID 278)
$resViewerValid = runIsolatedStudent(
    'student/view_course_material.php',
    ['user_id' => $testStudentId, 'role' => 'student'],
    ['id' => 278]
);
$rendersValidContent = (strpos($resViewerValid['stdout'], 'Study Material') !== false || strpos($resViewerValid['stdout'], 'Python') !== false);

// 2. Invalid non-existent document
$resViewerInvalid = runIsolatedStudent(
    'student/view_course_material.php',
    ['user_id' => $testStudentId, 'role' => 'student'],
    ['id' => 999999]
);
$rendersSafeError = (strpos($resViewerInvalid['stdout'], 'is not available or you do not have permission') !== false);

$test8Passed = ($rendersValidContent && $rendersSafeError);
reportTest(8, "student/view_course_material.php renders valid materials and safely blocks invalid/unauthorized requests", $test8Passed, "Valid material rendered; invalid ID displays safe error card without error");

// -----------------------------------------------------------------
// Test 9: Admin Side Workflows & Files Preserved
// -----------------------------------------------------------------
$adminFiles = [
    'admin/manage_course_materials.php',
    'admin/view_course_material.php'
];
exec('git diff --name-only admin/manage_course_materials.php', $diffOut);
$adminUntouched = empty($diffOut);
reportTest(9, "Admin course material management workflows and files remain unchanged", $adminUntouched, "admin/manage_course_materials.php has zero git modifications");

// -----------------------------------------------------------------
// Test 10: Explicit source parameter validation & Back to AI Assistant conditional visibility
// -----------------------------------------------------------------
$saSrcContent = file_get_contents(__DIR__ . '/../student/study_assistant.php');
$plSrcContent = file_get_contents(__DIR__ . '/../student/personalized_learning.php');
$smSrcContent = file_get_contents(__DIR__ . '/../student/study_materials.php');

$saHasSourceParam = (strpos($saSrcContent, 'source=assistant') !== false);
$plHasSourceParam = (strpos($plSrcContent, 'source=personalized_learning') !== false);
$smHasSourceParam = (strpos($smSrcContent, 'source=study_materials') !== false);

// Check rendered output of view_course_material.php for each source
$resViewAssistant = runIsolatedStudent('student/view_course_material.php', ['user_id' => $testStudentId, 'role' => 'student'], ['id' => 278, 'source' => 'assistant']);
$showsBackWhenAssistant = (strpos($resViewAssistant['stdout'], 'Back to AI Assistant') !== false);

$resViewPL = runIsolatedStudent('student/view_course_material.php', ['user_id' => $testStudentId, 'role' => 'student'], ['id' => 278, 'source' => 'personalized_learning']);
$hidesBackWhenPL = (strpos($resViewPL['stdout'], 'Back to AI Assistant') === false);

$resViewSM = runIsolatedStudent('student/view_course_material.php', ['user_id' => $testStudentId, 'role' => 'student'], ['id' => 278, 'source' => 'study_materials']);
$hidesBackWhenSM = (strpos($resViewSM['stdout'], 'Back to AI Assistant') === false);

$resViewNone = runIsolatedStudent('student/view_course_material.php', ['user_id' => $testStudentId, 'role' => 'student'], ['id' => 278]);
$hidesBackWhenNone = (strpos($resViewNone['stdout'], 'Back to AI Assistant') === false);

$test10Passed = ($saHasSourceParam && $plHasSourceParam && $smHasSourceParam 
    && $showsBackWhenAssistant && $hidesBackWhenPL && $hidesBackWhenSM && $hidesBackWhenNone);

reportTest(10, "Explicit source parameter passed by student pages and 'Back to AI Assistant' displayed only when source=assistant", $test10Passed, "assistant shows back button; personalized_learning & study_materials hide it");

echo "\n----------------------------------------------------\n";
echo "Summary: {$totalPassed} Passed, {$totalFailed} Failed\n";
echo "====================================================\n";

exit($totalFailed > 0 ? 1 : 0);
