<?php
/**
 * Test Suite: Study Material Clickable Links and Student Material Viewer
 *
 * Verifies:
 * 1. AI Assistant chat response renders clickable links for:
 *    - Document titles (e.g. "Study Notes - Python (5 Questions).md")
 *    - "Review Course Material" recommendations
 *    - Source citation badges (e.g. "Study Notes - Python.md — Page 1")
 * 2. Entire AI response card is NOT clickable; only titles and source badges are clickable.
 * 3. Links open student/view_course_material.php with target="_blank".
 * 4. student/view_course_material.php successfully renders valid ingested documents.
 * 5. Page navigation / anchor scrolling functions when page parameter is provided.
 * 6. Non-existent, invalid, or uningested (pending/failed) documents are rejected with safe error cards.
 * 7. Path traversal attempts are blocked.
 * 8. Unauthenticated requests are redirected.
 * 9. XSS sanitization prevents malicious script injection in Markdown or URLs.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/text_helpers.php';

echo "====================================================\n";
echo "  Study Material Clickable Links Test Suite         \n";
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

function runScriptIsolated(string $scriptRelativePath, array $get = [], array $session = ['role' => 'student', 'user_id' => 207]) {
    $baseDir = realpath(__DIR__ . '/..');
    $scriptFullPath = realpath($baseDir . '/' . $scriptRelativePath);

    $payload = [
        'base_dir' => dirname($scriptFullPath),
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
    chdir($data["base_dir"]);
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
// Setup: Ensure we have a valid ingested test document
// -------------------------------------------------------------
$docRow = $conn->query("SELECT * FROM ai_documents WHERE status = 'ingested' ORDER BY id ASC LIMIT 1")->fetch_assoc();
$testDocId = (int)($docRow['id'] ?? 278);
$testDocName = $docRow['original_name'] ?? 'Study Notes - Python (5 Questions).md';
$testDocFile = $docRow['filename'] ?? 'study_notes_Python_1792563154.md';

$docLookup = [
    'by_id' => [$testDocId => $docRow],
    'by_name' => [
        strtolower($testDocName) => $docRow,
        strtolower($testDocFile) => $docRow
    ]
];

// -------------------------------------------------------------
// Test 1: render_chat_markdown auto-links Document Title
// -------------------------------------------------------------
$sampleAnswer1 = "### 3. Relevant Python Study Materials\n- **Document Title:** `{$testDocName}`\n  - **Topic / Focus Area:** Core Principles\n  - **Status:** Approved";
$html1 = render_chat_markdown($sampleAnswer1, $docLookup);

$hasDocLink = (strpos($html1, 'href="view_course_material.php?id=' . $testDocId . '&amp;source=assistant"') !== false
    || strpos($html1, 'href="view_course_material.php?id=' . $testDocId . '&source=assistant"') !== false
    || strpos($html1, 'href="view_course_material.php?id=' . $testDocId . '&amp;page=1&amp;source=assistant"') !== false
    || strpos($html1, 'href="view_course_material.php?id=' . $testDocId . '&page=1"') !== false
    || strpos($html1, 'href="view_course_material.php?id=' . $testDocId . '"') !== false);
$hasTargetBlank = (strpos($html1, 'target="_blank"') !== false);
$hasNoRawScript = (strpos($html1, '<script>') === false);

report(
    "1. render_chat_markdown converts Document Title into clickable viewer link",
    ($hasDocLink && $hasTargetBlank && $hasNoRawScript),
    "Link found to view_course_material.php with target=_blank"
);

// -------------------------------------------------------------
// Test 2: render_chat_markdown auto-links Review Course Material
// -------------------------------------------------------------
$sampleAnswer2 = "### 4. Recommended Next Steps\n- **Review Course Material:** Read through `{$testDocName}` to consolidate your understanding of Core Principles.\n- **Targeted Question Practice:** Practice with 50 questions.";
$html2 = render_chat_markdown($sampleAnswer2, $docLookup);

$hasReviewLink = (strpos($html2, 'view_course_material.php?id=' . $testDocId) !== false);
$hasReviewLabelLink = (strpos($html2, '>Review Course Material:</a>') !== false || strpos($html2, '<strong>Review Course Material:</strong>') !== false);

report(
    "2. render_chat_markdown converts Review Course Material into clickable link",
    ($hasReviewLink),
    "Recommendation step contains clickable link to material"
);

// -------------------------------------------------------------
// Test 3: Markdown link rendering supports custom labels with safe URL validation
// -------------------------------------------------------------
$sampleMdLink = "Check the [Python Study Notes](view_course_material.php?id={$testDocId}&page=2) or [External Reference](https://example.com/docs).";
$htmlMd = render_chat_markdown($sampleMdLink, $docLookup);

$hasExplicitLink = (strpos($htmlMd, '<a href="view_course_material.php?id=' . $testDocId . '&amp;page=2"') !== false
    || strpos($htmlMd, '<a href="view_course_material.php?id=' . $testDocId . '&page=2"') !== false);
$hasExternalLink = (strpos($htmlMd, '<a href="https://example.com/docs"') !== false);

report(
    "3. render_chat_markdown properly renders explicit [label](url) Markdown links",
    ($hasExplicitLink && $hasExternalLink),
    "Explicit markdown links converted to <a> tags with security checks"
);

// -------------------------------------------------------------
// Test 4: XSS injection in markdown links is sanitized
// -------------------------------------------------------------
$maliciousMd = "Click here: [Attack](javascript:alert(1)) or [Data](data:text/html,<script>alert(1)</script>)";
$htmlSafe = render_chat_markdown($maliciousMd, $docLookup);

$blockedJs = (strpos($htmlSafe, 'javascript:') === false && strpos($htmlSafe, 'href="javascript') === false);
$blockedData = (strpos($htmlSafe, 'href="data:') === false);

report(
    "4. Malicious javascript: and data: URLs in markdown links are rejected",
    ($blockedJs && $blockedData),
    "XSS protection in links verified"
);

// -------------------------------------------------------------
// Test 5: student/study_assistant.php renders clickable Sources badges
// -------------------------------------------------------------
$assistantSession = [
    'role' => 'student',
    'user_id' => 207,
    'study_assistant_history' => [
        [
            'question' => 'Give me Python study materials',
            'answer' => "### 3. Relevant Python Study Materials\n- **Document Title:** `{$testDocName}`\n\n### 4. Recommended Next Steps\n- **Review Course Material:** Read through `{$testDocName}`",
            'sources' => [
                [
                    'filename' => $testDocName,
                    'page_number' => 1,
                    'document_id' => $testDocId
                ]
            ],
            'time' => '08:30 PM'
        ]
    ]
];

$resAssistant = runScriptIsolated('student/study_assistant.php', [], $assistantSession);

$hasCitationLink = (strpos($resAssistant['output'], "view_course_material.php?id={$testDocId}&page=1") !== false
    || strpos($resAssistant['output'], "view_course_material.php?id={$testDocId}&amp;page=1") !== false);
$hasCitationBadgeLinkClass = (strpos($resAssistant['output'], 'citation-badge-link') !== false);
$hasTargetBlankAssistant = (strpos($resAssistant['output'], 'target="_blank"') !== false);

// Verify entire assistant bubble is NOT an anchor tag
$hasClickableBubble = (preg_match('/<a[^>]*class="[^"]*assistant-bubble[^"]*"[^>]*>/i', $resAssistant['output']) === 1);

report(
    "5. study_assistant.php renders clickable source citation badges and doc titles",
    ($hasCitationLink && $hasCitationBadgeLinkClass && $hasTargetBlankAssistant && !$hasClickableBubble),
    "Citation badges link to view_course_material.php?id={$testDocId}&page=1 without making entire bubble clickable"
);

// -------------------------------------------------------------
// Test 6: student/view_course_material.php loads existing document
// -------------------------------------------------------------
$resViewer = runScriptIsolated('student/view_course_material.php', ['id' => $testDocId], ['role' => 'student', 'user_id' => 207]);

$containsTitle = (strpos($resViewer['output'], htmlspecialchars($testDocName)) !== false);
$containsSubject = (strpos($resViewer['output'], htmlspecialchars($docRow['subject'])) !== false);
$noError = (strpos($resViewer['output'], 'Unable to Open Study Material') === false);
$hasPrintBtn = (strpos($resViewer['output'], 'window.print()') !== false);
$hasBackBtn = (strpos($resViewer['output'], 'study_assistant.php') !== false);

report(
    "6. student/view_course_material.php successfully renders valid ingested document",
    ($resViewer['code'] === 0 && $containsTitle && $containsSubject && $noError && $hasPrintBtn && $hasBackBtn),
    "Document #{$testDocId} rendered with toolbar, metadata, and markdown body"
);

// -------------------------------------------------------------
// Test 7: Page navigation parameter handles page jumping
// -------------------------------------------------------------
$resViewerPage = runScriptIsolated('student/view_course_material.php', ['id' => $testDocId, 'page' => 2], ['role' => 'student', 'user_id' => 207]);

$hasPageBadge = (strpos($resViewerPage['output'], 'Referenced Page 2') !== false);
$hasJumpScript = (strpos($resViewerPage['output'], 'scrollIntoView') !== false);

report(
    "7. Page parameter displays referenced page badge and triggers scroll anchor",
    ($resViewerPage['code'] === 0 && $hasPageBadge && $hasJumpScript),
    "Page parameter 2 properly handled"
);

// -------------------------------------------------------------
// Test 8: Non-existent document ID produces safe error card
// -------------------------------------------------------------
$resNonExistent = runScriptIsolated('student/view_course_material.php', ['id' => 9999999], ['role' => 'student', 'user_id' => 207]);

$hasSafeMissingError = (strpos($resNonExistent['output'], 'Unable to Open Study Material') !== false
    && strpos($resNonExistent['output'], '9999999') !== false);
$hasReturnBtn = (strpos($resNonExistent['output'], 'Return to AI Assistant') !== false);

report(
    "8. Non-existent document ID returns clear, safe error card",
    ($hasSafeMissingError && $hasReturnBtn),
    "Safe error handling verified for missing document"
);

// -------------------------------------------------------------
// Test 9: Unapproved / pending documents cannot be viewed by students
// -------------------------------------------------------------
// Insert a temporary pending document
$stmtPend = $conn->prepare("INSERT INTO ai_documents (filename, original_name, file_path, file_size, subject, topic, status, uploaded_by) VALUES ('pending_test.md', 'Pending Test.md', 'uploads/course_materials/pending_test.md', 100, 'Security', 'Testing', 'pending', 1)");
$stmtPend->execute();
$pendingDocId = (int)$stmtPend->insert_id;
$stmtPend->close();

$resPending = runScriptIsolated('student/view_course_material.php', ['id' => $pendingDocId], ['role' => 'student', 'user_id' => 207]);
$isPendingBlocked = (strpos($resPending['output'], 'Unable to Open Study Material') !== false);

// Clean up
$conn->query("DELETE FROM ai_documents WHERE id = {$pendingDocId}");

report(
    "9. Students are barred from viewing pending/unapproved study materials",
    ($isPendingBlocked),
    "Only status='ingested' documents are accessible to students"
);

// -------------------------------------------------------------
// Test 10: Path traversal attempts are blocked
// -------------------------------------------------------------
$stmtTrav = $conn->prepare("INSERT INTO ai_documents (filename, original_name, file_path, file_size, subject, topic, status, uploaded_by) VALUES ('traversal_test.md', 'Traversal Test.md', '../../config/db.php', 200, 'Security', 'Testing', 'ingested', 1)");
$stmtTrav->execute();
$travDocId = (int)$stmtTrav->insert_id;
$stmtTrav->close();

$resTrav = runScriptIsolated('student/view_course_material.php', ['id' => $travDocId], ['role' => 'student', 'user_id' => 207]);
$isTravBlocked = (strpos($resTrav['output'], 'Security Violation') !== false
    || strpos($resTrav['output'], 'Unable to Open Study Material') !== false);

// Clean up
$conn->query("DELETE FROM ai_documents WHERE id = {$travDocId}");

report(
    "10. Path traversal attacks outside course materials directory are blocked",
    ($isTravBlocked),
    "Traversal guard actively prevents unauthorized file reads"
);

// -------------------------------------------------------------
// Test 11: Unauthenticated users are redirected
// -------------------------------------------------------------
$resUnauth = runScriptIsolated('student/view_course_material.php', ['id' => $testDocId], []);
$isRedirected = ($resUnauth['output'] === '' || strpos($resUnauth['output'], '<!DOCTYPE html>') === false);

report(
    "11. Unauthenticated users are redirected from the student viewer",
    ($isRedirected),
    "Authentication guard verified"
);

// -------------------------------------------------------------
// Test 12: Source parameter validation & conditional Back button
// -------------------------------------------------------------
$resAssistant = runScriptIsolated('student/view_course_material.php', ['id' => $testDocId, 'source' => 'assistant']);
$hasBackAssistant = (strpos($resAssistant['output'], 'Back to AI Assistant') !== false);

$resPL = runScriptIsolated('student/view_course_material.php', ['id' => $testDocId, 'source' => 'personalized_learning']);
$hidesBackPL = (strpos($resPL['output'], 'Back to AI Assistant') === false);

$resSM = runScriptIsolated('student/view_course_material.php', ['id' => $testDocId, 'source' => 'study_materials']);
$hidesBackSM = (strpos($resSM['output'], 'Back to AI Assistant') === false);

$resNoSource = runScriptIsolated('student/view_course_material.php', ['id' => $testDocId]);
$hidesBackNoSource = (strpos($resNoSource['output'], 'Back to AI Assistant') === false);

$resInvalidSource = runScriptIsolated('student/view_course_material.php', ['id' => $testDocId, 'source' => 'invalid_hacker_source']);
$hidesBackInvalidSource = (strpos($resInvalidSource['output'], 'Back to AI Assistant') === false);

$sourceValidationPassed = ($hasBackAssistant && $hidesBackPL && $hidesBackSM && $hidesBackNoSource && $hidesBackInvalidSource);

report(
    "12. 'Back to AI Assistant' button is rendered ONLY when source=assistant",
    ($sourceValidationPassed),
    "Shows for source=assistant; hidden for personalized_learning, study_materials, missing, and invalid"
);

echo "\n====================================================\n";
echo "  Results: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
