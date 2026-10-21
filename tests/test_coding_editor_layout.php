<?php
/**
 * Test Suite: Coding Question Layout and Answer Editor Verification
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/text_helpers.php';

echo "====================================================\n";
echo "   Coding Editor & Layout Verification Suite        \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $name, string $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $name\n";
        $passed++;
    } else {
        echo "  [FAIL] $name" . ($details ? ": $details" : "") . "\n";
        $failed++;
    }
}

// ==========================================
// 1. STARTER CODE & BOILERPLATE LOGIC
// ==========================================
echo "[1] Testing Language-Specific Boilerplate Generation:\n";

// 1.1 Python default fallback
$pyQuestion = ['programming_language' => 'Python', 'starter_code' => ''];
$pyCode = get_coding_starter_code($pyQuestion);
assert_test(str_contains($pyCode, '# Start code here'), "Python default has '# Start code here'");
assert_test(str_contains($pyCode, 'def solve():'), "Python default contains 'def solve():'");
assert_test(!str_contains($pyCode, 'pass'), "Python default does not contain 'pass'");
assert_test(!str_contains($pyCode, 'TODO'), "Python default does not contain 'TODO'");

// 1.2 Python with existing template in DB
$pyExisting = [
    'programming_language' => 'Python',
    'starter_code' => "# Solution template for Palindrome Validation in Python\ndef solve():\n    # TODO: Implement your solution here\n    pass"
];
$pyResolved = get_coding_starter_code($pyExisting);
assert_test(str_contains($pyResolved, '# Start code here'), "Python existing has '# Start code here'");
assert_test(str_contains($pyResolved, 'def solve():'), "Python existing retains 'def solve():'");
assert_test(!str_contains($pyResolved, 'TODO'), "Replaced TODO with single comment");
assert_test(!str_contains($pyResolved, 'pass'), "Replaced pass with single comment");
assert_test(!str_contains($pyResolved, '# Solution template for'), "Replaces old template header cleanly");
assert_test(substr_count($pyResolved, '# Start code here') === 1, "Exactly one placeholder comment appears");

// 1.3 Java default fallback
$javaQuestion = ['programming_language' => 'Java', 'starter_code' => ''];
$javaCode = get_coding_starter_code($javaQuestion);
assert_test(str_contains($javaCode, '// Start code here'), "Java default has '// Start code here'");
assert_test(str_contains($javaCode, 'public class Solution'), "Java default contains 'public class Solution'");
assert_test(str_contains($javaCode, 'public static void main(String[] args)'), "Java default contains 'public static void main'");
assert_test(!str_contains($javaCode, 'TODO'), "Java default does not contain 'TODO'");

// 1.4 Java with existing template in DB
$javaExisting = [
    'programming_language' => 'Java',
    'starter_code' => "// Solution template for Reverse String In-Place using Loop in Java\npublic class Solution {\n    public static void main(String[] args) {\n        // TODO: Implement solution\n    }\n}"
];
$javaResolved = get_coding_starter_code($javaExisting);
assert_test(str_contains($javaResolved, '// Start code here'), "Java existing has '// Start code here'");
assert_test(str_contains($javaResolved, 'public class Solution'), "Java existing retains 'public class Solution'");
assert_test(str_contains($javaResolved, 'public static void main'), "Java existing retains 'main()' method");
assert_test(!str_contains($javaResolved, 'TODO'), "Replaced Java TODO with single comment");
assert_test(substr_count($javaResolved, '// Start code here') === 1, "Exactly one placeholder comment appears");

// 1.5 C and C++ fallback
$cQuestion = ['programming_language' => 'c', 'starter_code' => ''];
$cCode = get_coding_starter_code($cQuestion);
assert_test(str_contains($cCode, '// Start code here'), "C default has '// Start code here'");
assert_test(str_contains($cCode, '#include <stdio.h>'), "C default retains stdio header");
assert_test(str_contains($cCode, 'int main()'), "C default retains main method");

$cppQuestion = ['programming_language' => 'cpp', 'starter_code' => ''];
$cppCode = get_coding_starter_code($cppQuestion);
assert_test(str_contains($cppCode, '// Start code here'), "C++ default has '// Start code here'");
assert_test(str_contains($cppCode, '#include <iostream>'), "C++ default retains iostream header");

// 1.6 SQL fallback
$sqlQuestion = ['programming_language' => 'SQL', 'starter_code' => ''];
$sqlCode = get_coding_starter_code($sqlQuestion);
assert_test(str_contains($sqlCode, '-- Start code here'), "SQL default has '-- Start code here'");

// 1.7 Preserving custom user boilerplate without template header
$customQ = [
    'programming_language' => 'Python',
    'starter_code' => "def custom_func(arr, k):\n    # Custom logic\n    return arr[:k]"
];
$customResolved = get_coding_starter_code($customQ);
assert_test(str_contains($customResolved, '# Start code here'), "Includes start comment with custom boilerplate");
assert_test(str_contains($customResolved, 'def custom_func(arr, k):'), "Preserves exact custom function signature");

// ==========================================
// 2. ATTEMPT EXAM SOURCE CODE CHECKS
// ==========================================
echo "\n[2] Verifying student/attempt_exam.php Source Code:\n";

$sourceAttempt = file_get_contents(__DIR__ . '/../student/attempt_exam.php');

// Confirm NO separate Starter Code block
assert_test(!str_contains($sourceAttempt, '<span>💻 Starter Code</span>'), "Removed 'Starter Code' span label");
assert_test(!str_contains($sourceAttempt, 'class="starter-code-block"'), "Removed 'starter-code-block' pre tag");
assert_test(!str_contains($sourceAttempt, 'class="btn-copy-starter"'), "Removed 'btn-copy-starter' button");

// Confirm boilerplate initialization inside answer editor
assert_test(str_contains($sourceAttempt, 'get_coding_starter_code($q)'), "Uses get_coding_starter_code(\$q) to initialize editor");
assert_test(str_contains($sourceAttempt, 'coding-answer-editor') || str_contains($sourceAttempt, 'coding-answer-textarea'), "Contains coding-answer-editor / coding-answer-textarea");

// Confirm controls directly below expanded editor
$posEditor = strpos($sourceAttempt, 'coding-answer-editor') ?: strpos($sourceAttempt, 'coding-answer-textarea');
$posToolbar = strpos($sourceAttempt, 'class="coding-toolbar"');
$posRun = strpos($sourceAttempt, 'btn-run-code');
$posCheck = strpos($sourceAttempt, 'btn-run-check');
$posCustom = strpos($sourceAttempt, 'btn-toggle-input');
$posPanel = strpos($sourceAttempt, 'class="execution-output-panel"');

assert_test($posToolbar > $posEditor, "Coding toolbar placed below the answer editor");
assert_test($posRun > $posToolbar, "Run Code button placed below editor inside toolbar");
assert_test($posCheck > $posToolbar, "Run & Check button placed inside toolbar");
assert_test($posCustom > $posToolbar, "Custom Input button placed inside toolbar");
assert_test($posPanel > $posToolbar, "Output panel placed below controls");

// Confirm navigation and submission workflow intact
assert_test(str_contains($sourceAttempt, 'showQuestion('), "showQuestion navigation intact");
assert_test(str_contains($sourceAttempt, 'saveCurrentAnswerIfTextarea()'), "saveCurrentAnswerIfTextarea intact");
assert_test(str_contains($sourceAttempt, 'markAnswered('), "markAnswered auto-save intact");
assert_test(str_contains($sourceAttempt, 'Submit Exam'), "Submit Exam button intact");
assert_test(str_contains($sourceAttempt, 'attempt_exam.css?v='), "attempt_exam.css has cache-busting version parameter");
assert_test(str_contains($sourceAttempt, 'class="coding-answer-editor coding-answer-textarea"'), "Contains both coding-answer-editor and coding-answer-textarea classes");

// ==========================================
// 3. CSS DIMENSIONS AND STYLES VERIFICATION
// ==========================================
echo "\n[3] Verifying assets/css/attempt_exam.css Rules:\n";

$sourceCss = file_get_contents(__DIR__ . '/../assets/css/attempt_exam.css');

assert_test(str_contains($sourceCss, 'max-width:980px') || str_contains($sourceCss, 'max-width: 980px'), "Question box max-width increased to 980px on desktop");
assert_test(str_contains($sourceCss, 'min-height: 350px') || str_contains($sourceCss, 'min-height: 380px'), "Editor min-height set to at least 350px");
assert_test(str_contains($sourceCss, 'height: 420px') || str_contains($sourceCss, 'height: 410px'), "Editor height set to ~420px (within 350-450px target)");
assert_test(str_contains($sourceCss, 'resize: vertical;'), "Editor allows vertical resizing");
assert_test(str_contains($sourceCss, 'white-space: pre;'), "Editor preserves indentation and formatting");
assert_test(str_contains($sourceCss, 'overflow-x: auto;'), "Editor supports horizontal scrolling for long code");

// Mobile responsive rules
assert_test(str_contains($sourceCss, 'min-height: 320px'), "Mobile min-height set appropriately in attempt_exam.css");
assert_test(str_contains($sourceCss, 'height: 360px'), "Mobile height set for smaller screens in attempt_exam.css");

// Check mobile.css and student_mobile.css
$sourceMobile = file_get_contents(__DIR__ . '/../assets/css/mobile.css');
assert_test(str_contains($sourceMobile, 'coding-answer-editor'), "mobile.css includes coding-answer-editor responsive rules");

$sourceStudentMobile = file_get_contents(__DIR__ . '/../assets/css/student_mobile.css');
assert_test(str_contains($sourceStudentMobile, 'coding-answer-editor'), "student_mobile.css includes coding-answer-editor responsive rules");

echo "\n----------------------------------------------------\n";
echo "Summary: $passed Passed, $failed Failed\n";
echo "====================================================\n";

exit($failed > 0 ? 1 : 0);
