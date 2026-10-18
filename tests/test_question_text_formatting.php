<?php
/**
 * Test Suite: Question Text Formatting & Variant Suffix Elimination
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/text_helpers.php';
require_once __DIR__ . '/../config/ai_client.php';

echo "====================================================\n";
echo "   Test Suite: Question Text Formatting & Variants  \n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test(string $testName, bool $condition, string $detail = '') {
    global $passed, $failed;
    echo " - {$testName}... ";
    if ($condition) {
        echo "PASSED\n";
        $passed++;
    } else {
        echo "FAILED: {$detail}\n";
        $failed++;
    }
}

// 1. Unit Tests for clean_question_title
echo "[1] Unit Tests for clean_question_title():\n";

$cases = [
    'What is a fundamental core concept of Grammar in English? (Variant 6)' => 'What is a fundamental core concept of Grammar in English?',
    'Which of the following best describes the function of Grammar? (Variant 7)' => 'Which of the following best describes the function of Grammar?',
    'In a Hard scenario involving Grammar, which strategy is recommended? (Variant 8)' => 'In a Hard scenario involving Grammar, which strategy is recommended?',
    'Which strategy is recommended for Grammar? (Variant 48)' => 'Which strategy is recommended for Grammar?',
    'Which function does Grammar perform? (Variant 49)' => 'Which function does Grammar perform?',
    'What is the result of 2 + 2? (variant 10)' => 'What is the result of 2 + 2?',
    'What is 802.11ax standard in networking?' => 'What is 802.11ax standard in networking?',
    'Calculate the value of 5 * 10.' => 'Calculate the value of 5 * 10.',
    'What is a genetic splice variant in molecular biology?' => 'What is a genetic splice variant in molecular biology?',
    'In modern C++, what does std::variant represent?' => 'In modern C++, what does std::variant represent?',
    'Which variant of the Dijkstra algorithm uses Fibonacci heaps?' => 'Which variant of the Dijkstra algorithm uses Fibonacci heaps?',
    'Evaluate the expression 3x + 5 = 20 (Variant 1)' => 'Evaluate the expression 3x + 5 = 20?',
    'Null or empty input check' => ''
];

foreach ($cases as $input => $expected) {
    if ($input === 'Null or empty input check') {
        $actualNull = clean_question_title(null);
        $actualEmpty = clean_question_title('');
        assert_test("Handle null and empty string", $actualNull === '' && $actualEmpty === '');
    } else {
        $actual = clean_question_title($input);
        assert_test(
            "Clean: " . mb_substr($input, 0, 45) . "...",
            $actual === $expected,
            "Expected '{$expected}', got '{$actual}'"
        );
    }
}

// 2. Database Record Verification
echo "\n[2] Database Record Verification:\n";

$qVariants = $conn->query("SELECT COUNT(*) FROM questions WHERE question LIKE '%Variant%'")->fetch_row()[0];
assert_test("Zero active questions contain 'Variant'", (int)$qVariants === 0, "Found {$qVariants} matching rows in questions");

$aiVariants = $conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE question LIKE '%Variant%'")->fetch_row()[0];
assert_test("Zero active ai_generated_questions contain 'Variant'", (int)$aiVariants === 0, "Found {$aiVariants} matching rows in ai_generated_questions");

$practiceVariants = $conn->query("SELECT COUNT(*) FROM ai_practice_answers WHERE question_text LIKE '%Variant%'")->fetch_row()[0];
assert_test("Zero ai_practice_answers contain 'Variant'", (int)$practiceVariants === 0, "Found {$practiceVariants} matching rows in ai_practice_answers");

// 3. Newly Generated Questions Verification via AiClient
echo "\n[3] AI Generation Pipeline Verification:\n";

$aiClient = new AiClient();
$health = $aiClient->checkHealth();

if ($health['online'] && ($health['status'] === 'success' || $health['data']['status'] === 'ok')) {
    $res = $aiClient->generateQuestions('Computer Science', 'Object Oriented Programming', 'medium', 25);
    assert_test("AI service generates 25 questions successfully", $res['success'] && count($res['data']['questions'] ?? []) === 25);

    $foundVariantInGen = false;
    $allPunctuationValid = true;

    if (!empty($res['data']['questions'])) {
        foreach ($res['data']['questions'] as $gq) {
            $text = $gq['question'];
            if (stripos($text, 'variant') !== false) {
                $foundVariantInGen = true;
            }
            if (!preg_match('/[?.!]$/u', trim($text))) {
                $allPunctuationValid = false;
            }
        }
    }

    assert_test("No artificial variant markers in newly generated questions", !$foundVariantInGen);
    assert_test("All generated questions have valid punctuation", $allPunctuationValid);
} else {
    echo " - AI Service offline on port 8001. Skipping live generation test.\n";
}

echo "\n----------------------------------------------------\n";
echo "Results: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

exit($failed > 0 ? 1 : 0);
