<?php
/**
 * Text & Question Formatting Helpers
 */

if (!function_exists('safe_unescape_exam_code')) {
    /**
     * Safely converts literal escaped newlines (\n, \r\n) to actual newlines,
     * while preserving in-string escapes (e.g. printf("...\n")) by tokenizing quoted string literals.
     *
     * @param string|null $code
     * @return string
     */
    function safe_unescape_exam_code(?string $code): string {
        if ($code === null) {
            return '';
        }
        if (!str_contains($code, '\r\n') && !str_contains($code, '\n')) {
            return str_replace(["\r\n", "\r"], "\n", $code);
        }

        $tokens = preg_split('/("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')/s', $code, -1, PREG_SPLIT_DELIM_CAPTURE);
        $unescaped = '';
        foreach ($tokens as $idx => $token) {
            if ($idx % 2 === 1) {
                // Inside string literal: keep untouched
                $unescaped .= $token;
            } else {
                // Outside string literal: convert escaped newlines
                $token = preg_replace('/(?<!\\\\)\\\\r\\\\n/', "\r\n", $token);
                $token = preg_replace('/(?<!\\\\)\\\\n/', "\n", $token);
                $unescaped .= $token;
            }
        }
        return str_replace(["\r\n", "\r"], "\n", $unescaped);
    }
}

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

        // Unescape literal escaped newlines safely if stored/passed with literal \n
        $trimmed = safe_unescape_exam_code($trimmed);

        // Narrowly targeted regex: removes only trailing "(Variant <digits>)" or "(variant <digits>)"
        $cleaned = preg_replace('/\s*\([Vv]ariant\s+\d+\)\s*$/u', '', $trimmed);
        $cleaned = trim($cleaned);

        // Strip any trailing question marks mistakenly attached to markdown code fences
        $cleaned = preg_replace('/```\s*\?+$/u', '```', $cleaned);

        // Ensure clean punctuation at the end of question title if missing,
        // but avoid appending '?' to closing markdown code block fences (```) or code statement terminators
        if ($cleaned !== '' 
            && !preg_match('/[?.!]$/u', $cleaned)
            && !preg_match('/[`}:;>\]]$/u', $cleaned)
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
     * bullet lists (- or *), links ([label](url)), and clean line/paragraph breaks.
     *
     * @param string|null $text
     * @param array $docLookup Optional lookup array for auto-linking document titles
     * @return string
     */
    function render_chat_markdown(?string $text, array $docLookup = []): string {
        if ($text === null || trim($text) === '') {
            return '';
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $html = [];

        $inCodeBlock = false;
        $codeLang = '';
        $codeLines = [];

        $inList = false;
        $listType = ''; // 'ul' or 'ol'

        $inTable = false;
        $tableRows = [];

        $inBlockquote = false;
        $quoteLines = [];

        $resolveDocLink = function(string $rawTitle, int $page = 1) use ($docLookup): string {
            $clean = trim($rawTitle, " `\"'\t\n\r");
            if ($clean === '') {
                return "view_course_material.php?page=" . max(1, $page) . "&source=assistant";
            }

            // Check if title has explicit ID like (ID: #278) or #278
            if (preg_match('/(?:ID:\s*#?|#)(\d+)/i', $clean, $mId)) {
                $tid = (int)$mId[1];
                return "view_course_material.php?id={$tid}" . ($page > 1 ? "&page={$page}" : "") . "&source=assistant";
            }

            // Check lookup by ID
            if (ctype_digit($clean)) {
                $cid = (int)$clean;
                if (isset($docLookup['by_id'][$cid])) {
                    return "view_course_material.php?id={$cid}" . ($page > 1 ? "&page={$page}" : "") . "&source=assistant";
                }
            }

            // Check lookup by lowercase name / original_name / filename
            $low = strtolower($clean);
            if (isset($docLookup['by_name'][$low])) {
                $cid = (int)$docLookup['by_name'][$low]['id'];
                return "view_course_material.php?id={$cid}" . ($page > 1 ? "&page={$page}" : "") . "&source=assistant";
            }

            // Check lookup with .md appended or stripped
            if (isset($docLookup['by_name'][$low . '.md'])) {
                $cid = (int)$docLookup['by_name'][$low . '.md']['id'];
                return "view_course_material.php?id={$cid}" . ($page > 1 ? "&page={$page}" : "") . "&source=assistant";
            }
            $noExt = preg_replace('/\.[a-zA-Z0-9]+$/', '', $low);
            if (isset($docLookup['by_name'][$noExt])) {
                $cid = (int)$docLookup['by_name'][$noExt]['id'];
                return "view_course_material.php?id={$cid}" . ($page > 1 ? "&page={$page}" : "") . "&source=assistant";
            }

            // Partial match in lookup
            if (!empty($docLookup['by_name'])) {
                foreach ($docLookup['by_name'] as $k => $item) {
                    if (strpos($k, $low) !== false || strpos($low, $k) !== false) {
                        $cid = (int)$item['id'];
                        return "view_course_material.php?id={$cid}" . ($page > 1 ? "&page={$page}" : "") . "&source=assistant";
                    }
                }
            }

            // Fallback: pass file query
            return "view_course_material.php?file=" . urlencode($clean) . ($page > 1 ? "&page={$page}" : "") . "&source=assistant";
        };

        $formatInline = function(string $str): string {
            // 1. Extract markdown links [label](url) and protect with tokens
            $linkPlaceholders = [];
            $str = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($m) use (&$linkPlaceholders) {
                $label = $m[1];
                $url = trim($m[2]);
                // Validate URL: only allow safe relative URLs or http/https, strictly reject javascript/data
                if (preg_match('#^(?:view_course_material\.php(?:\?[a-zA-Z0-9_=&.-]*)?|https?://[a-zA-Z0-9_.-]+|/[a-zA-Z0-9_.-]*)#i', $url) && !preg_match('#^(?:javascript|data|vbscript):#i', $url)) {
                    $idx = count($linkPlaceholders);
                    $token = "%%LINK_PLACEHOLDER_{$idx}%%";
                    $linkPlaceholders[$token] = [
                        'label' => $label,
                        'url' => $url
                    ];
                    return $token;
                }
                return $label;
            }, $str);

            // Escape all HTML characters
            $str = htmlspecialchars($str, ENT_QUOTES, 'UTF-8');

            // Task list checkboxes: [ ] or [x]
            $str = preg_replace('/^\[ \]\s*/', '<input type="checkbox" disabled class="form-check-input me-1" style="vertical-align: middle;"> ', $str);
            $str = preg_replace('/^\[[xX]\]\s*/', '<input type="checkbox" checked disabled class="form-check-input me-1" style="vertical-align: middle;"> ', $str);

            // Inline code: `code`
            $str = preg_replace('/`([^`]+)`/', '<code class="bg-light text-danger px-1 py-0.5 rounded border" style="font-size:0.88em; font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;">$1</code>', $str);

            // Bold: **text**
            $str = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $str);

            // Italic: *text* (avoiding double asterisks)
            $str = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $str);

            // Re-insert safe links
            foreach ($linkPlaceholders as $token => $linkData) {
                $safeUrl = htmlspecialchars($linkData['url'], ENT_QUOTES, 'UTF-8');
                $labelFormatted = htmlspecialchars($linkData['label'], ENT_QUOTES, 'UTF-8');
                $labelFormatted = preg_replace('/`([^`]+)`/', '<code class="bg-light text-danger px-1 py-0.5 rounded border" style="font-size:0.88em;">$1</code>', $labelFormatted);
                $labelFormatted = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $labelFormatted);
                $linkHtml = '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer" class="material-link text-primary fw-semibold text-decoration-underline">' . $labelFormatted . '</a>';
                $str = str_replace($token, $linkHtml, $str);
            }

            return $str;
        };

        $flushList = function() use (&$html, &$inList, &$listType) {
            if ($inList) {
                $html[] = ($listType === 'ol') ? '</ol>' : '</ul>';
                $inList = false;
                $listType = '';
            }
        };

        $flushTable = function() use (&$html, &$inTable, &$tableRows, $formatInline) {
            if ($inTable && !empty($tableRows)) {
                $tableHtml = '<div class="table-responsive my-2"><table class="table table-sm table-bordered table-striped align-middle mb-1 shadow-sm" style="font-size: 0.88rem;">';
                $hasHeader = false;
                foreach ($tableRows as $rIdx => $row) {
                    $cells = array_values(array_filter($row, function($k, $v) use ($row) {
                        return $v > 0 && $v < count($row) - 1;
                    }, ARRAY_FILTER_USE_BOTH));
                    if (empty($cells)) {
                        $cells = array_values(array_filter($row, fn($c) => trim($c) !== ''));
                    }

                    if ($rIdx === 0) {
                        $tableHtml .= '<thead class="table-light"><tr>';
                        foreach ($cells as $cell) {
                            $tableHtml .= '<th class="fw-bold text-dark py-2 px-3">' . $formatInline(trim($cell)) . '</th>';
                        }
                        $tableHtml .= '</tr></thead><tbody>';
                        $hasHeader = true;
                    } else {
                        $tableHtml .= '<tr>';
                        foreach ($cells as $cell) {
                            $tableHtml .= '<td class="py-2 px-3">' . $formatInline(trim($cell)) . '</td>';
                        }
                        $tableHtml .= '</tr>';
                    }
                }
                if ($hasHeader) {
                    $tableHtml .= '</tbody>';
                }
                $tableHtml .= '</table></div>';
                $html[] = $tableHtml;
                $tableRows = [];
                $inTable = false;
            }
        };

        $flushBlockquote = function() use (&$html, &$inBlockquote, &$quoteLines, $formatInline) {
            if ($inBlockquote && !empty($quoteLines)) {
                $content = implode("<br>", array_map($formatInline, $quoteLines));
                $html[] = '<blockquote class="border-start border-3 border-primary ps-3 py-1 my-2 text-muted fst-italic" style="font-size: 0.92rem;">' . $content . '</blockquote>';
                $quoteLines = [];
                $inBlockquote = false;
            }
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // 1. Fenced Code Block Toggle: ```lang or ```
            if (preg_match('/^```([a-zA-Z0-9_+-]*)/', $trimmed, $mCode)) {
                if ($inCodeBlock) {
                    $codeContent = htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES, 'UTF-8');
                    $langClass = $codeLang ? ' language-' . htmlspecialchars($codeLang, ENT_QUOTES, 'UTF-8') : '';
                    $html[] = '<pre class="bg-dark text-light p-3 rounded-3 my-2 overflow-auto" style="font-size: 0.88rem; line-height: 1.5; font-family: SFMono-Regular, Menlo, Monaco, Consolas, \'Liberation Mono\', \'Courier New\', monospace;"><code class="' . $langClass . '">' . $codeContent . '</code></pre>';
                    $codeLines = [];
                    $codeLang = '';
                    $inCodeBlock = false;
                } else {
                    $flushList();
                    $flushTable();
                    $flushBlockquote();
                    $inCodeBlock = true;
                    $codeLang = trim($mCode[1]);
                }
                continue;
            }

            // Inside code block: accumulate verbatim line
            if ($inCodeBlock) {
                $codeLines[] = $line;
                continue;
            }

            // 2. Empty line handling
            if ($trimmed === '') {
                $flushList();
                $flushTable();
                $flushBlockquote();
                $html[] = '<div class="my-2"></div>';
                continue;
            }

            // Horizontal rule: ---, ***, ___
            if (preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
                $flushList();
                $flushTable();
                $flushBlockquote();
                $html[] = '<hr class="my-3 border-secondary opacity-25">';
                continue;
            }

            // 3. Blockquote: starts with "> "
            if (preg_match('/^>\s*(.*)$/', $trimmed, $mQuote)) {
                $flushList();
                $flushTable();
                $inBlockquote = true;
                $quoteLines[] = $mQuote[1];
                continue;
            } else {
                $flushBlockquote();
            }

            // 4. Table Row: starts and ends with "|"
            if (preg_match('/^\|(.+)\|$/', $trimmed, $mTable)) {
                if (preg_match('/^\|(?:\s*:?-+:?\s*\|)+$/', $trimmed)) {
                    continue;
                }
                $flushList();
                $inTable = true;
                $tableRows[] = explode('|', $trimmed);
                continue;
            } else {
                $flushTable();
            }

            // 5. Auto-link study materials if line contains "Document Title"
            if (preg_match('/^([-*]\s+\*\*Document Title:\*\*\s*)(`([^`]+)`|([^\(\r\n]+))(\s*\(ID:\s*#?(\d+)\))?/i', $trimmed, $mDoc)) {
                if (!str_contains($trimmed, 'view_course_material.php')) {
                    $docName = !empty($mDoc[3]) ? trim($mDoc[3]) : trim($mDoc[4]);
                    $explicitId = !empty($mDoc[6]) ? (int)$mDoc[6] : 0;
                    $linkUrl = $explicitId > 0 ? "view_course_material.php?id={$explicitId}&page=1&source=assistant" : $resolveDocLink($docName, 1);
                    $displayTitle = !empty($mDoc[3]) ? "`{$docName}`" : $docName;
                    $idPart = !empty($mDoc[5]) ? $mDoc[5] : "";
                    $trimmed = $mDoc[1] . "[{$displayTitle}]({$linkUrl})" . $idPart;
                }
            }

            // 6. Auto-link study materials if line contains "Review Course Material"
            if (preg_match('/^([-*]\s+)\*\*(Review Course Material:?\*\*)\s*(Read through\s+)?(`([^`]+)`|([A-Za-z0-9_.\-\(\)\s]+\.md))(.*)$/i', $trimmed, $mRev)) {
                if (!str_contains($trimmed, 'view_course_material.php')) {
                    $revDocName = !empty($mRev[5]) ? trim($mRev[5]) : trim($mRev[6]);
                    $revUrl = $resolveDocLink($revDocName, 1);
                    $readPart = !empty($mRev[3]) ? $mRev[3] : "";
                    $dispDoc = !empty($mRev[5]) ? "`{$revDocName}`" : $revDocName;
                    $remainder = $mRev[7] ?? "";
                    $trimmed = $mRev[1] . "[**Review Course Material:**]({$revUrl}) " . $readPart . "[{$dispDoc}]({$revUrl})" . $remainder;
                }
            }

            // 7. Bullet List: starts with "- " or "* "
            if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $mBullet)) {
                if ($inList && $listType !== 'ul') {
                    $flushList();
                }
                if (!$inList) {
                    $html[] = '<ul class="mb-2 ps-3">';
                    $inList = true;
                    $listType = 'ul';
                }
                $html[] = '<li class="mb-1">' . $formatInline($mBullet[1]) . '</li>';
                continue;
            }

            // 8. Numbered / Ordered List: starts with "1. ", "2. ", etc.
            if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $mNum)) {
                if ($inList && $listType !== 'ol') {
                    $flushList();
                }
                if (!$inList) {
                    $html[] = '<ol class="mb-2 ps-3">';
                    $inList = true;
                    $listType = 'ol';
                }
                $html[] = '<li class="mb-1">' . $formatInline($mNum[1]) . '</li>';
                continue;
            }

            // Any non-list line ends existing list
            $flushList();

            // 9. Headings: # through ######
            if (preg_match('/^(#{1,6})\s*(.*)$/', $trimmed, $mHead) && !empty(trim($mHead[2]))) {
                $level = strlen($mHead[1]);
                $headingText = rtrim(trim($mHead[2]), " #\t\r\n");
                $title = $formatInline($headingText);
                if ($level === 1) {
                    $html[] = '<h5 class="fw-bold text-dark mt-3 mb-2">' . $title . '</h5>';
                } elseif ($level === 2) {
                    $html[] = '<h6 class="fw-bold text-dark mt-3 mb-2">' . $title . '</h6>';
                } elseif ($level === 3) {
                    $html[] = '<div class="fw-bold text-dark mt-2 mb-1" style="font-size: 1rem;">' . $title . '</div>';
                } elseif ($level === 4) {
                    $html[] = '<div class="fw-bold text-secondary mt-2 mb-1" style="font-size: 0.94rem;">' . $title . '</div>';
                } else {
                    $html[] = '<div class="fw-semibold text-secondary mt-1 mb-1" style="font-size: 0.88rem;">' . $title . '</div>';
                }
                continue;
            }

            // 10. Regular paragraph line
            $html[] = '<div>' . $formatInline($trimmed) . '</div>';
        }

        // Flush any pending blocks at EOF
        if ($inCodeBlock) {
            $codeContent = htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES, 'UTF-8');
            $langClass = $codeLang ? ' language-' . htmlspecialchars($codeLang, ENT_QUOTES, 'UTF-8') : '';
            $html[] = '<pre class="bg-dark text-light p-3 rounded-3 my-2 overflow-auto" style="font-size: 0.88rem; line-height: 1.5; font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;"><code class="' . $langClass . '">' . $codeContent . '</code></pre>';
        }
        $flushList();
        $flushTable();
        $flushBlockquote();

        return implode("\n", $html);
    }
}

if (!function_exists('renderStudyMaterialMarkdown')) {
    /**
     * Robust, XSS-safe Markdown to HTML parser for study materials.
     * Escapes all text elements before wrapping in semantic HTML tags.
     * Supports section and page anchors for targeted jumping.
     *
     * @param string $md
     * @return string
     */
    function renderStudyMaterialMarkdown(string $md): string {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $lines = explode("\n", $md);
        $html = [];

        $inCodeBlock = false;
        $codeLines = [];
        $inList = false;
        $listType = '';
        $inBlockquote = false;
        $quoteLines = [];
        $inTable = false;
        $tableRows = [];
        $sectionCounter = 0;

        $flushBlockquote = function() use (&$html, &$inBlockquote, &$quoteLines) {
            if ($inBlockquote) {
                $quoteText = implode("<br>", $quoteLines);
                $html[] = '<blockquote class="border-start border-4 border-primary ps-3 py-2 my-3 bg-light text-secondary rounded-end">' . $quoteText . '</blockquote>';
                $quoteLines = [];
                $inBlockquote = false;
            }
        };

        $flushList = function() use (&$html, &$inList, &$listType) {
            if ($inList) {
                $html[] = ($listType === 'ol') ? '</ol>' : '</ul>';
                $inList = false;
                $listType = '';
            }
        };

        $flushTable = function() use (&$html, &$inTable, &$tableRows) {
            if ($inTable && !empty($tableRows)) {
                $tableHtml = '<div class="table-responsive my-3"><table class="table table-bordered table-striped align-middle">';
                foreach ($tableRows as $rIdx => $row) {
                    if ($rIdx === 0) {
                        $tableHtml .= '<thead class="table-light"><tr>';
                        foreach ($row as $cell) {
                            $tableHtml .= '<th class="fw-bold">' . htmlspecialchars(trim($cell), ENT_QUOTES, 'UTF-8') . '</th>';
                        }
                        $tableHtml .= '</tr></thead><tbody>';
                    } else {
                        $tableHtml .= '<tr>';
                        foreach ($row as $cell) {
                            $tableHtml .= '<td>' . htmlspecialchars(trim($cell), ENT_QUOTES, 'UTF-8') . '</td>';
                        }
                        $tableHtml .= '</tr>';
                    }
                }
                $tableHtml .= '</tbody></table></div>';
                $html[] = $tableHtml;
                $tableRows = [];
                $inTable = false;
            }
        };

        $formatInline = function(string $text): string {
            $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            // Inline code: `code`
            $text = preg_replace('/`([^`]+)`/', '<code class="bg-light text-danger px-1.5 py-0.5 rounded border" style="font-size:0.9em;">$1</code>', $text);
            // Bold: **text**
            $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
            // Italic: *text*
            $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);
            // Markdown links: [label](url) - strictly sanitize scheme
            $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($m) {
                $label = $m[1];
                $url = $m[2];
                if (preg_match('#^https?://#i', $url) || preg_match('#^[a-zA-Z0-9_.-]+\.php(?:\?[a-zA-Z0-9_=&.-]*)?#i', $url) || preg_match('#^/[a-zA-Z0-9_.-]*#i', $url)) {
                    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" class="text-primary fw-semibold">' . $label . '</a>';
                }
                return $label;
            }, $text);
            return $text;
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Code block toggle: ```lang
            if (preg_match('/^```([a-zA-Z0-9_-]*)/', $trimmed, $m)) {
                if ($inCodeBlock) {
                    $codeContent = htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES, 'UTF-8');
                    $html[] = '<pre class="bg-dark text-light p-3 rounded-3 my-3 overflow-auto" style="font-size: 0.9rem; line-height: 1.5;"><code>' . $codeContent . '</code></pre>';
                    $codeLines = [];
                    $inCodeBlock = false;
                } else {
                    $flushBlockquote();
                    $flushList();
                    $flushTable();
                    $inCodeBlock = true;
                }
                continue;
            }

            if ($inCodeBlock) {
                $codeLines[] = $line;
                continue;
            }

            // Tables: | col1 | col2 |
            if (preg_match('/^\|(.+)\|$/', $trimmed, $m)) {
                if (preg_match('/^\|[\s\-:|]+\|$/', $trimmed)) {
                    continue;
                }
                $flushBlockquote();
                $flushList();
                $inTable = true;
                $cells = explode('|', trim($m[1]));
                $tableRows[] = $cells;
                continue;
            } else {
                $flushTable();
            }

            // Horizontal Rule: --- or ***
            if (preg_match('/^(\*{3,}|-{3,}|_{3,})$/', $trimmed)) {
                $flushBlockquote();
                $flushList();
                $sectionCounter++;
                $html[] = '<hr class="my-4 text-muted opacity-25" id="section-' . $sectionCounter . '">';
                continue;
            }

            // Blockquote: > text
            if (preg_match('/^>\s?(.*)$/', $trimmed, $m)) {
                $flushList();
                $inBlockquote = true;
                $quoteLines[] = $formatInline($m[1]);
                continue;
            } else {
                $flushBlockquote();
            }

            // Headings: #, ##, ###, ####
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m)) {
                $flushList();
                $level = strlen($m[1]);
                $titleText = $m[2];
                $title = $formatInline($titleText);
                $sectionCounter++;
                $slug = 'heading-' . $sectionCounter;
                // Check if heading has a number like "1.", "2.", "Question 1"
                if (preg_match('/^(\d+)\.?\s+/i', $titleText, $mNum)) {
                    $slug = 'page-' . $mNum[1];
                }

                $classes = [
                    1 => 'h3 fw-bold text-dark border-bottom pb-2 mt-4 mb-3',
                    2 => 'h4 fw-bold text-primary mt-4 mb-3',
                    3 => 'h5 fw-semibold text-dark mt-3 mb-2',
                    4 => 'h6 fw-bold text-secondary mt-3 mb-2',
                    5 => 'h6 fw-semibold text-muted mt-2 mb-1',
                    6 => 'small fw-bold text-muted mt-2 mb-1'
                ];
                $cls = $classes[$level] ?? 'h5 fw-bold';
                $html[] = "<h{$level} id=\"{$slug}\" class=\"{$cls}\">{$title}</h{$level}>";
                continue;
            }

            // Unordered List Items: - or *
            if (preg_match('/^(\s*)([-*])\s+(.*)$/', $line, $m)) {
                $indent = strlen($m[1]);
                $itemText = $formatInline($m[3]);
                if (!$inList || $listType !== 'ul') {
                    $flushList();
                    $html[] = '<ul class="mb-3 ps-4">';
                    $inList = true;
                    $listType = 'ul';
                }
                if ($indent >= 2) {
                    $html[] = '<li class="ms-3 mb-1 text-dark">' . $itemText . '</li>';
                } else {
                    $html[] = '<li class="mb-1 text-dark">' . $itemText . '</li>';
                }
                continue;
            }

            // Ordered List Items: 1.
            if (preg_match('/^\s*(\d+)\.\s+(.*)$/', $line, $m)) {
                $itemText = $formatInline($m[2]);
                if (!$inList || $listType !== 'ol') {
                    $flushList();
                    $html[] = '<ol class="mb-3 ps-4">';
                    $inList = true;
                    $listType = 'ol';
                }
                $html[] = '<li class="mb-1 text-dark">' . $itemText . '</li>';
                continue;
            }

            // Empty line
            if ($trimmed === '') {
                $flushList();
                continue;
            }

            // Normal paragraph
            $flushList();
            $html[] = '<p class="mb-2 text-dark lh-base">' . $formatInline($trimmed) . '</p>';
        }

        $flushBlockquote();
        $flushList();
        $flushTable();
        if ($inCodeBlock) {
            $html[] = '<pre class="bg-dark text-light p-3 rounded-3 my-3"><code>' . htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES, 'UTF-8') . '</code></pre>';
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

if (!function_exists('format_question_code_block')) {
    /**
     * Formats question code blocks to ensure readability line by line like in a proper IDE:
     * - Unescapes literal \n and \r\n safely if stored escaped
     * - Preserves indentation, blank lines, braces, and spacing
     * - In C-family languages (Java, C, C++), expands crammed single-line method definitions
     *   (e.g. void show() { System.out.print("P "); }) onto their own lines
     * - Ensures clean spacing between class definitions
     *
     * @param string $code
     * @param string $lang
     * @return string
     */
    function format_question_code_block(string $code, string $lang = ''): string {
        // 1. Unescape literal escaped newlines safely while preserving string literals (e.g. printf("...\n"))
        $code = safe_unescape_exam_code($code);

        $normLang = strtolower(trim($lang));
        $isCStyle = in_array($normLang, ['java', 'c', 'cpp', 'c++', 'cs', 'csharp', 'javascript', 'js', 'typescript', 'ts'], true)
                    || $normLang === '';

        if ($isCStyle) {
            // Expand single-line method/function bodies: e.g. void show() { System.out.print("P "); }
            $code = preg_replace_callback(
                '/^([ \t]*)((?:public|protected|private|static|final|abstract|synchronized|native|void|[A-Za-z0-9_<>[\]]+)\s+[^;{}]*?\([^;{}]*?\)\s*)\{\s*([^;{}\r\n]+;)\s*\}/mu',
                function ($m) {
                    $indent = $m[1];
                    $signature = rtrim($m[2]);
                    $statement = trim($m[3]);
                    return "{$indent}{$signature} {\n{$indent}    {$statement}\n{$indent}}";
                },
                $code
            );

            // Ensure clean blank line between top-level class definitions (e.g. "}\nclass" -> "}\n\nclass")
            $code = preg_replace('/^(\})\n(?=(?:public\s+|class\s+|abstract\s+class\s+|interface\s+))/mu', "$1\n\n", $code);
        }

        return rtrim($code);
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

        // 1. Handle \n, \r\n, and escaped newline sequences safely when loading stored code
        $rawText = safe_unescape_exam_code($rawQuestion);
        $text = str_replace(["\r\n", "\r"], "\n", $rawText);
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
                $code = format_question_code_block(rtrim($m[2]), $lang);
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
                    
                    $descHtml .= '<div class="code-block-wrapper question-code code-display">';
                    if ($langLabel !== '') {
                        $descHtml .= '<div class="code-block-header"><span class="code-block-badge">' . $langLabel . '</span></div>';
                    }
                    $descHtml .= '<pre class="code-block-dark code-display"><code class="' . $langClass . ' code-display">' . htmlspecialchars($cb['code'], ENT_QUOTES, 'UTF-8') . '</code></pre>';
                    $descHtml .= '</div>';
                } else {
                    // Check for inline numbered instructions after punctuation: "detail: 1. ... 2. ..."
                    if (preg_match('/(?<=[:.!?])\s+(?=\d+\.\s+)/u', $block) && preg_match_all('/\b\d+\.\s+/u', $block) >= 2) {
                        $parts = preg_split('/(?<=[:.!?])\s+(?=\d+\.\s+)/u', $block);
                        $intro = array_shift($parts);
                        if (trim($intro) !== '') {
                            $descHtml .= '<p class="question-p">' . $formatParagraphInline($intro) . '</p>';
                        }
                        $descHtml .= '<ol class="question-list question-ol">';
                        foreach ($parts as $p) {
                            $cleanItem = preg_replace('/^\d+\.\s*/u', '', trim($p));
                            if ($cleanItem !== '') {
                                $descHtml .= '<li class="question-li">' . $formatParagraphInline($cleanItem) . '</li>';
                            }
                        }
                        $descHtml .= '</ol>';
                        continue;
                    }

                    // Check for multiline lists (ordered or unordered)
                    $lines = explode("\n", $block);
                    $hasListItems = false;
                    foreach ($lines as $line) {
                        if (preg_match('/^(?:\d+\.|[-*•])\s+/u', trim($line))) {
                            $hasListItems = true;
                            break;
                        }
                    }

                    if ($hasListItems && count($lines) > 1) {
                        $output = [];
                        $currentList = [];
                        $currentListType = null;

                        $flushCurrentList = function() use (&$output, &$currentList, &$currentListType, $formatParagraphInline) {
                            if (!empty($currentList)) {
                                $tag = ($currentListType === 'ol') ? 'ol' : 'ul';
                                $cls = ($currentListType === 'ol') ? 'question-ol' : 'question-ul';
                                $listHtml = '<' . $tag . ' class="question-list ' . $cls . '">';
                                foreach ($currentList as $item) {
                                    $listHtml .= '<li class="question-li">' . $formatParagraphInline($item) . '</li>';
                                }
                                $listHtml .= '</' . $tag . '>';
                                $output[] = $listHtml;
                                $currentList = [];
                                $currentListType = null;
                            }
                        };

                        $paraLines = [];
                        $flushPara = function() use (&$output, &$paraLines, $formatParagraphInline) {
                            if (!empty($paraLines)) {
                                $output[] = '<p class="question-p">' . $formatParagraphInline(implode("\n", $paraLines)) . '</p>';
                                $paraLines = [];
                            }
                        };

                        foreach ($lines as $line) {
                            $t = trim($line);
                            if ($t === '') continue;

                            if (preg_match('/^(\d+)\.\s+(.*)$/u', $t, $m)) {
                                $flushPara();
                                if ($currentListType !== 'ol') {
                                    $flushCurrentList();
                                    $currentListType = 'ol';
                                }
                                $currentList[] = $m[2];
                            } elseif (preg_match('/^[-*•]\s+(.*)$/u', $t, $m)) {
                                $flushPara();
                                if ($currentListType !== 'ul') {
                                    $flushCurrentList();
                                    $currentListType = 'ul';
                                }
                                $currentList[] = $m[1];
                            } else {
                                $flushCurrentList();
                                $paraLines[] = $line;
                            }
                        }
                        $flushPara();
                        $flushCurrentList();

                        $descHtml .= implode("\n", $output);
                    } else {
                        $descHtml .= '<p class="question-p">' . $formatParagraphInline($block) . '</p>';
                    }
                }
            }
            $descHtml .= '</div>';
        }

        return $titleHtml . ($descHtml !== '' ? "\n" . $descHtml : '');
    }
}

if (!function_exists('format_io_format_lines')) {
    /**
     * Formats input and output format specifications line by line.
     * Splits sentences introducing sequential lines (e.g. Line 1: / Line 2:, First line: / Second line:),
     * highlights code/variable references safely, and wraps in clean .io-line containers.
     *
     * @param string|null $str
     * @return string
     */
    function format_io_format_lines(?string $str): string {
        if ($str === null) return '';
        $trimmed = trim($str);
        if ($trimmed === '') return '';

        // If string already has newlines, split on newlines
        if (str_contains($trimmed, "\n")) {
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $trimmed));
        } else {
            // Split on sentences that introduce subsequent lines
            $lines = preg_split('/(?<=[.!?])\s+(?=(?:Line\s*\d+|First\s+line|Second\s+line|Next\s*(?:\w+\s*)?lines?|\d+\.)\b)/iu', $trimmed);
        }

        $formatted = [];
        foreach ($lines as $line) {
            $lTrim = trim($line);
            if ($lTrim === '') continue;
            $escaped = htmlspecialchars($lTrim, ENT_QUOTES, 'UTF-8');
            $escaped = preg_replace('/`([^`]+)`/u', '<code class="inline-code-pill">$1</code>', $escaped);
            $escaped = preg_replace('/&#039;([^&#039;]+)&#039;/u', '<code class="inline-code-pill">$1</code>', $escaped);
            $formatted[] = '<div class="io-line">' . $escaped . '</div>';
        }

        return implode("\n", $formatted);
    }
}

if (!function_exists('get_coding_starter_code')) {
    /**
     * Resolves the language-appropriate boilerplate / starter code for a coding question.
     * Preserves existing question-specific starter code, declarations, and signatures,
     * while replacing placeholder implementations (TODO / pass) with a single '# Start code here'
     * or '// Start code here' comment directly inside the editor.
     *
     * @param array $q Question record array
     * @return string
     */
    function get_coding_starter_code(array $q): string {
        $lang = strtolower(trim($q['programming_language'] ?? 'python'));
        $starter = safe_unescape_exam_code($q['starter_code'] ?? '');

        // Determine comment syntax for language
        $commentPrefix = '//';
        if (in_array($lang, ['python', 'py', 'python3', 'ruby', 'bash', 'sh'], true)) {
            $commentPrefix = '#';
        } elseif ($lang === 'sql') {
            $commentPrefix = '--';
        }

        $placeholderComment = "{$commentPrefix} Start code here";

        if ($starter === '') {
            switch ($lang) {
                case 'java':
                    return "public class Solution {\n    public static void main(String[] args) {\n        // Start code here\n    }\n}";
                case 'c':
                    return "#include <stdio.h>\n\nint main() {\n    // Start code here\n    return 0;\n}";
                case 'cpp':
                case 'c++':
                    return "#include <iostream>\n\nusing namespace std;\n\nint main() {\n    // Start code here\n    return 0;\n}";
                case 'sql':
                    return "-- Start code here";
                case 'javascript':
                case 'js':
                    return "function solve() {\n    // Start code here\n}";
                case 'python':
                case 'python3':
                case 'py':
                default:
                    return "def solve():\n    # Start code here";
            }
        }

        // 1. Remove obsolete top-level banner comment if present
        // e.g. "# Solution template for ...", "// Solution template for ...", or previous "# Start code here: write your solution below"
        $starter = preg_replace('/^(\/\/|#|--)\s*(Solution template for|Start code here: write your solution below)[^\r\n]*(\r?\n)+/i', '', $starter);

        // 2. If it already has the exact placeholder comment and no remaining TODO or pass, return it
        if (str_contains($starter, $placeholderComment) && !preg_match('/(TODO:|(\bpass\b))/i', $starter)) {
            return trim($starter);
        }

        // 3. Replace placeholder implementation (TODO comments and/or pass) with the single placeholder comment
        if (preg_match('/([ \t]*)(\/\/|#|--)\s*TODO:[^\r\n]*(\r?\n[ \t]*pass)?/i', $starter, $m)) {
            $indent = $m[1] ?? '    ';
            $starter = preg_replace('/[ \t]*(\/\/|#|--)\s*TODO:[^\r\n]*(\r?\n[ \t]*pass)?/i', $indent . $placeholderComment, $starter);
        } elseif (preg_match('/([ \t]*)\bpass\b/i', $starter, $m)) {
            $indent = $m[1] ?? '    ';
            $starter = preg_replace('/[ \t]*\bpass\b/i', $indent . $placeholderComment, $starter);
        }

        // 4. If placeholder comment is still not in the code, append it cleanly
        if (!str_contains($starter, $placeholderComment)) {
            $starter .= "\n    " . $placeholderComment;
        }

        return trim($starter);
    }
}



