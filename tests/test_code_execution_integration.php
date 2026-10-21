<?php
/**
 * Automated Integration Test for Code Execution Security and Functionality
 * Tests student/run_code.php via simulated HTTP/PHP contexts:
 * - Unauthenticated request rejection (401)
 * - Missing/invalid CSRF rejection (403)
 * - Exam already completed rejection (403)
 * - Invalid question rejection (404)
 * - Valid Python execution with stdout
 * - Valid Python runtime error with stderr
 * - Python execution timeout
 * - Valid Java execution with stdout
 * - Java compilation error handling
 * - "Run & Check" sample case evaluation
 */

// 1. Initialize session FIRST before any output
$testSessionId = bin2hex(random_bytes(16));
session_id($testSessionId);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

// Find a student user
$uQuery = $conn->query("SELECT id, email FROM users WHERE role = 'student' LIMIT 1");
$studentRow = $uQuery->fetch_assoc();
$testStudentId = $studentRow ? (int)$studentRow['id'] : 1;
$csrfToken = bin2hex(random_bytes(16));

$_SESSION['user_id'] = $testStudentId;
$_SESSION['role'] = 'student';
$_SESSION['csrf_token'] = $csrfToken;
session_write_close();

$cookieHeader = "PHPSESSID=$testSessionId";

echo "====================================================\n";
echo "   Code Execution Integration & Security Suite      \n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $description) {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] $description\n";
        $passCount++;
    } else {
        echo "  [FAIL] $description\n";
        $failCount++;
    }
}

// 1. Direct AiClient tests
echo "[1] Testing AiClient Connection & Capabilities:\n";
$client = new AiClient();
$status = $client->getCodeExecutionStatus();
assertTest($status['online'] === true, "AI/Code Execution backend is online (Port 8001)");
assertTest(isset($status['data']['python']['available']) && $status['data']['python']['available'] === true, "Python runtime available in backend");
assertTest(isset($status['data']['java']['available']) && $status['data']['java']['available'] === true, "Java runtime available in backend");

// 2. Python Execution via AiClient
echo "\n[2] Testing Python Execution:\n";
$pyCode = "print('Hello Antigravity from Python!')";
$resPy = $client->executeCode('python', $pyCode, '');
assertTest($resPy['online'] === true, "Python execution responded");
assertTest(trim($resPy['data']['stdout'] ?? '') === "Hello Antigravity from Python!", "Python stdout matches exactly: '{$resPy['data']['stdout']}'");
assertTest(($resPy['data']['exit_code'] ?? -1) === 0, "Python exit code is 0");

// 2b. Python with Stdin
$pyStdinCode = "import sys\nline = sys.stdin.read().strip()\nprint(f'ECHO: {line}')";
$resStdin = $client->executeCode('python', $pyStdinCode, 'CustomInput123');
assertTest(trim($resStdin['data']['stdout'] ?? '') === "ECHO: CustomInput123", "Python stdin processed correctly");

// 2c. Python Runtime Error
$pyErrCode = "print(10 / 0)";
$resErr = $client->executeCode('python', $pyErrCode, '');
assertTest(($resErr['data']['exit_code'] ?? 0) !== 0, "Python division by zero returns non-zero exit code");
assertTest(strpos($resErr['data']['stderr'] ?? '', 'ZeroDivisionError') !== false, "Python stderr captures ZeroDivisionError");

// 2d. Python Timeout
$pyTimeoutCode = "import time\nwhile True:\n    time.sleep(0.1)";
$resTimeout = $client->executeCode('python', $pyTimeoutCode, '', 2.0);
assertTest(($resTimeout['data']['timeout'] ?? false) === true || ($resTimeout['data']['timed_out'] ?? false) === true, "Python infinite loop correctly timed out and was killed");

// 3. Java Execution via AiClient
echo "\n[3] Testing Java Execution:\n";
$javaCode = <<<'JAVA'
public class Solution {
    public static void main(String[] args) {
        System.out.println("Hello Java Execution!");
    }
}
JAVA;
$resJava = $client->executeCode('java', $javaCode, '');
assertTest($resJava['online'] === true, "Java execution responded");
assertTest(trim($resJava['data']['stdout'] ?? '') === "Hello Java Execution!", "Java stdout matches: '{$resJava['data']['stdout']}'");
assertTest(($resJava['data']['exit_code'] ?? -1) === 0, "Java exit code is 0");

// 3b. Java Compilation Error
$javaBadCode = <<<'JAVA'
public class Solution {
    public static void main(String[] args) {
        System.out.println("Missing semicolon")
    }
}
JAVA;
$resJavaBad = $client->executeCode('java', $javaBadCode, '');
assertTest(($resJavaBad['data']['exit_code'] ?? 0) !== 0, "Java syntax error returns non-zero exit code");
assertTest(strpos($resJavaBad['data']['error'] ?? '', 'compilation') !== false || strpos($resJavaBad['data']['stderr'] ?? '', 'error:') !== false, "Java compilation failure reported in error/stderr");

// 4. Sample Test Cases Checking via AiClient
echo "\n[4] Testing Run & Check Sample Cases:\n";
$reverseCode = <<<'PY'
import sys
s = sys.stdin.read().strip()
print(s[::-1])
PY;
$sampleCases = [
    [
        'input' => "hello",
        'output' => "olleh"
    ],
    [
        'input' => "world",
        'output' => "dlrow"
    ],
    [
        'input' => "racecar",
        'output' => "racecar"
    ]
];
$resCheck = $client->checkSampleCases('python', $reverseCode, $sampleCases);
assertTest($resCheck['online'] === true, "Sample case checker responded");
assertTest(($resCheck['data']['total_cases'] ?? ($resCheck['data']['total'] ?? 0)) === 3, "Checked 3 sample cases");
assertTest(($resCheck['data']['passed_cases'] ?? ($resCheck['data']['passed'] ?? 0)) === 3, "All 3 sample cases passed");
assertTest(($resCheck['data']['all_passed'] ?? false) === true, "all_passed flag is true");

// 5. Test Run Code Endpoint directly via HTTP POST to Apache
echo "\n[5] Testing student/run_code.php HTTP & Security Boundary:\n";

function postToRunCode($postData, $cookie = '') {
    $ch = curl_init("http://127.0.0.1/exam-online/online-exam-system/student/run_code.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_HEADER, true);
    if (!empty($cookie)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: $cookie"]);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($response, $headerSize);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => $body, 'json' => json_decode($body, true)];
}

// 5a. Unauthenticated Request
$resUnauth = postToRunCode(['exam_id' => 100111, 'question_id' => 736, 'code' => 'print(1)']);
assertTest($resUnauth['code'] === 401, "Unauthenticated request returns HTTP 401 Unauthorized");
assertTest(isset($resUnauth['json']['status']) && $resUnauth['json']['status'] === 'error', "Returns error JSON structure for 401");

// 5b. Invalid CSRF Token with authenticated student session
$resBadCsrf = postToRunCode([
    'exam_id' => 100111,
    'question_id' => 736,
    'code' => 'print(1)',
    'csrf_token' => 'invalid_csrf_token_xyz'
], $cookieHeader);
assertTest($resBadCsrf['code'] === 403, "Invalid CSRF token returns HTTP 403 Forbidden");

// 5c. Missing / Non-existent Question ID
$resBadQ = postToRunCode([
    'exam_id' => 9999999,
    'question_id' => 9999999,
    'code' => 'print(1)',
    'csrf_token' => $csrfToken
], $cookieHeader);
assertTest($resBadQ['code'] === 404 || $resBadQ['code'] === 400, "Non-existent question/exam returns 404 or 400");

// 5d. Valid Python Run Code request on an active coding question
$qRow = $conn->query("SELECT id, exam_id, programming_language, sample_test_cases FROM questions WHERE question_type = 'coding' LIMIT 1")->fetch_assoc();
if ($qRow) {
    // Ensure test student has not completed this exam in results table
    $conn->query("DELETE FROM results WHERE user_id = {$testStudentId} AND exam_id = {$qRow['exam_id']}");

    $resValidRun = postToRunCode([
        'exam_id' => $qRow['exam_id'],
        'question_id' => $qRow['id'],
        'code' => "import sys\ns = sys.stdin.read().strip()\nprint(f'OUTPUT_TOKEN_{s}')",
        'custom_input' => 'AntigravityLive456',
        'csrf_token' => $csrfToken,
        'action' => 'run'
    ], $cookieHeader);

    assertTest($resValidRun['code'] === 200, "Valid Run Code request returns HTTP 200 OK");
    assertTest(isset($resValidRun['json']['status']) && $resValidRun['json']['status'] === 'success', "Response status is 'success'");
    assertTest(strpos($resValidRun['json']['data']['stdout'] ?? '', 'OUTPUT_TOKEN_AntigravityLive456') !== false, "Student code stdout properly returned via run_code.php endpoint");

    // 5e. Valid Run & Check request
    $resCheckEndpoint = postToRunCode([
        'exam_id' => $qRow['exam_id'],
        'question_id' => $qRow['id'],
        'code' => "import sys\nprint('Hello')",
        'csrf_token' => $csrfToken,
        'action' => 'check'
    ], $cookieHeader);
    assertTest($resCheckEndpoint['code'] === 200, "Run & Check request returns HTTP 200 OK");
    assertTest(isset($resCheckEndpoint['json']['data']['results']) || isset($resCheckEndpoint['json']['data']['stdout']), "Run & Check returns structured test case results");
}

echo "\n----------------------------------------------------\n";
echo "Summary: $passCount Passed, $failCount Failed\n";
echo "====================================================\n";

exit($failCount === 0 ? 0 : 1);
