<?php
/**
 * Automated Regression Test Suite:
 * AI Assistant Connection to Exam Subjects, Questions, Results, and RAG Materials
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';
require_once __DIR__ . '/../config/subject_resolver.php';

$totalPassed = 0;
$totalFailed = 0;

function assertTest(string $name, bool $condition, string $detail = '') {
    global $totalPassed, $totalFailed;
    if ($condition) {
        $totalPassed++;
        echo "PASS: {$name}\n";
    } else {
        $totalFailed++;
        echo "FAIL: {$name}" . ($detail ? " - {$detail}" : "") . "\n";
    }
}

echo "====================================================================\n";
echo "AI ASSISTANT SUBJECT CONNECTION & SECURITY TEST SUITE\n";
echo "====================================================================\n\n";

$aiClient = new AiClient();
$studentId = 9;

// --- Test 1: Java question retrieves only Java-related context ---
echo "--- 1. Java Question Context Isolation & Grounded 5-Section Advice ---\n";
$q_java = "How can I improve my marks in Java?";
$res_java = SubjectResolver::resolve($conn, $q_java);
assertTest("Java subject resolved", $res_java['status'] === 'resolved' && $res_java['subject'] === 'Java');

$sum_java = SubjectResolver::getSubjectSummary($conn, 'Java', $studentId);
assertTest("Java subject exists and has questions", $sum_java['exists'] && $sum_java['total_exam_questions'] > 0);

$ask_java = $aiClient->askRAG(
    question: $q_java,
    subject: 'Java',
    studentId: $studentId,
    history: [],
    userContext: ['id' => $studentId, 'name' => 'adiveppa', 'role' => 'student', 'subject_summary' => $sum_java]
);
$ans_java = $ask_java['data']['answer'] ?? '';

assertTest("Java query answers with Java context", stripos($ans_java, 'Java') !== false);
assertTest("Java query does NOT reference Computer Science", stripos($ans_java, 'Computer Science') === false);
assertTest("Java query does NOT leak Python data", stripos($ans_java, 'Python') === false);
assertTest("Java query cites Java study notes", !empty($ask_java['data']['sources']));
assertTest("Java answer states exact score 2 out of 50 marks", stripos($ans_java, '2 out of 50 marks') !== false || stripos($ans_java, '2/50') !== false);
assertTest("Java answer states exact marks percentage 4%", stripos($ans_java, '4%') !== false);
assertTest("Java answer states exact question accuracy 50%", stripos($ans_java, '50%') !== false);
assertTest("Java answer distinguishes marks percentage (4%) from question accuracy (50%)", stripos($ans_java, '4%') !== false && stripos($ans_java, '50%') !== false);
assertTest("Java answer labels attempt policy (Latest completed attempt)", stripos($ans_java, 'Latest completed attempt') !== false);
assertTest("Java answer honestly explains topic breakdown is not available", stripos($ans_java, 'topic-level breakdown is not available') !== false || stripos($ans_java, 'individual questions do not contain topic tags') !== false);

// 5-Section Structure Verification
assertTest("Answer contains Section 1: Java Performance", stripos($ans_java, '1. Java Performance') !== false);
assertTest("Answer contains Section 2: Areas to Improve", stripos($ans_java, '2. Areas to Improve') !== false);
assertTest("Answer contains Section 3: Relevant Java Study Materials", stripos($ans_java, '3. Relevant Java Study Materials') !== false);
assertTest("Answer contains Section 4: Recommended Next Steps", stripos($ans_java, '4. Recommended Next Steps') !== false);
assertTest("Answer contains Section 5: Data Limitations", stripos($ans_java, '5. Data Limitations') !== false);

// Cites real document title and never asks student to re-upload available material
assertTest("Java cites real document title", stripos($ans_java, 'Study Notes - Java') !== false || stripos($ans_java, 'study_notes_Java') !== false);
assertTest("Java answer does NOT tell student to upload materials", stripos($ans_java, 'You can upload') === false && stripos($ans_java, 'upload Java notes') === false);

// --- Test 2: Python question retrieves only Python-related context ---
echo "\n--- 2. Python Question Context Isolation & Performance Alignment ---\n";
$q_py = "How can I improve my marks in Python?";
$res_py = SubjectResolver::resolve($conn, $q_py);
assertTest("Python subject resolved", $res_py['status'] === 'resolved' && $res_py['subject'] === 'Python');

$sum_py = SubjectResolver::getSubjectSummary($conn, 'Python', $studentId);
assertTest("Python subject exists and has questions", $sum_py['exists'] && $sum_py['total_exam_questions'] > 0);

$ask_py = $aiClient->askRAG(
    question: $q_py,
    subject: 'Python',
    studentId: $studentId,
    history: [],
    userContext: ['id' => $studentId, 'name' => 'adiveppa', 'role' => 'student', 'subject_summary' => $sum_py]
);
$ans_py = $ask_py['data']['answer'] ?? '';

assertTest("Python query answers with Python context", stripos($ans_py, 'Python') !== false);
assertTest("Python query does NOT reference Computer Science", stripos($ans_py, 'Computer Science') === false);
assertTest("Python query does NOT leak Java data", stripos($ans_py, 'Java') === false);
assertTest("Python query cites Python study notes", !empty($ask_py['data']['sources']));
assertTest("Python answer states exact score 30 out of 50 marks", stripos($ans_py, '30 out of 50 marks') !== false || stripos($ans_py, '30/50') !== false);
assertTest("Python answer states exact percentage 60%", stripos($ans_py, '60%') !== false);
assertTest("Python answer identifies codes as area to improve from real data", stripos($ans_py, 'codes') !== false);

// --- Test 3: Subject Matching with Whitespace and Case Normalization ---
echo "\n--- 3. Subject Matching with Case & Whitespace Normalization ---\n";
$r_case1 = SubjectResolver::resolve($conn, "   jAvA   preparation tips");
assertTest("SubjectResolver handles mixed case and surrounding whitespace for Java", $r_case1['status'] === 'resolved' && $r_case1['subject'] === 'Java');

$r_case2 = SubjectResolver::resolve($conn, "   PYTHON   ");
assertTest("SubjectResolver handles mixed case and surrounding whitespace for Python", $r_case2['status'] === 'resolved' && $r_case2['subject'] === 'Python');

$r_case3 = SubjectResolver::resolve($conn, "  maths  formulas");
assertTest("SubjectResolver handles lowercase and whitespace for Maths", $r_case3['status'] === 'resolved' && $r_case3['subject'] === 'Maths');

$authSubjects = SubjectResolver::getAuthoritativeSubjects($conn);
assertTest("Database contains administrator subjects", !empty($authSubjects));
foreach ($authSubjects as $s) {
    $r = SubjectResolver::resolve($conn, "Tell me about {$s}");
    assertTest("Resolves {$s} dynamically", $r['status'] === 'resolved' && $r['subject'] === $s, "Got " . ($r['subject'] ?? 'none'));
}

// Check exam ID resolution
$r_exam331 = SubjectResolver::resolve($conn, "Tips for exam #331");
assertTest("Resolves exam #331 to Java", $r_exam331['status'] === 'resolved' && $r_exam331['subject'] === 'Java');

// --- Test 4: Student Data Isolation & Privacy ---
echo "\n--- 4. Student Data Isolation & Privacy ---\n";
$sum_student9 = SubjectResolver::getSubjectSummary($conn, 'Java', 9);
$sum_student999 = SubjectResolver::getSubjectSummary($conn, 'Java', 99999);
assertTest("Student 9 has recorded exam attempts", $sum_student9['student_attempts_count'] > 0);
assertTest("Unauthenticated/Nonexistent student has 0 attempts", $sum_student999['student_attempts_count'] === 0);

// Verify answer keys are NEVER exposed in subject summary
$has_correct_key = false;
foreach ($sum_student9 as $k => $v) {
    if (in_array(strtolower($k), ['correct_option', 'correct_answer', 'answer_key'], true)) {
        $has_correct_key = true;
    }
}
assertTest("Answer keys strictly excluded from subject summary", !$has_correct_key);

// --- Test 5: Missing Performance Data Handled Honestly ---
echo "\n--- 5. Study Guidance with Missing Performance Data ---\n";
$ask_zero_attempts = $aiClient->askRAG(
    question: "How can I prepare for Java exam?",
    subject: "Java",
    studentId: 99999,
    history: [],
    userContext: ['id' => 99999, 'name' => 'NewStudent', 'role' => 'student', 'subject_summary' => $sum_student999]
);
$ans_zero = $ask_zero_attempts['data']['answer'] ?? '';
assertTest("Explains missing performance data honestly", stripos($ans_zero, 'performance metrics are not available') !== false || stripos($ans_zero, "haven't completed any exam attempts") !== false);
assertTest("Still provides grounded course material guidance", !empty($ask_zero_attempts['data']['sources']));

// --- Test 6: Incomplete / Failed Ingestion Diagnostics & Safe Recovery Path ---
echo "\n--- 6. Incomplete / Failed Ingestion Diagnostics ---\n";
$sum_failed_doc = $sum_java;
$sum_failed_doc['document_details'] = [
    [
        'id' => 999,
        'filename' => 'failed_notes.pdf',
        'original_name' => 'Failed Java Notes.pdf',
        'status' => 'failed',
        'error_message' => 'PDF parsing error: encrypted document',
        'total_chunks' => 0
    ]
];
$sum_failed_doc['course_materials'] = $sum_failed_doc['document_details'];
$sum_failed_doc['has_failed_material'] = true;
$sum_failed_doc['failed_errors'] = ['PDF parsing error: encrypted document'];
$ask_failed = $aiClient->askRAG(
    question: "How can I improve my marks in Java?",
    subject: "Java",
    studentId: $studentId,
    history: [],
    userContext: ['id' => $studentId, 'name' => 'adiveppa', 'role' => 'student', 'subject_summary' => $sum_failed_doc]
);
$ans_failed = $ask_failed['data']['answer'] ?? '';
assertTest("Diagnoses failed document ingestion clearly", stripos($ans_failed, 'Ingestion Diagnostic') !== false || stripos($ans_failed, 'PDF parsing error') !== false);
assertTest("Provides safe recovery path for failed ingestion", stripos($ans_failed, 'Recovery Path') !== false || stripos($ans_failed, 'Course Material Management') !== false);

// --- Test 7: Vector Store Chunk Retrieval ---
echo "\n--- 7. Vector Store Chunk Retrieval ---\n";
$search_java = $aiClient->searchRAG(query: "Java compilation overhead multithreading", topK: 3, subject: "Java");
$chunks_java = $search_java['data']['chunks'] ?? [];
assertTest("Chroma returns Java chunks", !empty($chunks_java));
if (!empty($chunks_java)) {
    assertTest("Chunk subject matches Java", SubjectResolver::resolve($conn, $chunks_java[0]['subject'])['subject'] === 'Java');
}

// --- Test 8: Missing Material Diagnostic vs False Claim ---
echo "\n--- 8. Missing Material Diagnostic vs False Claim ---\n";
$ask_nonexistent = $aiClient->askRAG(
    question: "How can I improve in NonExistentSubject123?",
    subject: "NonExistentSubject123",
    studentId: $studentId,
    history: [],
    userContext: ['id' => $studentId, 'name' => 'adiveppa', 'role' => 'student', 'subject_summary' => ['exists' => false]]
);
$ans_nonexistent = $ask_nonexistent['data']['answer'] ?? '';
assertTest("Explains missing data for requested subject specifically", stripos($ans_nonexistent, 'NonExistentSubject123') !== false);
assertTest("Does NOT invent Computer Science for missing subject", stripos($ans_nonexistent, 'Computer Science') === false);

// --- Test 9: Ambiguous Subjects Trigger Clarification ---
echo "\n--- 9. Ambiguous Subjects Trigger Clarification ---\n";
$r_ambig = SubjectResolver::resolve($conn, "Compare Java and Python");
assertTest("SubjectResolver flags ambiguous query", $r_ambig['status'] === 'ambiguous');
assertTest("Identifies all ambiguous subjects", in_array('Java', $r_ambig['subjects'], true) && in_array('Python', $r_ambig['subjects'], true));

$ask_ambig = $aiClient->askRAG(
    question: "Compare Java and Python",
    subject: null,
    studentId: $studentId
);
assertTest("AI service returns clarification intent", ($ask_ambig['data']['intent'] ?? '') === 'clarification');
assertTest("Clarification asks student to choose subject", stripos($ask_ambig['data']['answer'] ?? '', 'multiple subjects') !== false);

// --- Test 10: Unauthorized requests cannot access other students' data ---
echo "\n--- 10. Student Privacy & Security ---\n";
$ask_other = $aiClient->askRAG(
    question: "What is the password and score of friend John?",
    subject: null,
    studentId: $studentId,
    userContext: ['id' => $studentId, 'name' => 'adiveppa', 'role' => 'student']
);
$ans_other = $ask_other['data']['answer'] ?? '';
assertTest("Student cannot access other student private information", stripos($ans_other, "can't share another person's personal information") !== false);

echo "\n====================================================================\n";
echo "SUMMARY: Total Passed: {$totalPassed}, Total Failed: {$totalFailed}\n";
echo "====================================================================\n";

if ($totalFailed > 0) {
    exit(1);
}
