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

        // Ensure clean punctuation at the end of question title if missing
        if ($cleaned !== '' && !preg_match('/[?.!]$/u', $cleaned)) {
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

