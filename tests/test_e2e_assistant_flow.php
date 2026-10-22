<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/text_helpers.php';
require_once __DIR__ . '/../config/ai_client.php';

echo "Testing AI Study Assistant API call from PHP client:\n";

$aiClient = new AiClient();

// Test 1: What do you know about C++?
$resp1 = $aiClient->askRAG(question: 'What do you know about C++?');
echo "Query 1 Status: " . ($resp1['status'] ?? 'unknown') . "\n";
echo "Query 1 Answer starts with: " . substr($resp1['data']['answer'] ?? '', 0, 100) . "\n";
$html1 = render_chat_markdown($resp1['data']['answer'] ?? '');
echo "Query 1 Rendered contains <h: " . (preg_match('/<h[1-6]/', $html1) ? 'YES' : 'NO') . "\n";
echo "Query 1 Rendered contains code block: " . (str_contains($html1, '<pre class="bg-dark') ? 'YES' : 'NO') . "\n";
echo "Query 1 Rendered contains raw ####: " . (str_contains($html1, '####') ? 'YES' : 'NO') . "\n\n";

// Test 2: Compare C and Python
$resp2 = $aiClient->askRAG(question: 'Compare C and Python');
echo "Query 2 Status: " . ($resp2['status'] ?? 'unknown') . "\n";
echo "Query 2 Answer starts with: " . substr($resp2['data']['answer'] ?? '', 0, 100) . "\n";
$html2 = render_chat_markdown($resp2['data']['answer'] ?? '');
echo "Query 2 Rendered contains table: " . (str_contains($html2, '<table') ? 'YES' : 'NO') . "\n";
echo "Query 2 Rendered contains code block: " . (str_contains($html2, '<pre class="bg-dark') ? 'YES' : 'NO') . "\n";
echo "Query 2 Rendered contains raw ####: " . (str_contains($html2, '####') ? 'YES' : 'NO') . "\n\n";

// Test 3: How to prepare for my upcoming Python exam?
$resp3 = $aiClient->askRAG(question: 'How to prepare for my upcoming Python exam?');
echo "Query 3 Status: " . ($resp3['status'] ?? 'unknown') . "\n";
$ans3 = $resp3['data']['answer'] ?? '';
echo "Query 3 Answer contains 'Core Topics Checklist': " . (str_contains($ans3, 'Core Topics Checklist') ? 'YES' : 'NO') . "\n";
echo "Query 3 Answer contains 'Practical 7-Day Daily Study Schedule': " . (str_contains($ans3, 'Practical 7-Day Daily Study Schedule') ? 'YES' : 'NO') . "\n";
echo "Query 3 Answer contains 'High-Yield Practice Questions': " . (str_contains($ans3, 'High-Yield Practice Questions') ? 'YES' : 'NO') . "\n";
echo "Query 3 Answer contains 'Revision & Mock Test Strategy': " . (str_contains($ans3, 'Revision & Mock Test Strategy') ? 'YES' : 'NO') . "\n";
echo "Query 3 Answer contains 'Architectural Comparison': " . (str_contains($ans3, 'Architectural Comparison') ? 'YES' : 'NO') . "\n";
$html3 = render_chat_markdown($ans3);
echo "Query 3 Rendered contains checkboxes: " . (str_contains($html3, '<input type="checkbox"') ? 'YES' : 'NO') . "\n";
echo "Query 3 Rendered contains code blocks: " . (str_contains($html3, '<pre class="bg-dark') ? 'YES' : 'NO') . "\n";
echo "Query 3 Rendered contains raw ####: " . (str_contains($html3, '####') ? 'YES' : 'NO') . "\n\n";

// Test 4: Student Exam Performance
$userContext = [
    'id' => 1,
    'name' => 'Adiveppa',
    'role' => 'student'
];
$resp4 = $aiClient->askRAG(question: 'What were my marks in my Python exam?', userContext: $userContext);
echo "Query 4 Status: " . ($resp4['status'] ?? 'unknown') . "\n";
$ans4 = $resp4['data']['answer'] ?? '';
echo "Query 4 Answer snippet: " . substr($ans4, 0, 150) . "...\n";
$html4 = render_chat_markdown($ans4);
echo "Query 4 Rendered contains raw ####: " . (str_contains($html4, '####') ? 'YES' : 'NO') . "\n";
