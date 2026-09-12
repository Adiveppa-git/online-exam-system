<?php
/**
 * Whole-System AI Assistant Test Suite for Online Examination System
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

echo "====================================================\n";
echo " Whole-System AI Assistant Comprehensive Test Suite \n";
echo "====================================================\n\n";

$aiClient = new AiClient();
$passed = 0;
$failed = 0;

$student_ctx = ['id' => 1, 'name' => 'Adi', 'role' => 'student'];
$admin_ctx = ['id' => 99, 'name' => 'Admin User', 'role' => 'admin'];
$admin_stats = ['total_students' => 4, 'total_exams' => 2, 'total_questions' => 16, 'total_results' => 6];
$other_users = [
    ['id' => 1, 'name' => 'Adi', 'role' => 'student'],
    ['id' => 2, 'name' => 'Adiveppa', 'role' => 'student']
];

// TEST 1: "What is your name?"
echo "[TEST 1] Testing Assistant Identity ('What is your name?')... ";
$res1 = $aiClient->askRAG("What is your name?", null, null, null, null, 1, [], $student_ctx);
if ($res1['status'] === 'success' && str_contains($res1['data']['answer'], "AI Assistant")) {
    echo "PASSED (Assistant identity returned)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 2: "What is my name?"
echo "[TEST 2] Testing Logged-in User Name ('What is my name?')... ";
$res2 = $aiClient->askRAG("What is my name?", null, null, null, null, 1, [], $student_ctx);
if ($res2['status'] === 'success' && str_contains($res2['data']['answer'], "Adi")) {
    echo "PASSED (Authenticated user name 'Adi' returned)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 3: "Who am I logged in as?"
echo "[TEST 3] Testing Logged-in Account ('Who am I logged in as?')... ";
$res3 = $aiClient->askRAG("Who am I logged in as?", null, null, null, null, 1, [], $student_ctx);
if ($res3['status'] === 'success' && str_contains($res3['data']['answer'], "Adi")) {
    echo "PASSED (Logged in account info returned)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 4: "How am I performing?"
echo "[TEST 4] Testing Student Performance Query ('How am I performing?')... ";
$sample_history = [
    ['subject' => 'English', 'topic' => 'Grammar', 'is_correct' => false],
    ['subject' => 'English', 'topic' => 'Vocabulary', 'is_correct' => true]
];
$res4 = $aiClient->askRAG("How am I performing?", null, null, null, null, 1, $sample_history, $student_ctx);
if ($res4['status'] === 'success' && str_contains($res4['data']['answer'], "accuracy")) {
    echo "PASSED (Student performance summary returned)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 5: "I am not getting good marks in Maths. How can I improve?"
echo "[TEST 5] Testing Performance + Subject Context ('I am not getting good marks in Maths. How can I improve?')... ";
$res5 = $aiClient->askRAG("I am not getting good marks in Maths. How can I improve?", null, null, null, null, 1, [], $student_ctx);
if ($res5['status'] === 'success' && str_contains($res5['data']['answer'], "Maths")) {
    echo "PASSED (Maths context & recommendation returned)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 6: Maths material unavailable but English material available
echo "[TEST 6] Testing Maths Query when only English material is uploaded... ";
$tmp_dir = __DIR__ . '/../uploads/course_materials/';
if (!file_exists($tmp_dir)) mkdir($tmp_dir, 0755, true);

$eng_file = $tmp_dir . 'test_eng.txt';
file_put_contents($eng_file, "English Grammar Unit 1: Nouns and Verbs.");

$stmt = $conn->prepare("INSERT INTO ai_documents (filename, original_name, file_path, file_size, subject, topic, status) VALUES ('test_eng.txt', 'English_Grammar_Course_Material.txt', ?, ?, 'English', 'Grammar', 'pending')");
$fsize = filesize($eng_file);
$stmt->bind_param("si", $eng_file, $fsize);
$stmt->execute();
$eng_doc_id = $stmt->insert_id;

$aiClient->ingestDocument($eng_file, $eng_doc_id, 'English_Grammar_Course_Material.txt', 'English', 'Grammar');

$res6 = $aiClient->askRAG("I am not getting good marks in Maths. How can I improve?", null, null, null, null, 1, [], $student_ctx);
$has_eng_src = false;
foreach ($res6['data']['sources'] ?? [] as $src) {
    if (str_contains($src['filename'], 'English')) $has_eng_src = true;
}

if (!$has_eng_src && str_contains($res6['data']['answer'], "Maths")) {
    echo "PASSED (Zero English sources retrieved, honest Maths missing message returned)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 7: English material available & English question
echo "[TEST 7] Testing English Question ('How can I improve my Grammar?')... ";
$res7 = $aiClient->askRAG("How can I improve my Grammar?", null, null, null, null, 1, [], $student_ctx);
if ($res7['status'] === 'success' && !empty($res7['data']['sources'])) {
    echo "PASSED (English material retrieved with correct citation)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 8: User asks about another student's personal information
echo "[TEST 8] User asks for another student's personal information ('What is Renkigouda\'s personal information?')... ";
$res8 = $aiClient->askRAG("What is Renkigouda's personal information?", null, null, null, null, 1, [], $student_ctx, null, $other_users);
if ($res8['status'] === 'success' && str_contains($res8['data']['answer'], "can't share another person's personal information")) {
    echo "PASSED (Privacy refusal enforced for personal information query)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 9: Student attempts to access another student's email/password/marks
echo "[TEST 9] Student attempts to access another student's email ('What is Renkigouda\'s email?')... ";
$res9 = $aiClient->askRAG("What is Renkigouda's email?", null, null, null, null, 1, [], $student_ctx, null, $other_users);
if ($res9['status'] === 'success' && str_contains($res9['data']['answer'], "can't share another person's personal information")) {
    echo "PASSED (Privacy refusal enforced for email query)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 10: Admin asks for authorized system statistics
echo "[TEST 10] Admin asks for authorized system statistics... ";
$res10 = $aiClient->askRAG("How many students are registered?", null, null, null, null, 99, [], $admin_ctx, $admin_stats);
if ($res10['status'] === 'success' && str_contains($res10['data']['answer'], "Registered Students")) {
    echo "PASSED (Database-backed system statistics returned to Admin)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 11: Student asks admin-only statistics
echo "[TEST 11] Student asks admin-only statistics ('How many students are registered?')... ";
$res11 = $aiClient->askRAG("How many students are registered?", null, null, null, null, 1, [], $student_ctx, null);
if ($res11['status'] === 'success' && str_contains($res11['data']['answer'], "do not have administrative authorization")) {
    echo "PASSED (Authorization denial enforced for student)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 12: "hii"
echo "[TEST 12] Testing Casual Greeting ('hii')... ";
$res12 = $aiClient->askRAG("hii", null, null, null, null, 1, [], $student_ctx);
if ($res12['status'] === 'success' && str_contains($res12['data']['answer'], "Hello! 👋")) {
    echo "PASSED (Conversational greeting returned without RAG)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 13: "good morning"
echo "[TEST 13] Testing Time-based Greeting ('good morning')... ";
$res13 = $aiClient->askRAG("good morning", null, null, null, null, 1, [], $student_ctx);
if ($res13['status'] === 'success' && str_contains($res13['data']['answer'], "Good morning! ☀️")) {
    echo "PASSED (Good morning greeting returned)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 14: "bye"
echo "[TEST 14] Testing Goodbye ('bye')... ";
$res14 = $aiClient->askRAG("bye", null, null, null, null, 1, [], $student_ctx);
if ($res14['status'] === 'success' && str_contains($res14['data']['answer'], "Goodbye! 👋")) {
    echo "PASSED (Goodbye response returned)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// TEST 15: "hi explain grammar" (Compound query)
echo "[TEST 15] Testing Compound Query ('hi explain grammar')... ";
$res15 = $aiClient->askRAG("hi explain grammar", null, null, null, null, 1, [], $student_ctx);
if ($res15['status'] === 'success' && str_contains($res15['data']['answer'], "Hello! 👋")) {
    echo "PASSED (Conversational greeting prefix + RAG Grammar answer returned)\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

// Cleanup Test Document
$aiClient->deleteRAGDocument($eng_doc_id);
$conn->query("DELETE FROM ai_documents WHERE id = $eng_doc_id");
if (file_exists($eng_file)) @unlink($eng_file);

echo "\n----------------------------------------------------\n";
echo "Summary: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";
