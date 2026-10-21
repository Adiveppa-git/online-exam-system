<?php
/**
 * Test Suite: Coding Question Line Breaks & IDE Formatting
 * Verifies that coding questions and code blocks preserve line breaks, statements on separate lines,
 * indentation, horizontal scrolling, and required CSS styling across Java, Python, C, C++, and SQL.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/text_helpers.php';

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$message}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$message}\n";
        $failed++;
    }
}

echo "====================================================\n";
echo "   Coding Question Line Breaks Verification Suite   \n";
echo "====================================================\n\n";

// ----------------------------------------------------
// 1. JAVA MULTI-CLASS AND METHOD LINE BREAKS
// ----------------------------------------------------
echo "[1] Testing Java Multi-Class & Statement Line Breaks:\n";

$rawJavaQuestion = 'What is the output of the following Java snippet?

```java
class Parent {
    void show() { System.out.print("P "); }
}
class Child extends Parent {
    void show() { System.out.print("C "); }
}
public class Test {
    public static void main(String[] args) {
        Parent obj = new Child();
        obj.show();
    }
}
```?';

$renderedJava = render_exam_question($rawJavaQuestion, 1);

assert_test(str_contains($renderedJava, 'question-code') && str_contains($renderedJava, 'code-display'), "Wrapper has .question-code and .code-display classes");
assert_test(str_contains($renderedJava, '<pre class="code-block-dark code-display">'), "Pre has .code-block-dark and .code-display classes");
assert_test(str_contains($renderedJava, 'class Parent {'), "Contains class Parent declaration");
assert_test(str_contains($renderedJava, "void show() {\n        System.out.print"), "Parent void show() statement is on its own line");
assert_test(str_contains($renderedJava, "}\n\nclass Child extends Parent {"), "Blank line exists between Parent class and Child class");
assert_test(str_contains($renderedJava, "}\n\npublic class Test {"), "Blank line exists between Child class and Test class");
assert_test(str_contains($renderedJava, "Parent obj = new Child();\n        obj.show();"), "Test main method statements each on their own line");

// ----------------------------------------------------
// 2. PYTHON NESTED INDENTATION & SPACING
// ----------------------------------------------------
echo "\n[2] Testing Python Nested Indentation & Spacing:\n";

$rawPyQuestion = 'Write a function to check if N is prime.

```python
def is_prime(n):
    if n <= 1:
        return False
    for i in range(2, int(n**0.5) + 1):
        if n % i == 0:
            return False
    return True
```';

$renderedPy = render_exam_question($rawPyQuestion, 2);

assert_test(str_contains($renderedPy, "    if n &lt;= 1:\n        return False") || str_contains($renderedPy, "    if n <= 1:\n        return False"), "Python nested 4-space and 8-space indentation preserved");
assert_test(str_contains($renderedPy, "    for i in range(2, int(n**0.5) + 1):\n        if n % i == 0:\n            return False"), "Python for loop and nested if on distinct lines");
assert_test(!str_contains($renderedPy, "```python"), "Raw ```python marker removed");

// ----------------------------------------------------
// 3. C, C++, AND SQL PRESERVATION
// ----------------------------------------------------
echo "\n[3] Testing C, C++, and SQL Snippets:\n";

$rawCQuestion = "Analyze this C snippet:\n\n```c\n#include <stdio.h>\n\nint main() {\n    printf(\"Line 1\\n\");\n    printf(\"Line 2\\n\");\n    return 0;\n}\n```";
$renderedC = render_exam_question($rawCQuestion, 3);
assert_test(str_contains($renderedC, "#include &lt;stdio.h&gt;\n\nint main()"), "C headers and main method line breaks preserved");
assert_test(str_contains($renderedC, 'printf(&quot;Line 1\n&quot;);') || str_contains($renderedC, 'printf(&quot;Line 1\\n&quot;);'), "C internal string escape \\n preserved");

$rawSqlQuestion = "Run this SQL query:\n\n```sql\nSELECT id, name\nFROM users\nWHERE active = 1\nORDER BY created_at DESC;\n```";
$renderedSql = render_exam_question($rawSqlQuestion, 4);
assert_test(str_contains($renderedSql, "SELECT id, name\nFROM users\nWHERE active = 1\nORDER BY created_at DESC;"), "SQL query clauses each on their own line preserved");

// ----------------------------------------------------
// 4. ESCAPED NEWLINE SEQUENCES (\n, \r\n) HANDLING
// ----------------------------------------------------
echo "\n[4] Testing Literal Escaped Newline Sequences (\\n, \\r\\n):\n";

$escapedQuestion = "What is the output?\\n\\n```java\\nclass Example {\\n    int x = 10;\\n}\\n```";
$renderedEscaped = render_exam_question($escapedQuestion, 5);
assert_test(str_contains($renderedEscaped, '<h2 class="question-title">Q5. What is the output?</h2>'), "Escaped \\n in question text extracted cleanly into title");
assert_test(str_contains($renderedEscaped, "class Example {\n    int x = 10;\n}"), "Escaped \\n inside code block converted to actual newlines");

$starterWithEsc = 'def solve():\n    # TODO: Implement\n    pass';
$resolvedStarter = get_coding_starter_code(['programming_language' => 'python', 'starter_code' => $starterWithEsc]);
assert_test(str_contains($resolvedStarter, "def solve():\n    # Start code here"), "Starter code with literal \\n unescaped and formatted");

// ----------------------------------------------------
// 5. CSS RULES AND SELECTORS VERIFICATION
// ----------------------------------------------------
echo "\n[5] Verifying CSS Rules in assets/css/attempt_exam.css:\n";

$cssAttempt = file_get_contents(__DIR__ . '/../assets/css/attempt_exam.css');

assert_test(str_contains($cssAttempt, '.code-display'), "CSS contains .code-display selector");
assert_test(str_contains($cssAttempt, '.code-display pre'), "CSS contains .code-display pre selector");
assert_test(str_contains($cssAttempt, '.code-display code'), "CSS contains .code-display code selector");
assert_test(str_contains($cssAttempt, '.question-code'), "CSS contains .question-code selector");
assert_test(str_contains($cssAttempt, '.question-code pre'), "CSS contains .question-code pre selector");
assert_test(str_contains($cssAttempt, '.starter-code-block'), "CSS contains .starter-code-block selector");
assert_test(str_contains($cssAttempt, '.coding-editor'), "CSS contains .coding-editor selector");
assert_test(str_contains($cssAttempt, 'white-space: pre !important') || str_contains($cssAttempt, 'white-space: pre'), "CSS specifies white-space: pre");
assert_test(str_contains($cssAttempt, 'overflow-x: auto'), "CSS specifies overflow-x: auto");
assert_test(str_contains($cssAttempt, 'tab-size: 4'), "CSS specifies tab-size: 4");
assert_test(str_contains($cssAttempt, 'line-height: 1.7'), "CSS specifies line-height: 1.7");
assert_test(str_contains($cssAttempt, 'Consolas, Monaco, "Courier New", monospace'), "CSS specifies required font family");

// ----------------------------------------------------
// 6. VERIFY STUDENT/ATTEMPT_EXAM.PHP SOURCE INTEGRITY
// ----------------------------------------------------
echo "\n[6] Verifying student/attempt_exam.php Integrity:\n";

$phpAttempt = file_get_contents(__DIR__ . '/../student/attempt_exam.php');

assert_test(str_contains($phpAttempt, '.code-display'), "attempt_exam.php <style> contains .code-display");
assert_test(str_contains($phpAttempt, '.question-code'), "attempt_exam.php <style> contains .question-code");
assert_test(str_contains($phpAttempt, '.question-code pre'), "attempt_exam.php <style> contains .question-code pre");
assert_test(str_contains($phpAttempt, '.starter-code-block'), "attempt_exam.php <style> contains .starter-code-block");
assert_test(str_contains($phpAttempt, '.coding-editor'), "attempt_exam.php <style> contains .coding-editor");
assert_test(str_contains($phpAttempt, 'line-height: 1.7'), "attempt_exam.php <style> specifies line-height: 1.7");
assert_test(str_contains($phpAttempt, 'safe_unescape_exam_code($editorInitialCode)'), "attempt_exam.php safely handles escaped newlines for initial code via safe_unescape_exam_code");
assert_test(str_contains($phpAttempt, 'htmlspecialchars($editorInitialCode, ENT_QUOTES, \'UTF-8\')'), "attempt_exam.php safely escapes HTML without destroying line breaks");
assert_test(str_contains($phpAttempt, 'options input[type="radio"]'), "MCQ radio button alignment rules intact");
assert_test(str_contains($phpAttempt, 'display: flex !important;'), "MCQ flex layout intact");

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
}
