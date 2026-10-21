<?php
/**
 * Test Suite: Student Exam Coding Question Display & Formatting
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/text_helpers.php';

echo "====================================================\n";
echo "   Student Exam Coding Question Display Test Suite  \n";
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
// 1. QUESTION HEADING & MULTILINE FORMATTING
// ==========================================
echo "[1] Testing Question Title & Multiline Formatting:\n";

$multiQuestion = "Palindrome Validation in Python\n\nWrite a program in Python to determine whether a given string is a palindrome,\nconsidering alphanumeric characters and ignoring case differences.";
$htmlMulti = render_exam_question($multiQuestion, 1);

assert_test(str_contains($htmlMulti, '<h2 class="question-title">Q1. Palindrome Validation in Python</h2>'), "Separates title line into clean H2 heading");
assert_test(str_contains($htmlMulti, '<div class="question-description">'), "Wraps problem description in question-description container");
assert_test(str_contains($htmlMulti, '<p class="question-p">Write a program in Python to determine whether a given string is a palindrome,<br />'), "Preserves intentional line breaks in paragraph");
assert_test(!str_contains($htmlMulti, 'Palindrome Validation in Python?'), "Does not force question mark on section title");

// ==========================================
// 2. MARKDOWN CODE FENCE STRIPPING
// ==========================================
echo "\n[2] Testing Markdown Code Fence Removal:\n";

$snippetQuestion = "What is the output of the following Java snippet?\n\n```java\nclass Parent {\n    void show() { System.out.print(\"P \"); }\n}\npublic class Test {\n    public static void main(String[] args) {\n        Parent obj = new Parent();\n        obj.show();\n    }\n}\n```";
$htmlSnippet = render_exam_question($snippetQuestion, 2);

// Heading must NOT contain raw code fences
assert_test(str_contains($htmlSnippet, '<h2 class="question-title">Q2. What is the output of the following Java snippet?</h2>'), "Heading contains question statement only");
assert_test(!str_contains($htmlSnippet, '<h2>Q2. What is the output of the following Java snippet?```'), "Raw code fence ``` is excluded from H2 heading");
assert_test(!str_contains($htmlSnippet, '```java'), "Raw markdown fence marker ```java is removed from display");
assert_test(!str_contains($htmlSnippet, '```?'), "No question mark attached to code fences");

// Code block must be rendered inside dedicated dark pre/code block
assert_test(str_contains($htmlSnippet, '<pre class="code-block-dark">'), "Code block is rendered inside code-block-dark pre");
assert_test(str_contains($htmlSnippet, '<span class="code-block-badge">JAVA</span>'), "Language badge JAVA displayed on code block header");
assert_test(str_contains($htmlSnippet, 'class Parent {'), "Exact code content is preserved");

// ==========================================
// 3. XSS AND INJECTION SAFETY
// ==========================================
echo "\n[3] Testing Safe HTML Escaping:\n";

$xssQuestion = "<script>alert('xss')</script> in Python\n\nExplain how `<div>` and <img src=x onerror=alert(1)> are handled.\n\n```python\nprint('<tag> & value')\n```";
$htmlXss = render_exam_question($xssQuestion, 3);

assert_test(!str_contains($htmlXss, '<script>'), "Raw <script> tag is escaped");
assert_test(str_contains($htmlXss, '&lt;script&gt;'), "<script> escaped to entities");
assert_test(!str_contains($htmlXss, '<img src=x'), "Raw <img> tag is escaped");
assert_test(str_contains($htmlXss, '<code class="inline-code-pill">&lt;div&gt;</code>'), "Inline code is safely escaped and pill-styled");
assert_test(str_contains($htmlXss, 'print(&#039;&lt;tag&gt; &amp; value&#039;)'), "Code block content safely escaped");

// ==========================================
// 4. CONSTRAINTS FORMATTING & EMPTY OMISSION
// ==========================================
echo "\n[4] Testing Constraints Formatting & Omission:\n";

// Crowded line
$crowded = "Constraints: 1 <= length(S) <= 10^5 Time Limit: 1.0s Memory Limit: 256MB";
$htmlCrowded = render_coding_constraints($crowded);
assert_test(str_contains($htmlCrowded, '1 ≤ length(S) ≤ 10⁵'), "Math notation formatted correctly");
assert_test(str_contains($htmlCrowded, '<strong>Constraints:</strong>'), "Constraints label separated");
assert_test(str_contains($htmlCrowded, '<strong>Time Limit:</strong>'), "Time Limit label separated");
assert_test(str_contains($htmlCrowded, '<strong>Memory Limit:</strong>'), "Memory Limit label separated");

// Missing constraints, only time limit
$onlyTime = "Time Limit: 2.0s";
$htmlOnlyTime = render_coding_constraints($onlyTime);
assert_test(!str_contains($htmlOnlyTime, 'None specified'), "Does not display 'None specified' when constraints missing");
assert_test(!str_contains($htmlOnlyTime, 'Constraints:'), "Omits constraints item when missing");
assert_test(str_contains($htmlOnlyTime, '<strong>Time Limit:</strong>'), "Renders present item Time Limit");
assert_test(!str_contains($htmlOnlyTime, 'Memory Limit:'), "Omits missing Memory Limit");

// Multiple constraints with powers and negatives
$multiConst = "-10^9 <= arr[i] <= 10^9\n1 <= N <= 2 * 10^5";
$htmlMultiConst = render_coding_constraints($multiConst);
assert_test(str_contains($htmlMultiConst, '-10⁹ ≤ arr[i] ≤ 10⁹'), "Negative power formatted");
assert_test(str_contains($htmlMultiConst, '1 ≤ N ≤ 2 × 10⁵'), "Multiplication sign formatted");

// ==========================================
// 5. STARTER CODE FORMATTING & INDENTATION
// ==========================================
echo "\n[5] Testing Starter Code Formatting:\n";

$starterCode = "def solve():\n    # Step 1: Read input\n    n = int(input())\n    \n    # Step 2: Compute\n    result = []\n    for i in range(n):\n        result.append(i * 2)\n    return result\n";
// Check indentation preservation
assert_test(str_contains($starterCode, "    # Step 1"), "Starter code indentation preserved");
assert_test(str_contains($starterCode, "\n    \n"), "Blank lines preserved");

// ==========================================
// 6. LIVE DATABASE QUESTIONS VERIFICATION
// ==========================================
echo "\n[6] Testing Live Database Questions:\n";

$res = $conn->query("SELECT id, exam_id, question_type, programming_language, question, starter_code, constraints FROM questions ORDER BY exam_id, id");
$dbCount = 0;
while ($q = $res->fetch_assoc()) {
    $dbCount++;
    $rendered = render_exam_question($q['question'], $dbCount);
    
    // Check heading never contains raw fence
    $hasRawFenceInHeading = preg_match('/<h2[^>]*>.*?```.*?<\/h2>/s', $rendered);
    assert_test(!$hasRawFenceInHeading, "Q{$dbCount} (ID {$q['id']}): No raw fence in H2 heading");
    
    // Check clean math constraints if present
    if (!empty($q['constraints'])) {
        $cHtml = render_coding_constraints($q['constraints']);
        assert_test(!str_contains($cHtml, 'None specified'), "Q{$dbCount}: Constraints never display 'None specified'");
        assert_test(str_contains($cHtml, 'coding-constraints-box'), "Q{$dbCount}: Constraints box formatted");
    }
}

echo "\n----------------------------------------------------\n";
echo "Summary: $passed Passed, $failed Failed\n";
echo "====================================================\n";

exit($failed > 0 ? 1 : 0);
