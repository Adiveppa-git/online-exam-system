<?php
/**
 * Text & Question Formatting Helpers
 */

if (!function_exists('clean_question_title')) {
    /**
     * Removes unwanted artificial variant suffixes (e.g. " (Variant 48)", " (Variant 6)", " (variant 1)")
     * from question titles while preserving legitimate uses of the word when part of the question's actual meaning.
     *
     * @param string|null $text
     * @return string
     */
    function clean_question_title(?string $text): string {
        if ($text === null) {
            return '';
        }
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        // Narrowly targeted regex: removes only trailing "(Variant <digits>)" or "(variant <digits>)"
        $cleaned = preg_replace('/\s*\([Vv]ariant\s+\d+\)\s*$/u', '', $trimmed);
        $cleaned = trim($cleaned);

        // Strip any trailing question marks mistakenly attached to markdown code fences
        $cleaned = preg_replace('/```\s*\?+$/u', '```', $cleaned);

        // Ensure clean punctuation at the end of question title if missing,
        // but avoid appending '?' to closing markdown code block fences (```)
        if ($cleaned !== '' 
            && !preg_match('/[?.!]$/u', $cleaned)
            && !str_ends_with($cleaned, '```')
        ) {
            $cleaned .= '?';
        }

        return $cleaned;
    }
}

if (!function_exists('normalize_question_text')) {
    /**
     * Normalizes question text for robust deduplication.
     * Ignores differences in capitalization, extra spaces, punctuation, and unwanted Variant N suffix.
     *
     * @param string|null $text
     * @return string
     */
    function normalize_question_text(?string $text): string {
        if ($text === null) {
            return '';
        }
        // First strip any trailing artificial variant suffixes
        $cleaned = clean_question_title($text);
        if ($cleaned === '') {
            return '';
        }
        // Lowercase
        $lower = mb_strtolower($cleaned, 'UTF-8');
        // Strip punctuation and special characters, replace with spaces
        $noPunct = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $lower);
        // Collapse multiple spaces into single space and trim
        $normalized = preg_replace('/\s+/u', ' ', trim($noPunct));
        return $normalized;
    }
}

if (!function_exists('is_question_duplicate')) {
    /**
     * Checks if candidate question is an exact duplicate or highly similar duplicate
     * against a list of already normalized existing questions.
     *
     * @param string $candidate
     * @param array $existingNormalizedQuestions Array of normalized strings
     * @param float $charSimilarityThreshold Percentage (0-100), default 88.0
     * @param float $wordJaccardThreshold Percentage (0-100), default 85.0
     * @return bool
     */
    function is_question_duplicate(string $candidate, array $existingNormalizedQuestions, float $charSimilarityThreshold = 88.0, float $wordJaccardThreshold = 85.0): bool {
        $candNorm = normalize_question_text($candidate);
        if ($candNorm === '') {
            return false;
        }

        $candWords = array_values(array_filter(explode(' ', $candNorm)));
        $candWordCount = count($candWords);
        $candWordUnique = array_unique($candWords);

        foreach ($existingNormalizedQuestions as $existNorm) {
            if ($existNorm === '') {
                continue;
            }

            // 1. Exact normalized match
            if ($candNorm === $existNorm) {
                return true;
            }

            // 2. Character similarity (via Oliver/similar_text)
            similar_text($candNorm, $existNorm, $charPercent);
            if ($charPercent >= $charSimilarityThreshold) {
                return true;
            }

            // 3. Word token Jaccard similarity for multi-word questions (>= 4 words)
            if ($candWordCount >= 4) {
                $existWords = array_values(array_filter(explode(' ', $existNorm)));
                $existWordCount = count($existWords);
                if ($existWordCount >= 4) {
                    $existWordUnique = array_unique($existWords);
                    $common = count(array_intersect($candWordUnique, $existWordUnique));
                    $union = count(array_unique(array_merge($candWordUnique, $existWordUnique)));
                    if ($union > 0) {
                        $jaccard = ($common / $union) * 100.0;
                        if ($jaccard >= $wordJaccardThreshold) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }
}

if (!function_exists('render_chat_markdown')) {
    /**
     * Renders Markdown text safely in the AI chat window.
     * All raw text is HTML-escaped to prevent XSS.
     * Supports bold (**text**), italic (*text*), inline code (`code`),
     * bullet lists (- or *), and clean line/paragraph breaks.
     *
     * @param string|null $text
     * @return string
     */
    function render_chat_markdown(?string $text): string {
        if ($text === null || trim($text) === '') {
            return '';
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $html = [];
        $inList = false;

        $formatInline = function(string $str): string {
            $str = htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
            // Inline code: `code`
            $str = preg_replace('/`([^`]+)`/', '<code class="bg-light text-danger px-1 rounded border" style="font-size:0.9em;">$1</code>', $str);
            // Bold: **text**
            $str = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $str);
            // Italic: *text*
            $str = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $str);
            return $str;
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $html[] = '<div class="my-2"></div>';
                continue;
            }

            // Bullet list: starts with "- " or "* "
            if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $m)) {
                if (!$inList) {
                    $html[] = '<ul class="mb-2 ps-3">';
                    $inList = true;
                }
                $html[] = '<li class="mb-1">' . $formatInline($m[1]) . '</li>';
                continue;
            }

            // End list if a non-bullet line is encountered
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }

            // Header line: ### or ##
            if (preg_match('/^#{1,3}\s+(.*)$/', $trimmed, $m)) {
                $html[] = '<div class="fw-bold text-dark mt-2 mb-1">' . $formatInline($m[1]) . '</div>';
                continue;
            }

            $html[] = '<div>' . $formatInline($trimmed) . '</div>';
        }

        if ($inList) {
            $html[] = '</ul>';
        }

        return implode("\n", $html);
    }
}

if (!function_exists('format_math_notation')) {
    /**
     * Converts raw comparison and exponential notation to clean mathematical typography.
     * e.g., "1 <= length(S) <= 10^5"  ->  "1 ≤ length(S) ≤ 10⁵"
     *       "1 <= length(S) <= 2 * 10^5" -> "1 ≤ length(S) ≤ 2 × 10⁵"
     *       "-10^9 <= arr[i] <= 10^9" -> "-10⁹ ≤ arr[i] ≤ 10⁹"
     *       "arr.length >= 1" -> "arr.length ≥ 1"
     *       "x != 0" -> "x ≠ 0"
     *
     * @param string $text
     * @return string
     */
    function format_math_notation(string $text): string {
        // 1. Replace ASCII comparison operators with Unicode mathematical symbols
        $text = str_replace(
            ['<=', '>=', '!='],
            ['≤', '≥', '≠'],
            $text
        );

        // 2. Convert ^exponent (e.g. ^5, ^9, ^10, ^-9, ^31) into Unicode superscripts
        $superscriptMap = [
            '0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴',
            '5' => '⁵', '6' => '⁶', '7' => '⁷', '8' => '⁸', '9' => '⁹',
            '-' => '⁻', '+' => '⁺'
        ];
        $text = preg_replace_callback('/\^([0-9+-]+)/', function($m) use ($superscriptMap) {
            $chars = str_split($m[1]);
            $out = '';
            foreach ($chars as $c) {
                $out .= $superscriptMap[$c] ?? $c;
            }
            return $out;
        }, $text);

        // 3. Convert multiplication asterisk into multiplication sign × when between numbers/exponents
        $text = preg_replace('/(\d+)\s*\*\s*(\d+|10)/u', '$1 × $2', $text);

        return $text;
    }
}

if (!function_exists('parse_coding_constraints')) {
    /**
     * Parses a raw constraints string into structured components:
     * - constraints: array of clean mathematical constraint strings
     * - time_limit: extracted time limit string or null
     * - memory_limit: extracted memory limit string or null
     *
     * @param string|null $raw
     * @return array{constraints: array<string>, time_limit: ?string, memory_limit: ?string}
     */
    function parse_coding_constraints(?string $raw): array {
        if ($raw === null || trim($raw) === '') {
            return [
                'constraints' => [],
                'time_limit' => null,
                'memory_limit' => null
            ];
        }

        $text = trim($raw);
        $timeLimit = null;
        $memoryLimit = null;

        // 1. Extract Time Limit (e.g. "Time Limit: 1.0s", "Time limit: 2s", "TL: 1.0s")
        if (preg_match('/(?:Time\s*Limit|TL)\s*[:=]\s*([0-9.]+\s*(?:s|ms|seconds?|sec))\b/i', $text, $m)) {
            $timeLimit = trim($m[1]);
            $text = str_replace($m[0], '', $text);
        } elseif (preg_match('/(?:Time\s*Limit|TL)\s*[:=]\s*([^\r\n|;,]+)/i', $text, $m)) {
            $timeLimit = trim($m[1]);
            $text = str_replace($m[0], '', $text);
        }

        // 2. Extract Memory Limit (e.g. "Memory Limit: 256MB", "Memory limit: 512 MB", "ML: 256MB")
        if (preg_match('/(?:Memory\s*Limit|ML)\s*[:=]\s*([0-9.]+\s*(?:MB|GB|KB|megabytes?|gigabytes?))\b/i', $text, $m)) {
            $memoryLimit = trim($m[1]);
            $text = str_replace($m[0], '', $text);
        } elseif (preg_match('/(?:Memory\s*Limit|ML)\s*[:=]\s*([^\r\n|;,]+)/i', $text, $m)) {
            $memoryLimit = trim($m[1]);
            $text = str_replace($m[0], '', $text);
        }

        // 3. Strip any leftover "Constraints:" or "Constraint:" prefix
        $text = preg_replace('/^\s*(?:Constraints?)\s*[:=]\s*/i', '', $text);

        // 4. Split remaining text into constraint items by newline, pipe, or semicolon
        $rawParts = preg_split('/[\r\n|;]+/u', $text);
        $constraints = [];
        foreach ($rawParts as $part) {
            $cleaned = trim($part, " \t\n\r\0\x0B,;|");
            // Do not strip leading minus from negative numbers like -10^9
            $cleaned = preg_replace('/^([*•]|-(?!\d))\s*/u', '', $cleaned);
            $cleaned = trim($cleaned);
            if ($cleaned !== '') {
                $constraints[] = format_math_notation($cleaned);
            }
        }

        return [
            'constraints' => $constraints,
            'time_limit' => $timeLimit,
            'memory_limit' => $memoryLimit
        ];
    }
}

if (!function_exists('render_coding_constraints')) {
    /**
     * Renders a fully formatted, accessible, and responsive coding constraints warning block.
     *
     * @param string|null $raw
     * @return string HTML output
     */
    function render_coding_constraints(?string $raw): string {
        $parsed = parse_coding_constraints($raw);
        if (empty($parsed['constraints']) && empty($parsed['time_limit']) && empty($parsed['memory_limit'])) {
            return '';
        }

        $items = [];

        // 1. Constraints item
        // 1. Constraints item (omitted if none specified)
        if (!empty($parsed['constraints'])) {
            $codePills = [];
            foreach ($parsed['constraints'] as $c) {
                $codePills[] = '<code style="background: #fef3c7; border: 1px solid #fcd34d; padding: 2px 7px; border-radius: 4px; font-family: monospace; font-size: 12px; color: #78350f; display: inline-block;">' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . '</code>';
            }
            $items[] = '<div class="constraint-item" style="display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap;">'
                . '<span style="font-size: 13px;">⚠️</span> <strong>Constraints:</strong> '
                . implode(' ', $codePills)
                . '</div>';
        }

        // 2. Time Limit item
        if (!empty($parsed['time_limit'])) {
            $items[] = '<div class="constraint-item" style="display: inline-flex; align-items: center; gap: 6px; flex-wrap: nowrap;">'
                . '<strong>Time Limit:</strong> '
                . '<code style="background: #fef3c7; border: 1px solid #fcd34d; padding: 2px 7px; border-radius: 4px; font-family: monospace; font-size: 12px; color: #78350f; display: inline-block;">' . htmlspecialchars($parsed['time_limit'], ENT_QUOTES, 'UTF-8') . '</code>'
                . '</div>';
        }

        // 3. Memory Limit item
        if (!empty($parsed['memory_limit'])) {
            $items[] = '<div class="constraint-item" style="display: inline-flex; align-items: center; gap: 6px; flex-wrap: nowrap;">'
                . '<strong>Memory Limit:</strong> '
                . '<code style="background: #fef3c7; border: 1px solid #fcd34d; padding: 2px 7px; border-radius: 4px; font-family: monospace; font-size: 12px; color: #78350f; display: inline-block;">' . htmlspecialchars($parsed['memory_limit'], ENT_QUOTES, 'UTF-8') . '</code>'
                . '</div>';
        }

        $html = '<div class="coding-constraints-box" style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; font-size: 13px; color: #92400e; display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px;">';
        $html .= implode('<span class="constraints-divider" style="color: #fcd34d; user-select: none;">•</span>', $items);
        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('render_exam_question')) {
    /**
     * Renders a question with clean separation between the question title and problem statement/description.
     * Multiline content is displayed with preserved line breaks and paragraphs.
     * Code blocks within the question are extracted from Markdown fences (e.g. ```java, ```python)
     * and rendered inside dark, scrollable code blocks, without displaying raw fence markers in headings.
     * All output is escaped safely against XSS and HTML injection.
     *
     * @param string|null $rawQuestion
     * @param int $questionNumber
     * @return string Safe HTML
     */
    function render_exam_question(?string $rawQuestion, int $questionNumber): string {
        if ($rawQuestion === null || trim($rawQuestion) === '') {
            return '<h2 class="question-title">Q' . $questionNumber . '.</h2>';
        }

        // 1. Normalize line endings and clean trailing artifacts
        $text = str_replace(["\r\n", "\r"], "\n", trim($rawQuestion));
        // Remove artificial variant suffix
        $text = preg_replace('/\s*\([Vv]ariant\s+\d+\)\s*$/u', '', $text);
        // Strip trailing question marks attached to code fences
        $text = preg_replace('/```\s*\?+$/u', '```', $text);
        $text = trim($text);

        // 2. Extract code blocks (tokens) to protect them during paragraph splitting
        $codeBlocks = [];
        $textWithTokens = preg_replace_callback(
            '/```([a-zA-Z0-9_+-]*)\n?([\s\S]*?)```/u',
            function ($m) use (&$codeBlocks) {
                $token = '___CODEBLOCK_' . count($codeBlocks) . '___';
                $lang = trim($m[1]);
                $code = rtrim($m[2]);
                $codeBlocks[$token] = [
                    'lang' => $lang,
                    'code' => $code
                ];
                return "\n\n" . $token . "\n\n";
            },
            $text
        );

        // 3. Split into paragraphs by two or more newlines
        $rawParagraphs = preg_split('/\n{2,}/u', trim($textWithTokens));
        $paragraphs = [];
        foreach ($rawParagraphs as $p) {
            $pTrim = trim($p);
            if ($pTrim !== '') {
                $paragraphs[] = $pTrim;
            }
        }

        if (empty($paragraphs)) {
            return '<h2 class="question-title">Q' . $questionNumber . '.</h2>';
        }

        // Helper for formatting title inline text
        $formatTitleInline = function (string $str, bool $hasDescription): string {
            $str = preg_replace('/\s*\([Vv]ariant\s+\d+\)\s*$/u', '', trim($str));
            $str = preg_replace('/```\s*\?+$/u', '```', $str);
            if (!$hasDescription && $str !== '' && !preg_match('/[?.!]$/u', $str) && !preg_match('/[`}:;>\]]$/u', $str)) {
                $str .= '?';
            }
            $escaped = htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
            $escaped = preg_replace('/`([^`]+)`/u', '<code class="inline-code-pill">$1</code>', $escaped);
            $escaped = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $escaped);
            return $escaped;
        };

        // Helper for regular paragraph inline text (preserves terminal punctuation and line breaks)
        $formatParagraphInline = function (string $str): string {
            $escaped = htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
            $escaped = preg_replace('/`([^`]+)`/u', '<code class="inline-code-pill">$1</code>', $escaped);
            $escaped = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $escaped);
            return nl2br($escaped);
        };

        $firstPara = array_shift($paragraphs);
        $descBlocks = [];
        $titleText = '';

        // Check if first paragraph is a code block token
        if (isset($codeBlocks[$firstPara])) {
            $titleText = 'Problem Statement';
            $descBlocks[] = $firstPara;
        } else {
            $lines = explode("\n", $firstPara);
            if (count($lines) > 1 && strlen(trim($lines[0])) < 120 && !preg_match('/[:]$/u', trim($lines[0]))) {
                $titleText = array_shift($lines);
                $remaining = trim(implode("\n", $lines));
                if ($remaining !== '') {
                    $descBlocks[] = $remaining;
                }
            } else {
                $titleText = $firstPara;
            }
        }

        // Add remaining paragraphs
        foreach ($paragraphs as $p) {
            $descBlocks[] = $p;
        }

        $hasDescription = !empty($descBlocks);
        $titleHtml = '<h2 class="question-title">Q' . $questionNumber . '. ' . $formatTitleInline($titleText, $hasDescription) . '</h2>';

        // Render description HTML
        $descHtml = '';
        if (!empty($descBlocks)) {
            $descHtml .= '<div class="question-description">';
            foreach ($descBlocks as $block) {
                if (isset($codeBlocks[$block])) {
                    $cb = $codeBlocks[$block];
                    $langLabel = !empty($cb['lang']) ? htmlspecialchars(strtoupper($cb['lang']), ENT_QUOTES, 'UTF-8') : '';
                    $langClass = !empty($cb['lang']) ? ' language-' . htmlspecialchars(strtolower($cb['lang']), ENT_QUOTES, 'UTF-8') : '';
                    
                    $descHtml .= '<div class="code-block-wrapper">';
                    if ($langLabel !== '') {
                        $descHtml .= '<div class="code-block-header"><span class="code-block-badge">' . $langLabel . '</span></div>';
                    }
                    $descHtml .= '<pre class="code-block-dark"><code class="' . $langClass . '">' . htmlspecialchars($cb['code'], ENT_QUOTES, 'UTF-8') . '</code></pre>';
                    $descHtml .= '</div>';
                } else {
                    $descHtml .= '<p class="question-p">' . $formatParagraphInline($block) . '</p>';
                }
            }
            $descHtml .= '</div>';
        }

        return $titleHtml . ($descHtml !== '' ? "\n" . $descHtml : '');
    }
}



