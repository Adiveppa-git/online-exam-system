<?php
/**
 * Student Secure Code Execution Endpoint
 * Handles "Run Code" and "Run & Check" requests from student exam attempts.
 * Code is securely delegated to the isolated backend sandbox service.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

header("Content-Type: application/json; charset=utf-8");
header("X-Content-Type-Options: nosniff");

/* ===== 1. AUTHENTICATION CHECK ===== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized access. Student session required."
    ]);
    exit;
}

/* ===== 2. CSRF TOKEN VALIDATION ===== */
$postToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $postToken)) {
    http_response_code(403);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid or expired CSRF security token."
    ]);
    exit;
}

$student_id  = (int)$_SESSION['user_id'];
$exam_id     = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
$question_id = isset($_POST['question_id']) ? (int)$_POST['question_id'] : 0;
$code        = isset($_POST['code']) ? (string)$_POST['code'] : '';
$action      = isset($_POST['action']) ? strtolower(trim((string)$_POST['action'])) : 'run';
$customInput = isset($_POST['custom_input']) ? (string)$_POST['custom_input'] : null;

/* ===== 3. INPUT VALIDATION ===== */
if ($exam_id <= 0 || $question_id <= 0) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid exam or question ID."
    ]);
    exit;
}

if (trim($code) === '') {
    echo json_encode([
        "status" => "error",
        "message" => "Code editor is empty. Please enter your code solution before running."
    ]);
    exit;
}

if (strlen($code) > 65536) {
    echo json_encode([
        "status" => "error",
        "message" => "Code exceeds maximum permitted length of 64 KB."
    ]);
    exit;
}

/* ===== 4. CHECK IF EXAM ALREADY COMPLETED ===== */
$chkComp = $conn->prepare("SELECT id FROM results WHERE user_id = ? AND exam_id = ?");
$chkComp->bind_param("ii", $student_id, $exam_id);
$chkComp->execute();
if ($chkComp->get_result()->num_rows > 0) {
    http_response_code(403);
    echo json_encode([
        "status" => "error",
        "message" => "This exam has already been completed. Code execution is disabled."
    ]);
    exit;
}
$chkComp->close();

/* ===== 5. VALIDATE QUESTION BELONGS TO EXAM ===== */
$stmtQ = $conn->prepare("SELECT id, question, question_type, programming_language, sample_test_cases FROM questions WHERE id = ? AND exam_id = ?");
$stmtQ->bind_param("ii", $question_id, $exam_id);
$stmtQ->execute();
$qRow = $stmtQ->get_result()->fetch_assoc();
$stmtQ->close();

if (!$qRow) {
    http_response_code(404);
    echo json_encode([
        "status" => "error",
        "message" => "Question not found or does not belong to this exam."
    ]);
    exit;
}

// Determine programming language
$language = !empty($qRow['programming_language']) ? strtolower(trim($qRow['programming_language'])) : 'python';

// Decode sample test cases
$sampleCases = [];
if (!empty($qRow['sample_test_cases'])) {
    $decoded = is_string($qRow['sample_test_cases']) ? json_decode($qRow['sample_test_cases'], true) : $qRow['sample_test_cases'];
    if (is_array($decoded)) {
        $sampleCases = $decoded;
    }
}

/* ===== 6. DISPATCH TO SECURE EXECUTION SANDBOX ===== */
$client = new AiClient();

if ($action === 'check') {
    // Run & Check against sample test cases
    if (empty($sampleCases)) {
        // Fallback to single run if no sample cases configured
        $execRes = $client->executeCode($language, $code, $customInput ?? "");
        if (!$execRes['online']) {
            echo json_encode([
                "status" => "error",
                "message" => "Code execution backend service is currently offline or unreachable. Please verify the AI/Execution service is running."
            ]);
            exit;
        }
        echo json_encode([
            "status" => "success",
            "mode" => "run",
            "language" => $language,
            "data" => $execRes['data'] ?? []
        ]);
        exit;
    }

    $checkRes = $client->checkSampleCases($language, $code, $sampleCases);
    if (!$checkRes['online']) {
        echo json_encode([
            "status" => "error",
            "message" => "Code execution backend service is currently offline or unreachable. Please verify the AI/Execution service is running."
        ]);
        exit;
    }

    echo json_encode([
        "status" => "success",
        "mode" => "check",
        "language" => $language,
        "data" => $checkRes['data'] ?? []
    ]);
    exit;

} else {
    // Default "Run Code" with custom input or Sample 1 input
    $stdin = "";
    if ($customInput !== null && $customInput !== "") {
        $stdin = $customInput;
    } elseif (!empty($sampleCases[0]['input'])) {
        $firstInput = $sampleCases[0]['input'];
        $stdin = is_scalar($firstInput) ? (string)$firstInput : json_encode($firstInput);
    }

    $execRes = $client->executeCode($language, $code, $stdin);
    if (!$execRes['online']) {
        echo json_encode([
            "status" => "error",
            "message" => "Code execution backend service is currently offline or unreachable. Please verify the AI/Execution service is running."
        ]);
        exit;
    }

    echo json_encode([
        "status" => "success",
        "mode" => "run",
        "language" => $language,
        "stdin_used" => $stdin,
        "data" => $execRes['data'] ?? []
    ]);
    exit;
}
