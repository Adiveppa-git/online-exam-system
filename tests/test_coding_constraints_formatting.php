<?php
require_once __DIR__ . '/../config/text_helpers.php';

echo "====================================================\n";
echo "   Coding Constraints Formatting & Display Suite    \n";
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

// 1. Math notation formatting
$m1 = format_math_notation('1 <= length(S) <= 10^5');
assert_test($m1 === '1 ≤ length(S) ≤ 10⁵', 'Math notation: <= and 10^5 converted', "got $m1");

$m2 = format_math_notation('-10^9 <= arr[i] <= 10^9');
assert_test($m2 === '-10⁹ ≤ arr[i] ≤ 10⁹', 'Math notation: negative numbers with powers preserved', "got $m2");

$m3 = format_math_notation('1 <= length(S) <= 2 * 10^5');
assert_test($m3 === '1 ≤ length(S) ≤ 2 × 10⁵', 'Math notation: multiplication 2 * 10^5 converted to 2 × 10⁵', "got $m3");

$m4 = format_math_notation('arr.length >= 1 && x != 0');
assert_test(str_contains($m4, '≥') && str_contains($m4, '≠'), 'Math notation: >= and != converted', "got $m4");

// 2. Parser extraction - newline separated
$raw1 = "1 <= length(S) <= 10^5\nTime Limit: 1.0s\nMemory Limit: 256MB";
$p1 = parse_coding_constraints($raw1);
assert_test(count($p1['constraints']) === 1 && $p1['constraints'][0] === '1 ≤ length(S) ≤ 10⁵', 'Parser newline: constraint extracted');
assert_test($p1['time_limit'] === '1.0s', 'Parser newline: time limit extracted');
assert_test($p1['memory_limit'] === '256MB', 'Parser newline: memory limit extracted');

// 3. Parser extraction - crowded single line (as reported in user prompt)
$rawCrowded = "Constraints: 1 <= length(S) <= 10^5 Time Limit: 1.0s Memory Limit: 256MB";
$pCrowded = parse_coding_constraints($rawCrowded);
assert_test(count($pCrowded['constraints']) === 1 && $pCrowded['constraints'][0] === '1 ≤ length(S) ≤ 10⁵', 'Parser crowded: constraint isolated and prefix stripped');
assert_test($pCrowded['time_limit'] === '1.0s', 'Parser crowded: time limit extracted');
assert_test($pCrowded['memory_limit'] === '256MB', 'Parser crowded: memory limit extracted');

// 4. Parser extraction - multiple constraints and negative boundaries
$rawMulti = "1 <= N <= 10^5\n-10^9 <= arr[i] <= 10^9\nTime Limit: 1.0s";
$pMulti = parse_coding_constraints($rawMulti);
assert_test(count($pMulti['constraints']) === 2, 'Parser multi: 2 constraints extracted');
assert_test($pMulti['constraints'][0] === '1 ≤ N ≤ 10⁵', 'Parser multi: first constraint math formatted');
assert_test($pMulti['constraints'][1] === '-10⁹ ≤ arr[i] ≤ 10⁹', 'Parser multi: negative boundary preserved');
assert_test($pMulti['time_limit'] === '1.0s', 'Parser multi: time limit extracted');
assert_test($pMulti['memory_limit'] === null, 'Parser multi: memory limit is null when omitted');

// 5. HTML rendering and XSS escaping
$rawXss = "<script>alert('xss')</script> <= N <= 10^5\nTime Limit: <img src=x onerror=alert(1)>";
$htmlXss = render_coding_constraints($rawXss);
assert_test(!str_contains($htmlXss, '<script>'), 'HTML output: raw <script> tag escaped');
assert_test(!str_contains($htmlXss, '<img src=x'), 'HTML output: raw <img> tag escaped');
assert_test(str_contains($htmlXss, '&lt;script&gt;'), 'HTML output: entities properly escaped');

// 6. Visual structure and styling elements
$htmlRender = render_coding_constraints($rawCrowded);
assert_test(str_contains($htmlRender, 'class="coding-constraints-box"'), 'HTML output: coding-constraints-box container present');
assert_test(str_contains($htmlRender, '⚠️'), 'HTML output: warning icon preserved');
assert_test(str_contains($htmlRender, '<strong>Constraints:</strong>'), 'HTML output: Constraints label separated');
assert_test(str_contains($htmlRender, '<strong>Time Limit:</strong>'), 'HTML output: Time Limit label separated');
assert_test(str_contains($htmlRender, '<strong>Memory Limit:</strong>'), 'HTML output: Memory Limit label separated');
assert_test(str_contains($htmlRender, '1 ≤ length(S) ≤ 10⁵'), 'HTML output: formatted inequality rendered');
assert_test(str_contains($htmlRender, '1.0s'), 'HTML output: time limit value rendered');
assert_test(str_contains($htmlRender, '256MB'), 'HTML output: memory limit value rendered');

// 7. Null and empty handling
assert_test(render_coding_constraints(null) === '', 'Null input returns empty string');
assert_test(render_coding_constraints('   ') === '', 'Whitespace input returns empty string');

echo "\n----------------------------------------------------\n";
echo "Summary: $passed Passed, $failed Failed\n";
echo "====================================================\n";

exit($failed > 0 ? 1 : 0);
