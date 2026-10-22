<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';
require_once __DIR__ . '/../config/text_helpers.php';
require_once __DIR__ . '/../config/subject_resolver.php';

$client = new AiClient();

$queries = [
    'How to prepare for Java exam?',
    'How can I improve my Java performance?',
    'Compare Java and Python',
    'Explain C++',
    'How to prepare for C++?'
];

foreach ($queries as $q) {
    echo "========================================================\n";
    echo "QUERY: {$q}\n";
    
    // Resolve subject as done in student/study_assistant.php
    $resSubj = SubjectResolver::resolve($conn, $q);
    $resolvedSubject = $resSubj['subject'] ?? null;
    echo "Resolved Subject: " . ($resolvedSubject ?? 'None') . "\n";
    
    $userContext = ['id' => 1, 'name' => 'Student'];
    if ($resolvedSubject) {
        $userContext['subject_summary'] = SubjectResolver::getSubjectSummary($conn, $resolvedSubject, 1);
    }
    
    $res = $client->askRAG(
        question: $q,
        subject: $resolvedSubject,
        userContext: $userContext
    );
    
    $data = $res['data'] ?? [];
    echo "Intent: " . ($data['intent'] ?? 'N/A') . "\n";
    echo "Sources count: " . count($data['sources'] ?? []) . "\n";
    $answer = $data['answer'] ?? ($res['message'] ?? 'ERROR');
    echo "Answer snippet (first 350 chars):\n" . substr($answer, 0, 350) . "...\n\n";
}
