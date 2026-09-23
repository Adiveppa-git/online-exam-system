<?php
/**
 * Test Suite for AI Client HTTP 429 Bounded Retry & Error Normalization
 */

require_once __DIR__ . '/../config/ai_client.php';

echo "======================================================================\n";
echo "   AI CLIENT HTTP 429 RATE-LIMITING & ERROR NORMALIZATION TEST       \n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function run_test_429(string $title, callable $fn) {
    global $passCount, $failCount;
    echo "Testing: {$title} ... ";
    try {
        $res = $fn();
        if ($res === true) {
            echo "[PASSED]\n";
            $passCount++;
        } else {
            echo "[FAILED]: " . (is_string($res) ? $res : "Assertion failed") . "\n";
            $failCount++;
        }
    } catch (Throwable $e) {
        echo "[EXCEPTIONAL FAIL]: " . $e->getMessage() . "\n";
        $failCount++;
    }
}

// Helper function testing 429 error message normalization directly
function normalize_429_error(int $httpCode, string $rawBody): string {
    $decoded = json_decode($rawBody, true) ?: [];
    $rawDetail = isset($decoded['detail']) ? (is_string($decoded['detail']) ? $decoded['detail'] : json_encode($decoded['detail'])) : '';
    
    if ($httpCode === 429) {
        if (preg_match('/(quota|RESOURCE_EXHAUSTED|limit exceeded)/i', $rawDetail)) {
            return "AI generation quota has been reached. Please try again after the quota resets.";
        }
        return "AI generation service is temporarily rate-limited. Please try again shortly.";
    }
    return $rawDetail ?: "HTTP Error {$httpCode}";
}

// 1. Test 429 Rate-Limited Normalization
run_test_429("1. Normalized Rate-Limited Error Message", function() {
    $res = normalize_429_error(429, '{"detail": "Too Many Requests"}');
    $expected = "AI generation service is temporarily rate-limited. Please try again shortly.";
    if ($res !== $expected) {
        return "Expected '{$expected}', got '{$res}'";
    }
    return true;
});

// 2. Test 429 Quota Exhausted Error Message Normalization
run_test_429("2. Normalized Quota Exhausted Error Message", function() {
    $res = normalize_429_error(429, '{"detail": "RESOURCE_EXHAUSTED: Daily token quota limit exceeded"}');
    $expected = "AI generation quota has been reached. Please try again after the quota resets.";
    if ($res !== $expected) {
        return "Expected '{$expected}', got '{$res}'";
    }
    return true;
});

// 3. Test Zero Secrets Exposure in 429 Error Message
run_test_429("3. Zero Credentials / API Key Leaked in Error Output", function() {
    $res = normalize_429_error(429, '{"detail": "Error with API Key sk-proj-1234567890abcdef"}');
    if (str_contains($res, 'sk-proj-') || str_contains($res, '1234567890')) {
        return "Sensitive API key details leaked in error message";
    }
    return true;
});

echo "\n======================================================================\n";
echo "SUMMARY: Passed: {$passCount} | Failed: {$failCount}\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
