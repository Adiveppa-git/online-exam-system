<?php
/**
 * Test Suite: AI Chat Markdown Rendering & XSS Safety
 * Verifies Issue 2:
 * - Headings # through ###### (no raw #### markers)
 * - Fenced code blocks with syntax highlighting classes and safe escaping
 * - Markdown tables formatted as clean Bootstrap tables
 * - Ordered and unordered lists
 * - Blockquotes
 * - Safe links and XSS sanitization
 */

require_once __DIR__ . '/../config/text_helpers.php';

$totalPassed = 0;
$totalFailed = 0;

function assertTest(string $desc, bool $condition, string $detail = '') {
    global $totalPassed, $totalFailed;
    if ($condition) {
        $totalPassed++;
        echo "[PASS] {$desc}\n";
    } else {
        $totalFailed++;
        echo "[FAIL] {$desc} " . ($detail ? "({$detail})" : "") . "\n";
    }
}

echo "====================================================\n";
echo "   AI Chat Markdown Rendering & Safety Test Suite   \n";
echo "====================================================\n\n";

// 1. Heading rendering without raw markers
$rawHeadings = "#### Quick Reference Comparison Table\n### Architectural Comparison\n# Top Heading\n##### Minor Level 5";
$renderedHeadings = render_chat_markdown($rawHeadings);
assertTest(
    "1. Headings # through ###### render without raw # markers",
    !str_contains($renderedHeadings, '####') &&
    !str_contains($renderedHeadings, '###') &&
    str_contains($renderedHeadings, 'Quick Reference Comparison Table') &&
    str_contains($renderedHeadings, '<h5 class="fw-bold text-dark')
);

// 2. Fenced code block preservation and escaping
$rawCode = "```python\ndef print_evens(max_val):\n    for i in range(0, max_val + 1, 2):\n        print(i)\n```";
$renderedCode = render_chat_markdown($rawCode);
assertTest(
    "2. Fenced code blocks render inside <pre><code class=\"language-python\"> with proper indentation",
    str_contains($renderedCode, '<pre class="bg-dark text-light') &&
    str_contains($renderedCode, 'language-python') &&
    str_contains($renderedCode, '    for i in range(0, max_val + 1, 2):')
);

// 3. Fenced code block XSS safety
$maliciousCode = "```html\n<script>alert('xss')</script>\n<img src=x onerror=alert(1)>\n```";
$renderedMaliciousCode = render_chat_markdown($maliciousCode);
assertTest(
    "3. Fenced code blocks escape HTML and prevent script execution",
    str_contains($renderedMaliciousCode, '&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;') &&
    !str_contains($renderedMaliciousCode, '<script>')
);

// 4. Markdown tables render into Bootstrap table structure
$rawTable = "| Feature / Dimension | C++ | Python |\n| :--- | :--- | :--- |\n| **Execution Model** | Compiled directly | Interpreted |";
$renderedTable = render_chat_markdown($rawTable);
assertTest(
    "4. Markdown tables render into Bootstrap table with thead, tbody, and th/td",
    str_contains($renderedTable, '<div class="table-responsive my-2">') &&
    str_contains($renderedTable, '<thead class="table-light">') &&
    str_contains($renderedTable, '<th class="fw-bold text-dark') &&
    str_contains($renderedTable, '<strong>Execution Model</strong>') &&
    str_contains($renderedTable, '<td class="py-2 px-3">Compiled directly</td>')
);

// 5. Ordered and unordered lists
$rawLists = "- First bullet\n- Second bullet\n\n1. Step one\n2. Step two";
$renderedLists = render_chat_markdown($rawLists);
assertTest(
    "5. Unordered and ordered lists render as <ul> and <ol> with <li> elements",
    str_contains($renderedLists, '<ul class="mb-2 ps-3">') &&
    str_contains($renderedLists, '<li class="mb-1">First bullet</li>') &&
    str_contains($renderedLists, '<ol class="mb-2 ps-3">') &&
    str_contains($renderedLists, '<li class="mb-1">Step one</li>')
);

// 6. Blockquote rendering
$rawQuote = "> This is a key architectural insight for exam preparation.";
$renderedQuote = render_chat_markdown($rawQuote);
assertTest(
    "6. Blockquotes render as styled <blockquote> tags",
    str_contains($renderedQuote, '<blockquote class="border-start border-3 border-primary') &&
    str_contains($renderedQuote, 'This is a key architectural insight')
);

// 7. Inline code and formatting
$rawInline = "Use the `new` operator in C++ and `malloc()` in C with **bold** and *italic* text.";
$renderedInline = render_chat_markdown($rawInline);
assertTest(
    "7. Inline code, bold, and italic render properly",
    str_contains($renderedInline, '<code class="bg-light text-danger') &&
    str_contains($renderedInline, '`') === false &&
    str_contains($renderedInline, '<strong>bold</strong>') &&
    str_contains($renderedInline, '<em>italic</em>')
);

// 8. Safe links and javascript: protocol blocking
$safeLink = "Check the [Course Material](view_course_material.php?id=10&source=assistant) for details.";
$unsafeLink = "Click [here](javascript:alert(1)) to hack.";
$renderedSafe = render_chat_markdown($safeLink);
$renderedUnsafe = render_chat_markdown($unsafeLink);
assertTest(
    "8. Safe links are rendered as <a> tags while javascript: links are blocked",
    str_contains($renderedSafe, '<a href="view_course_material.php?id=10&amp;source=assistant"') &&
    !str_contains($renderedUnsafe, '<a href="javascript:') &&
    !str_contains($renderedUnsafe, 'javascript:alert')
);

// 9. Document Auto-linking
$docLookup = [
    'by_id' => [278 => ['id' => 278, 'filename' => 'Python_Guide.md']],
    'by_name' => ['python_guide.md' => ['id' => 278, 'filename' => 'Python_Guide.md']]
];
$rawDocLine = "- **Document Title:** `Python_Guide.md` (ID: #278)";
$renderedDocLine = render_chat_markdown($rawDocLine, $docLookup);
assertTest(
    "9. Document Title lines auto-link to view_course_material.php with ID and source",
    str_contains($renderedDocLine, 'view_course_material.php?id=278') &&
    str_contains($renderedDocLine, 'source=assistant')
);

// 10. Checkboxes
$rawChecklist = "- [ ] Master core language syntax\n- [x] Complete practice quiz";
$renderedChecklist = render_chat_markdown($rawChecklist);
assertTest(
    "10. Checkboxes render as disabled checkbox inputs",
    str_contains($renderedChecklist, '<input type="checkbox" disabled') &&
    str_contains($renderedChecklist, '<input type="checkbox" checked disabled')
);

// 11. Horizontal Rule
$rawHr = "Section A\n---\nSection B";
$renderedHr = render_chat_markdown($rawHr);
assertTest(
    "11. Horizontal rules render as styled <hr> tags",
    str_contains($renderedHr, '<hr class="my-3 border-secondary opacity-25">')
);

// 12. Headings with trailing hashes
$rawHashHeading = "### Python Preparation Strategy ###";
$renderedHashHeading = render_chat_markdown($rawHashHeading);
assertTest(
    "12. Headings with trailing hashes render cleanly without hashes",
    str_contains($renderedHashHeading, 'Python Preparation Strategy') &&
    !str_contains($renderedHashHeading, '#')
);

echo "\n----------------------------------------------------\n";
echo "Summary: {$totalPassed} Passed, {$totalFailed} Failed\n";
echo "====================================================\n";

if ($totalFailed > 0) {
    exit(1);
}
exit(0);
