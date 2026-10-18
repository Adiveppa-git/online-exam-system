<?php
/**
 * StudyMaterialService
 * Generates verified, high-fidelity course study materials exclusively from existing
 * approved AI-generated questions in the database.
 * Strictly adheres to source questions without hallucinating external topics.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_client.php';
require_once __DIR__ . '/text_helpers.php';

class StudyMaterialService {

    /**
     * Ensure database columns exist on ai_documents table
     */
    public static function ensureSchema($conn): void {
        static $checked = false;
        if ($checked) return;

        try {
            $chk1 = $conn->query("SHOW COLUMNS FROM ai_documents LIKE 'exam_id'");
            if ($chk1 && $chk1->num_rows === 0) {
                $conn->query("ALTER TABLE ai_documents ADD COLUMN exam_id INT NULL DEFAULT NULL");
            }
            $chk2 = $conn->query("SHOW COLUMNS FROM ai_documents LIKE 'source_question_ids'");
            if ($chk2 && $chk2->num_rows === 0) {
                $conn->query("ALTER TABLE ai_documents ADD COLUMN source_question_ids TEXT NULL DEFAULT NULL");
            }
            $chk3 = $conn->query("SHOW COLUMNS FROM ai_documents LIKE 'is_generated'");
            if ($chk3 && $chk3->num_rows === 0) {
                $conn->query("ALTER TABLE ai_documents ADD COLUMN is_generated TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (Throwable $e) {
            // Ignore if already present
        }
        $checked = true;
    }

    /**
     * Fetch all available exams
     */
    public static function getExams($conn): array {
        $exams = [];
        $res = $conn->query("SELECT id, title FROM exams ORDER BY title ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $exams[] = $row;
            }
        }
        return $exams;
    }

    /**
     * Fetch distinct subjects for an exam (or across all exams)
     */
    public static function getSubjects($conn, ?int $examId = null): array {
        $subjects = [];
        $sql = "SELECT DISTINCT subject FROM ai_generated_questions WHERE subject IS NOT NULL AND TRIM(subject) != ''";
        if ($examId !== null && $examId > 0) {
            $sql .= " AND exam_id = " . (int)$examId;
        }
        $sql .= " ORDER BY subject ASC";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $s = trim($row['subject']);
                if ($s !== '' && !in_array($s, $subjects, true)) {
                    $subjects[] = $s;
                }
            }
        }
        return $subjects;
    }

    /**
     * Fetch distinct topics for an exam and subject
     */
    public static function getTopics($conn, ?int $examId = null, ?string $subject = null): array {
        $topics = [];
        $where = ["topic IS NOT NULL AND TRIM(topic) != ''"];
        if ($examId !== null && $examId > 0) {
            $where[] = "exam_id = " . (int)$examId;
        }
        if (!empty($subject)) {
            $safeSubj = $conn->real_escape_string($subject);
            $where[] = "subject = '{$safeSubj}'";
        }
        $sql = "SELECT DISTINCT topic FROM ai_generated_questions WHERE " . implode(' AND ', $where) . " ORDER BY topic ASC";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $t = trim($row['topic']);
                if ($t !== '' && !in_array($t, $topics, true)) {
                    $topics[] = $t;
                }
            }
        }
        return $topics;
    }

    /**
     * Retrieve matching questions from the database using strict criteria.
     * Enforces:
     * - Target Exam ID
     * - Subject
     * - Topic (if specified)
     * - Status (defaults to 'approved' only)
     * - Specific Question IDs (if provided)
     */
    public static function getMatchingQuestions(
        $conn,
        int $examId,
        ?string $subject = null,
        ?string $topic = null,
        string $status = 'approved',
        ?array $questionIds = null
    ): array {
        $where = ["exam_id = ?"];
        $params = [$examId];
        $types = "i";

        if ($status !== null && $status !== '' && strtolower($status) !== 'all') {
            $where[] = "status = ?";
            $params[] = $status;
            $types .= "s";
        }

        if (!empty($subject) && strtolower($subject) !== 'all') {
            $where[] = "subject = ?";
            $params[] = $subject;
            $types .= "s";
        }

        if (!empty($topic) && strtolower($topic) !== 'all') {
            $where[] = "topic = ?";
            $params[] = $topic;
            $types .= "s";
        }

        if (!empty($questionIds) && is_array($questionIds)) {
            $intIds = array_values(array_filter(array_map('intval', $questionIds)));
            if (!empty($intIds)) {
                $placeholders = implode(',', array_fill(0, count($intIds), '?'));
                $where[] = "id IN ({$placeholders})";
                foreach ($intIds as $qId) {
                    $params[] = $qId;
                    $types .= "i";
                }
            }
        }

        $sql = "SELECT id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, explanation, status 
                FROM ai_generated_questions 
                WHERE " . implode(" AND ", $where) . " 
                ORDER BY id ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $questions = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $questions[] = $row;
            }
        }
        $stmt->close();
        return $questions;
    }

    /**
     * Synthesizes verified study material strictly from the retrieved question records.
     * Enforces the STRICT RULE:
     * Do NOT invent new topics, introduce unrelated subjects, or use general AI knowledge to fill gaps.
     */
    public static function buildStudyNotesMarkdown(
        string $examTitle,
        int $examId,
        string $subject,
        string $topic,
        array $questions
    ): string {
        $count = count($questions);
        $qIds = array_column($questions, 'id');
        $qIdsList = implode(', ', $qIds);
        $timestamp = date('Y-m-d H:i:s');
        $displayTopic = (!empty($topic) && strtolower($topic) !== 'all') ? $topic : 'Comprehensive Subject Notes';

        // Group questions by topic
        $byTopic = [];
        foreach ($questions as $q) {
            $t = !empty($q['topic']) ? trim($q['topic']) : 'General';
            $byTopic[$t][] = $q;
        }

        $md = [];
        $md[] = "# Study Material: {$subject} — {$displayTopic}";
        $md[] = "**Target Exam:** {$examTitle} (Exam ID: #{$examId})  ";
        $md[] = "**Subject:** {$subject}  ";
        $md[] = "**Topic / Module:** {$displayTopic}  ";
        $md[] = "**Status Scope:** Verified Approved AI Questions Only  ";
        $md[] = "**Source Question Count:** {$count} Questions  ";
        $md[] = "**Source Question IDs:** [{$qIdsList}]  ";
        $md[] = "**Generated At:** {$timestamp}  ";
        $md[] = "";
        $md[] = "---";
        $md[] = "";
        $md[] = "## 1. Source Fidelity Notice & Scope Authenticity";
        $md[] = "> **Strict Source Fidelity Policy:** This study material is synthesized strictly and exclusively from the **{$count} approved questions** stored in the database for **{$subject}** (Exam #{$examId}).";
        $md[] = "> ";
        $md[] = "> To prevent hallucinations, no external topics, speculative models, or unverified facts have been introduced. If a concept is not directly evaluated by these source questions, the source question set provides insufficient information and external extrapolation is withheld.";
        $md[] = "";
        $md[] = "---";
        $md[] = "";
        $md[] = "## 2. Core Concepts & Definitions";
        $md[] = "The following core concepts and principles are explicitly evaluated in the verified question set:";
        $md[] = "";

        $conceptIndex = 1;
        foreach ($byTopic as $subTopic => $subQuestions) {
            $subCount = count($subQuestions);
            $md[] = "### 2.{$conceptIndex}. {$subTopic} Overview";
            $md[] = "- **Scope:** Evaluated across {$subCount} question(s) in this exam bank.";
            $md[] = "- **Verified Principles & Key Concepts:**";

            $hasExplanations = false;
            foreach ($subQuestions as $q) {
                $cleanQ = clean_question_title($q['question']);
                $correctOpt = strtoupper(trim($q['correct_option']));
                $optKey = 'option_' . strtolower($correctOpt);
                $correctText = $q[$optKey] ?? $correctOpt;
                $exp = trim($q['explanation'] ?? '');

                if ($exp !== '') {
                    $hasExplanations = true;
                    $md[] = "  - **Concept (Q#{$q['id']}):** {$correctText} — *{$exp}*";
                } else {
                    $md[] = "  - **Principle (Q#{$q['id']}):** For problem *\"{$cleanQ}\"*, the correct answer is verified as **{$correctText}**.";
                }
            }

            if (!$hasExplanations) {
                $md[] = "  - *Information Boundary:* Source questions provide specific correct-option coverage for these points; extended external theoretical background is omitted to ensure zero hallucination.";
            }

            $md[] = "";
            $conceptIndex++;
        }

        $md[] = "---";
        $md[] = "";
        $md[] = "## 3. Verified Questions, Solutions & Explanations";
        $md[] = "Below are the verified exam questions, option analyses, and official solutions from the question bank:";
        $md[] = "";

        $qNum = 1;
        foreach ($questions as $q) {
            $cleanQ = clean_question_title($q['question']);
            $correctOpt = strtoupper(trim($q['correct_option']));
            $optKey = 'option_' . strtolower($correctOpt);
            $correctText = $q[$optKey] ?? $correctOpt;
            $exp = trim($q['explanation'] ?? '');
            $qTopic = !empty($q['topic']) ? $q['topic'] : $subject;
            $qDiff = !empty($q['difficulty']) ? ucfirst($q['difficulty']) : 'Standard';

            $md[] = "### Question {$qNum} (ID #{$q['id']}) [{$qTopic} | Difficulty: {$qDiff}]";
            $md[] = "**Question:** {$cleanQ}";
            $md[] = "";
            $md[] = "- **A)** " . ($q['option_a'] ?? '');
            $md[] = "- **B)** " . ($q['option_b'] ?? '');
            $md[] = "- **C)** " . ($q['option_c'] ?? '');
            $md[] = "- **D)** " . ($q['option_d'] ?? '');
            $md[] = "";
            $md[] = "**Verified Correct Answer:** Option **{$correctOpt}** (`{$correctText}`)";
            if ($exp !== '') {
                $md[] = "**Official Explanation:** {$exp}";
            } else {
                $md[] = "**Official Explanation:** Source questions confirm Option {$correctOpt} as the validated answer. Extended rationale is not provided in source question records.";
            }
            $md[] = "**Practice Point:** When encountering *\"{$cleanQ}\"*, recognize that **{$correctText}** is the correct determination under **{$qTopic}**.";
            $md[] = "";
            $qNum++;
        }

        $md[] = "---";
        $md[] = "";
        $md[] = "## 4. Key Practice Points & Summary Takeaways";
        $md[] = "Synthesized review summary from the {$count} verified source questions:";
        $md[] = "";

        $takeawayIndex = 1;
        foreach ($questions as $q) {
            $correctOpt = strtoupper(trim($q['correct_option']));
            $optKey = 'option_' . strtolower($correctOpt);
            $correctText = $q[$optKey] ?? $correctOpt;
            $cleanQ = clean_question_title($q['question']);
            $qTopic = !empty($q['topic']) ? $q['topic'] : $subject;

            $md[] = "{$takeawayIndex}. **[{$qTopic}]** {$correctText} is the correct solution for: *\"{$cleanQ}\"*.";
            $takeawayIndex++;
        }

        $md[] = "";
        $md[] = "---";
        $md[] = "*Document generated by Online Examination System RAG Pipeline. Strictly sourced from active approved question records.*";

        return implode("\n", $md);
    }

    /**
     * Generates study notes, saves them to disk and ai_documents, and indexes them in RAG.
     */
    public static function generateAndIndex(
        $conn,
        int $examId,
        string $subject,
        ?string $topic = null,
        string $status = 'approved',
        int $adminId = 1,
        bool $force = false,
        ?array $specificQuestionIds = null
    ): array {
        self::ensureSchema($conn);

        // 1. Validate Exam
        $examStmt = $conn->prepare("SELECT id, title FROM exams WHERE id = ?");
        $examStmt->bind_param("i", $examId);
        $examStmt->execute();
        $examRow = $examStmt->get_result()->fetch_assoc();
        $examStmt->close();

        if (!$examRow) {
            return [
                'success' => false,
                'message' => "Invalid target exam ID #{$examId}. Exam not found."
            ];
        }

        // 2. Validate Subject
        $subject = trim($subject);
        if (empty($subject)) {
            return [
                'success' => false,
                'message' => "Subject is required to generate study material."
            ];
        }

        $topic = trim($topic ?? '');

        // 3. Retrieve Matching Questions
        $questions = self::getMatchingQuestions($conn, $examId, $subject, $topic, $status, $specificQuestionIds);

        if (empty($questions)) {
            $scopeDesc = "Exam '{$examRow['title']}' (ID: #{$examId}) and Subject '{$subject}'" . (!empty($topic) ? " [Topic: '{$topic}']" : "");
            return [
                'success' => false,
                'empty' => true,
                'message' => "No {$status} AI questions found matching {$scopeDesc}. Study material generation aborted."
            ];
        }

        $qCount = count($questions);
        $qIds = array_column($questions, 'id');
        sort($qIds, SORT_NUMERIC);
        $qIdsStr = implode(',', $qIds);

        // 4. Deduplication Check
        if (!$force) {
            $checkTopic = !empty($topic) ? $topic : 'General';
            $dedupStmt = $conn->prepare("
                SELECT id, original_name, status, total_chunks 
                FROM ai_documents 
                WHERE exam_id = ? AND subject = ? AND topic = ? AND source_question_ids = ? AND status = 'ingested'
                ORDER BY id DESC LIMIT 1
            ");
            $dedupStmt->bind_param("isss", $examId, $subject, $checkTopic, $qIdsStr);
            $dedupStmt->execute();
            $existing = $dedupStmt->get_result()->fetch_assoc();
            $dedupStmt->close();

            if ($existing) {
                return [
                    'success' => false,
                    'duplicate' => true,
                    'existing_doc_id' => (int)$existing['id'],
                    'doc_id' => (int)$existing['id'],
                    'message' => "Study material for Exam '{$examRow['title']}' ({$subject} - {$checkTopic}) matching these {$qCount} questions already exists and is indexed in RAG (Document #{$existing['id']}: '{$existing['original_name']}'). Use 'Overwrite existing' to regenerate."
                ];
            }
        }

        // 5. Generate Markdown
        $markdownContent = self::buildStudyNotesMarkdown(
            $examRow['title'],
            $examId,
            $subject,
            $topic,
            $questions
        );

        // 6. Save File Locally (or Supabase)
        $uploadDir = __DIR__ . '/../uploads/course_materials/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $safeExam = preg_replace('/[^a-zA-Z0-9_-]/', '_', $examRow['title']);
        $safeSubj = preg_replace('/[^a-zA-Z0-9_-]/', '_', $subject);
        $safeTopic = !empty($topic) ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $topic) : 'General';
        $timestamp = time();

        $filename = "study_notes_exam_{$examId}_{$safeSubj}_{$safeTopic}_{$timestamp}.md";
        $targetPath = $uploadDir . $filename;
        $originalName = "Study Notes - {$examRow['title']} - {$subject}" . (!empty($topic) ? " ({$topic})" : "") . ".md";

        if (file_put_contents($targetPath, $markdownContent) === false) {
            return [
                'success' => false,
                'message' => "Failed to write generated study notes to server storage."
            ];
        }

        $fileSize = filesize($targetPath);
        $docTopic = !empty($topic) ? $topic : 'General';

        // 7. Insert Document Record in Database
        $insStmt = $conn->prepare("
            INSERT INTO ai_documents 
            (filename, original_name, file_path, file_size, subject, topic, status, uploaded_by, exam_id, source_question_ids, is_generated)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, 1)
        ");
        $insStmt->bind_param("sssissiis", $filename, $originalName, $targetPath, $fileSize, $subject, $docTopic, $adminId, $examId, $qIdsStr);
        $insStmt->execute();
        $docId = (int)$insStmt->insert_id;
        $insStmt->close();

        // 8. Index into RAG Vector Store
        $aiClient = new AiClient();
        $ingestRes = $aiClient->ingestDocument($targetPath, $docId, $originalName, $subject, $docTopic);

        $chunks = 0;
        $pages = 1;
        $ragSuccess = false;

        if ($ingestRes['status'] === 'success') {
            $docData = $ingestRes['data'] ?? [];
            $pages = (int)($docData['total_pages'] ?? 1);
            $chunks = (int)($docData['total_chunks'] ?? 0);
            $ragSuccess = true;

            $updStmt = $conn->prepare("UPDATE ai_documents SET status = 'ingested', total_pages = ?, total_chunks = ? WHERE id = ?");
            $updStmt->bind_param("iii", $pages, $chunks, $docId);
            $updStmt->execute();
            $updStmt->close();
        } else {
            $errMsg = $ingestRes['message'] ?? 'RAG Ingestion Service failed.';
            $updStmt = $conn->prepare("UPDATE ai_documents SET status = 'failed', error_message = ? WHERE id = ?");
            $updStmt->bind_param("si", $errMsg, $docId);
            $updStmt->execute();
            $updStmt->close();
        }

        // 9. Update question_study_notes for individual question views as well
        try {
            foreach ($questions as $q) {
                $qConcept = (!empty($q['topic']) ? $q['topic'] : $subject) . " Concept (Exam #{$examId})";
                $qNotes = "### Verified Concept from Exam #{$examId} Question #{$q['id']}\n" .
                          "**Question:** " . clean_question_title($q['question']) . "\n" .
                          "**Correct Option:** " . $q['correct_option'] . "\n" .
                          "**Official Explanation:** " . ($q['explanation'] ?: 'Verified in exam question bank.') . "\n";

                $chkQ = $conn->prepare("SELECT id FROM question_study_notes WHERE ai_question_id = ?");
                if ($chkQ) {
                    $chkQ->bind_param("i", $q['id']);
                    $chkQ->execute();
                    $existQsn = $chkQ->get_result()->fetch_assoc();
                    $chkQ->close();

                    if (!$existQsn) {
                        $insQsn = $conn->prepare("INSERT INTO question_study_notes (ai_question_id, subject, topic, concept_title, notes_content, is_grounded, source_count) VALUES (?, ?, ?, ?, ?, 1, 1)");
                        if ($insQsn) {
                            $insQsn->bind_param("issss", $q['id'], $subject, $docTopic, $qConcept, $qNotes);
                            $insQsn->execute();
                            $insQsn->close();
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Non-critical cache insertion error
        }

        if ($ragSuccess) {
            return [
                'success' => true,
                'doc_id' => $docId,
                'filename' => $originalName,
                'pages' => $pages,
                'chunks' => $chunks,
                'question_count' => $qCount,
                'message' => "Study material '{$originalName}' generated from {$qCount} approved question(s) and indexed into RAG successfully! ({$chunks} text chunks indexed)"
            ];
        } else {
            return [
                'success' => true,
                'partial' => true,
                'doc_id' => $docId,
                'filename' => $originalName,
                'question_count' => $qCount,
                'message' => "Study material saved for {$qCount} questions, but RAG indexing reported: " . ($ingestRes['message'] ?? 'Service warning')
            ];
        }
    }

    /**
     * Retrieve subjects that have at least one approved AI-generated question,
     * along with the count of approved questions.
     */
    public static function getSubjectsWithApprovedQuestions($conn): array {
        $subjects = [];
        $sql = "SELECT subject, COUNT(*) as count 
                FROM ai_generated_questions 
                WHERE status = 'approved' AND subject IS NOT NULL AND TRIM(subject) != ''
                GROUP BY subject 
                ORDER BY subject ASC";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $subjects[] = [
                    'subject' => trim($row['subject']),
                    'count' => (int)$row['count']
                ];
            }
        }
        return $subjects;
    }

    /**
     * Retrieve approved questions for a specific subject, strictly excluding
     * pending and rejected questions, and excluding questions from other subjects.
     */
    public static function getApprovedQuestionsForSubject($conn, string $subject, ?array $questionIds = null): array {
        $where = ["status = 'approved'", "subject = ?"];
        $params = [$subject];
        $types = "s";

        if (!empty($questionIds) && is_array($questionIds)) {
            $intIds = array_values(array_filter(array_map('intval', $questionIds)));
            if (!empty($intIds)) {
                $placeholders = implode(',', array_fill(0, count($intIds), '?'));
                $where[] = "id IN ({$placeholders})";
                foreach ($intIds as $qId) {
                    $params[] = $qId;
                    $types .= "i";
                }
            }
        }

        $sql = "SELECT id, exam_id, subject, topic, difficulty, question, option_a, option_b, option_c, option_d, correct_option, explanation, status 
                FROM ai_generated_questions 
                WHERE " . implode(" AND ", $where) . " 
                ORDER BY topic ASC, id ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $questions = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $questions[] = $row;
            }
        }
        $stmt->close();
        return $questions;
    }

    /**
     * Synthesizes verified study material strictly from approved questions of a given subject.
     * Automatically detects and groups questions into their existing topics.
     * Enforces the STRICT RULE:
     * Do NOT invent new topics, introduce unrelated subjects, or use general AI knowledge to fill gaps.
     */
    public static function buildStudyNotesMarkdownForSubject(
        string $subject,
        array $questions
    ): string {
        $count = count($questions);
        $qIds = array_column($questions, 'id');
        $qIdsList = implode(', ', $qIds);
        $timestamp = date('Y-m-d H:i:s');

        // Automatically group questions into their existing topics
        $byTopic = [];
        foreach ($questions as $q) {
            $rawTopic = trim($q['topic'] ?? '');
            $topic = ($rawTopic !== '' && strtolower($rawTopic) !== 'general') ? $rawTopic : "Core {$subject} Principles";
            $byTopic[$topic][] = $q;
        }

        $topicNames = array_keys($byTopic);
        $topicsList = implode(', ', $topicNames);

        $md = [];
        $md[] = "# Course Study Notes: {$subject}";
        $md[] = "**Subject:** {$subject}  ";
        $md[] = "**Scope:** Verified Approved AI Question Bank Only  ";
        $md[] = "**Total Approved Questions:** {$count} Questions  ";
        $md[] = "**Detected Topics / Modules:** {$topicsList}  ";
        $md[] = "**Source Question IDs:** [{$qIdsList}]  ";
        $md[] = "**Generated At:** {$timestamp}  ";
        $md[] = "";
        $md[] = "---";
        $md[] = "";
        $md[] = "## 1. Source Fidelity Notice & Scope Authenticity";
        $md[] = "> **Strict Source Fidelity Policy:** This study material is synthesized strictly and exclusively from the **{$count} approved questions** stored in the database for **{$subject}**.";
        $md[] = "> ";
        $md[] = "> To prevent hallucinations, no external topics, speculative models, or unverified facts have been introduced. If a concept is not directly evaluated by these source questions, the source question set provides insufficient information and external extrapolation is withheld.";
        $md[] = "";
        $md[] = "---";
        $md[] = "";
        $md[] = "## 2. Core Concepts & Definitions (Grouped by Topic)";
        $md[] = "The following core concepts, definitions, and principles are derived exclusively from the approved question set and grouped by detected topic:";
        $md[] = "";

        $topicIdx = 1;
        foreach ($byTopic as $topicName => $topicQuestions) {
            $tCount = count($topicQuestions);
            $md[] = "### 2.{$topicIdx}. {$topicName}";
            $md[] = "- **Topic Scope:** {$tCount} approved question(s) in database.";
            $md[] = "- **Key Principles & Concepts:**";

            $hasExplanations = false;
            $seenConcepts = [];
            foreach ($topicQuestions as $q) {
                $cleanQ = clean_question_title($q['question']);
                $correctOpt = strtoupper(trim($q['correct_option']));
                $optKey = 'option_' . strtolower($correctOpt);
                $correctText = $q[$optKey] ?? $correctOpt;
                $exp = trim($q['explanation'] ?? '');

                if ($exp !== '') {
                    $hasExplanations = true;
                    $key = md5(trim($correctText) . '|' . trim($exp));
                    if (isset($seenConcepts[$key])) {
                        $seenConcepts[$key]['ids'][] = $q['id'];
                    } else {
                        $seenConcepts[$key] = [
                            'text' => "**Concept:** {$correctText} — *{$exp}*",
                            'ids' => [$q['id']]
                        ];
                    }
                } else {
                    $key = md5(trim($cleanQ) . '|' . trim($correctText));
                    if (isset($seenConcepts[$key])) {
                        $seenConcepts[$key]['ids'][] = $q['id'];
                    } else {
                        $seenConcepts[$key] = [
                            'text' => "**Principle:** For problem *\"{$cleanQ}\"*, the correct answer is verified as **{$correctText}**.",
                            'ids' => [$q['id']]
                        ];
                    }
                }
            }

            foreach ($seenConcepts as $item) {
                $idList = implode(', Q#', $item['ids']);
                $md[] = "  - (Q#{$idList}) {$item['text']}";
            }

            if (!$hasExplanations) {
                $md[] = "  - *Information Boundary:* Source questions provide validated correct-option coverage for these points; extended external theoretical background is omitted to ensure zero hallucination.";
            }

            $md[] = "";
            $topicIdx++;
        }

        $md[] = "---";
        $md[] = "";
        $md[] = "## 3. Verified Questions, Solutions & Explanations";
        $md[] = "Complete breakdown of approved questions with options, verified answers, and official explanations:";
        $md[] = "";

        $qNum = 1;
        foreach ($byTopic as $topicName => $topicQuestions) {
            $md[] = "### Module: {$topicName}";
            $md[] = "";

            foreach ($topicQuestions as $q) {
                $cleanQ = clean_question_title($q['question']);
                $correctOpt = strtoupper(trim($q['correct_option']));
                $optKey = 'option_' . strtolower($correctOpt);
                $correctText = $q[$optKey] ?? $correctOpt;
                $exp = trim($q['explanation'] ?? '');
                $qDiff = !empty($q['difficulty']) ? ucfirst($q['difficulty']) : 'Standard';

                $md[] = "#### Question {$qNum} (ID #{$q['id']}) [{$topicName} | Difficulty: {$qDiff}]";
                $md[] = "**Question:** {$cleanQ}";
                $md[] = "";
                $md[] = "- **A)** " . ($q['option_a'] ?? '');
                $md[] = "- **B)** " . ($q['option_b'] ?? '');
                $md[] = "- **C)** " . ($q['option_c'] ?? '');
                $md[] = "- **D)** " . ($q['option_d'] ?? '');
                $md[] = "";
                $md[] = "**Verified Correct Answer:** Option **{$correctOpt}** (`{$correctText}`)";
                if ($exp !== '') {
                    $md[] = "**Official Explanation:** {$exp}";
                } else {
                    $md[] = "**Official Explanation:** Source questions confirm Option {$correctOpt} as the validated answer. Extended rationale is not provided in source question records.";
                }
                $md[] = "**Practice Point:** When encountering *\"{$cleanQ}\"*, recognize that **{$correctText}** is the correct determination under **{$topicName}**.";
                $md[] = "";
                $qNum++;
            }
        }

        $md[] = "---";
        $md[] = "";
        $md[] = "## 4. Key Practice Points & Summary Takeaways";
        $md[] = "Synthesized review summary from the {$count} verified source questions:";
        $md[] = "";

        $takeawayIndex = 1;
        $seenTakeaways = [];
        foreach ($questions as $q) {
            $correctOpt = strtoupper(trim($q['correct_option']));
            $optKey = 'option_' . strtolower($correctOpt);
            $correctText = $q[$optKey] ?? $correctOpt;
            $cleanQ = clean_question_title($q['question']);
            $rawTopic = trim($q['topic'] ?? '');
            $qTopic = ($rawTopic !== '' && strtolower($rawTopic) !== 'general') ? $rawTopic : $subject;

            $takeawayText = "**[{$qTopic}]** {$correctText} is the correct solution for: *\"{$cleanQ}\"*.";
            if (!isset($seenTakeaways[$takeawayText])) {
                $seenTakeaways[$takeawayText] = true;
                $md[] = "{$takeawayIndex}. {$takeawayText}";
                $takeawayIndex++;
            }
        }

        $md[] = "";
        $md[] = "---";
        $md[] = "*Document generated by Online Examination System RAG Pipeline. Strictly sourced from active approved question records.*";

        return implode("\n", $md);
    }

    /**
     * Generates and indexes study materials for a given subject from all of its approved questions.
     * Prevents accidental duplicates by safely reusing and updating existing material for the same subject.
     */
    public static function generateSubjectStudyMaterial(
        $conn,
        string $subject,
        int $adminId = 1,
        ?array $specificQuestionIds = null
    ): array {
        self::ensureSchema($conn);

        $subject = trim($subject);
        if (empty($subject)) {
            return [
                'success' => false,
                'message' => 'Please select a valid subject.'
            ];
        }

        // 1. Fetch only approved questions for this subject
        $questions = self::getApprovedQuestionsForSubject($conn, $subject, $specificQuestionIds);
        $qCount = count($questions);

        if ($qCount === 0) {
            return [
                'success' => false,
                'empty' => true,
                'count' => 0,
                'subject' => $subject,
                'message' => "No approved AI-generated questions found for Subject '{$subject}'. Study material generation aborted."
            ];
        }

        $qIds = array_column($questions, 'id');
        $qIdsStr = implode(',', $qIds);

        // Detect topics automatically
        $byTopic = [];
        foreach ($questions as $q) {
            $rawTopic = trim($q['topic'] ?? '');
            $t = ($rawTopic !== '' && strtolower($rawTopic) !== 'general') ? $rawTopic : "Core Principles";
            $byTopic[$t][] = $q;
        }
        $topicList = implode(', ', array_keys($byTopic));
        $storedTopic = mb_strimwidth($topicList, 0, 100, '...');

        // Detect primary exam ID if present:
        // Use real relationships across questions rather than just the first item.
        // If all questions that have an exam_id point to a single exam, use that exam_id.
        // If questions point to multiple different exams, or have no exam_id, leave null (Subject-level).
        $nonNullExamIds = [];
        foreach ($questions as $q) {
            if (!empty($q['exam_id'])) {
                $nonNullExamIds[] = (int)$q['exam_id'];
            }
        }
        $uniqueExamIds = array_values(array_unique($nonNullExamIds));
        $primaryExamId = (count($uniqueExamIds) === 1) ? $uniqueExamIds[0] : null;

        // 2. Synthesize Markdown strictly grounded in the approved questions
        $markdownContent = self::buildStudyNotesMarkdownForSubject($subject, $questions);

        // 3. Prepare target file path
        $uploadDir = __DIR__ . '/../uploads/course_materials/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $safeSubj = preg_replace('/[^a-zA-Z0-9_-]/', '_', $subject);
        $timestamp = time();

        // 4. Check for existing generated document for this subject to prevent accidental duplication
        $chkStmt = $conn->prepare("
            SELECT id, filename, file_path, original_name, source_question_ids 
            FROM ai_documents 
            WHERE is_generated = 1 AND subject = ? 
            ORDER BY id DESC LIMIT 1
        ");
        $chkStmt->bind_param("s", $subject);
        $chkStmt->execute();
        $existing = $chkStmt->get_result()->fetch_assoc();
        $chkStmt->close();

        $originalName = "Study Notes - {$subject} ({$qCount} Questions).md";

        if ($existing) {
            // Reuse and safely update existing document
            $docId = (int)$existing['id'];
            $targetPath = $existing['file_path'];

            if (empty($targetPath) || !file_exists(dirname($targetPath))) {
                $filename = "study_notes_{$safeSubj}_{$timestamp}.md";
                $targetPath = $uploadDir . $filename;
            } else {
                $filename = basename($targetPath);
            }

            if (file_put_contents($targetPath, $markdownContent) === false) {
                return [
                    'success' => false,
                    'message' => "Failed to write updated study notes to server storage."
                ];
            }

            $fileSize = filesize($targetPath);

            // Re-index into RAG Vector Store
            $aiClient = new AiClient(null, 120);
            $ingestRes = $aiClient->ingestDocument($targetPath, $docId, $originalName, $subject, $storedTopic, 120);

            $chunks = 0;
            $pages = 1;
            if ($ingestRes['status'] === 'success') {
                $docData = $ingestRes['data'] ?? [];
                $pages = (int)($docData['total_pages'] ?? 1);
                $chunks = (int)($docData['total_chunks'] ?? 0);

                $updStmt = $conn->prepare("
                    UPDATE ai_documents 
                    SET filename = ?, original_name = ?, file_path = ?, file_size = ?, topic = ?, status = 'ingested', 
                        total_pages = ?, total_chunks = ?, source_question_ids = ?, exam_id = ?, error_message = NULL 
                    WHERE id = ?
                ");
                $updStmt->bind_param("sssisiisii", $filename, $originalName, $targetPath, $fileSize, $storedTopic, $pages, $chunks, $qIdsStr, $primaryExamId, $docId);
                $updStmt->execute();
                $updStmt->close();

                // Refresh question_study_notes cache
                try {
                    foreach ($questions as $q) {
                        $qConcept = (!empty($q['topic']) ? $q['topic'] : $subject) . " Concept";
                        $qNotes = "### Verified Concept from Question #{$q['id']}\n" .
                                  "**Question:** " . clean_question_title($q['question']) . "\n" .
                                  "**Correct Option:** " . $q['correct_option'] . "\n" .
                                  "**Official Explanation:** " . ($q['explanation'] ?: 'Verified in exam question bank.') . "\n";

                        $chkQ = $conn->prepare("SELECT id FROM question_study_notes WHERE ai_question_id = ?");
                        $chkQ->bind_param("i", $q['id']);
                        $chkQ->execute();
                        $existQsn = $chkQ->get_result()->fetch_assoc();
                        $chkQ->close();

                        if (!$existQsn) {
                            $insQsn = $conn->prepare("INSERT INTO question_study_notes (ai_question_id, subject, topic, concept_title, notes_content, is_grounded, source_count) VALUES (?, ?, ?, ?, ?, 1, 1)");
                            if ($insQsn) {
                                $insQsn->bind_param("issss", $q['id'], $subject, $storedTopic, $qConcept, $qNotes);
                                $insQsn->execute();
                                $insQsn->close();
                            }
                        }
                    }
                } catch (Throwable $e) {}

                return [
                    'success' => true,
                    'updated' => true,
                    'doc_id' => $docId,
                    'exam_id' => $primaryExamId,
                    'subject' => $subject,
                    'count' => $qCount,
                    'pages' => $pages,
                    'chunks' => $chunks,
                    'topics' => array_keys($byTopic),
                    'message' => "Successfully updated and indexed study notes for <strong>" . htmlspecialchars($subject) . "</strong> ({$qCount} approved questions, {$chunks} text chunks indexed into RAG)!"
                ];
            } else {
                $errMsg = $ingestRes['message'] ?? 'RAG Ingestion Service failed.';
                $updStmt = $conn->prepare("UPDATE ai_documents SET status = 'failed', total_chunks = 0, exam_id = ?, error_message = ? WHERE id = ?");
                $updStmt->bind_param("isi", $primaryExamId, $errMsg, $docId);
                $updStmt->execute();
                $updStmt->close();

                return [
                    'success' => false,
                    'partial' => true,
                    'doc_id' => $docId,
                    'exam_id' => $primaryExamId,
                    'subject' => $subject,
                    'count' => $qCount,
                    'message' => "Study notes written for " . htmlspecialchars($subject) . ", but RAG ingestion failed: " . htmlspecialchars($errMsg)
                ];
            }
        } else {
            // New generated document record
            $filename = "study_notes_{$safeSubj}_{$timestamp}.md";
            $targetPath = $uploadDir . $filename;

            if (file_put_contents($targetPath, $markdownContent) === false) {
                return [
                    'success' => false,
                    'message' => "Failed to write generated study notes to server storage."
                ];
            }

            $fileSize = filesize($targetPath);

            $insStmt = $conn->prepare("
                INSERT INTO ai_documents 
                (filename, original_name, file_path, file_size, subject, topic, status, uploaded_by, exam_id, source_question_ids, is_generated)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, 1)
            ");
            $insStmt->bind_param("sssissiis", $filename, $originalName, $targetPath, $fileSize, $subject, $storedTopic, $adminId, $primaryExamId, $qIdsStr);
            $insStmt->execute();
            $docId = (int)$insStmt->insert_id;
            $insStmt->close();

            // Index into RAG Vector Store
            $aiClient = new AiClient(null, 120);
            $ingestRes = $aiClient->ingestDocument($targetPath, $docId, $originalName, $subject, $storedTopic, 120);

            $chunks = 0;
            $pages = 1;

            if ($ingestRes['status'] === 'success') {
                $docData = $ingestRes['data'] ?? [];
                $pages = (int)($docData['total_pages'] ?? 1);
                $chunks = (int)($docData['total_chunks'] ?? 0);

                $updStmt = $conn->prepare("UPDATE ai_documents SET status = 'ingested', total_pages = ?, total_chunks = ?, error_message = NULL WHERE id = ?");
                $updStmt->bind_param("iii", $pages, $chunks, $docId);
                $updStmt->execute();
                $updStmt->close();

                // Refresh question_study_notes cache
                try {
                    foreach ($questions as $q) {
                        $qConcept = (!empty($q['topic']) ? $q['topic'] : $subject) . " Concept";
                        $qNotes = "### Verified Concept from Question #{$q['id']}\n" .
                                  "**Question:** " . clean_question_title($q['question']) . "\n" .
                                  "**Correct Option:** " . $q['correct_option'] . "\n" .
                                  "**Official Explanation:** " . ($q['explanation'] ?: 'Verified in exam question bank.') . "\n";

                        $chkQ = $conn->prepare("SELECT id FROM question_study_notes WHERE ai_question_id = ?");
                        $chkQ->bind_param("i", $q['id']);
                        $chkQ->execute();
                        $existQsn = $chkQ->get_result()->fetch_assoc();
                        $chkQ->close();

                        if (!$existQsn) {
                            $insQsn = $conn->prepare("INSERT INTO question_study_notes (ai_question_id, subject, topic, concept_title, notes_content, is_grounded, source_count) VALUES (?, ?, ?, ?, ?, 1, 1)");
                            if ($insQsn) {
                                $insQsn->bind_param("issss", $q['id'], $subject, $storedTopic, $qConcept, $qNotes);
                                $insQsn->execute();
                                $insQsn->close();
                            }
                        }
                    }
                } catch (Throwable $e) {}

                return [
                    'success' => true,
                    'doc_id' => $docId,
                    'exam_id' => $primaryExamId,
                    'subject' => $subject,
                    'count' => $qCount,
                    'pages' => $pages,
                    'chunks' => $chunks,
                    'topics' => array_keys($byTopic),
                    'message' => "Successfully generated and indexed study material for <strong>" . htmlspecialchars($subject) . "</strong> ({$qCount} approved questions, {$chunks} text chunks indexed into RAG)!"
                ];
            } else {
                $errMsg = $ingestRes['message'] ?? 'RAG Ingestion Service failed.';
                $updStmt = $conn->prepare("UPDATE ai_documents SET status = 'failed', total_chunks = 0, error_message = ? WHERE id = ?");
                $updStmt->bind_param("si", $errMsg, $docId);
                $updStmt->execute();
                $updStmt->close();

                return [
                    'success' => false,
                    'partial' => true,
                    'doc_id' => $docId,
                    'exam_id' => $primaryExamId,
                    'subject' => $subject,
                    'count' => $qCount,
                    'message' => "Study notes generated for " . htmlspecialchars($subject) . ", but RAG ingestion failed: " . htmlspecialchars($errMsg)
                ];
            }
        }
    }

    /**
     * Safely retry indexing for an existing document without duplicating document records or vectors.
     */
    public static function retryIngestion(mysqli $conn, int $docId, int $adminId = 1): array
    {
        self::ensureSchema($conn);

        $stmt = $conn->prepare("SELECT * FROM ai_documents WHERE id = ?");
        $stmt->bind_param("i", $docId);
        $stmt->execute();
        $doc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$doc) {
            return [
                'success' => false,
                'message' => "Document #{$docId} not found."
            ];
        }

        $filePath = $doc['file_path'];
        if (empty($filePath) || !file_exists($filePath)) {
            // If it's a generated document and markdown file is missing, regenerate it
            if (!empty($doc['is_generated']) && !empty($doc['subject'])) {
                return self::generateSubjectStudyMaterial($conn, $doc['subject'], $adminId);
            }
            return [
                'success' => false,
                'message' => "Document file not found on disk: " . htmlspecialchars(basename($filePath ?? 'unknown'))
            ];
        }

        $subject = $doc['subject'] ?? 'General';
        $topic = $doc['topic'] ?? 'General';
        $originalName = $doc['original_name'] ?: basename($filePath);

        // Resolve exam_id if missing from relationships
        $primaryExamId = $doc['exam_id'] ? (int)$doc['exam_id'] : null;
        if ($primaryExamId === null && !empty($doc['subject'])) {
            $approved = self::getApprovedQuestionsForSubject($conn, $doc['subject']);
            $nonNullExamIds = [];
            foreach ($approved as $q) {
                if (!empty($q['exam_id'])) {
                    $nonNullExamIds[] = (int)$q['exam_id'];
                }
            }
            $uniqueExamIds = array_values(array_unique($nonNullExamIds));
            if (count($uniqueExamIds) === 1) {
                $primaryExamId = $uniqueExamIds[0];
            }
        }

        $aiClient = new AiClient(null, 120);
        $ingestRes = $aiClient->ingestDocument($filePath, $docId, $originalName, $subject, $topic, 120);

        if ($ingestRes['status'] === 'success') {
            $docData = $ingestRes['data'] ?? [];
            $pages = (int)($docData['total_pages'] ?? 1);
            $chunks = (int)($docData['total_chunks'] ?? 0);

            $upd = $conn->prepare("
                UPDATE ai_documents 
                SET status = 'ingested', total_pages = ?, total_chunks = ?, exam_id = ?, error_message = NULL 
                WHERE id = ?
            ");
            $upd->bind_param("iiii", $pages, $chunks, $primaryExamId, $docId);
            $upd->execute();
            $upd->close();

            return [
                'success' => true,
                'doc_id' => $docId,
                'exam_id' => $primaryExamId,
                'pages' => $pages,
                'chunks' => $chunks,
                'message' => "Successfully re-indexed <strong>" . htmlspecialchars($originalName) . "</strong> ({$chunks} text chunks indexed into RAG)!"
            ];
        } else {
            $errMsg = $ingestRes['message'] ?? 'RAG Ingestion Service failed.';
            $upd = $conn->prepare("UPDATE ai_documents SET status = 'failed', total_chunks = 0, exam_id = ?, error_message = ? WHERE id = ?");
            $upd->bind_param("isi", $primaryExamId, $errMsg, $docId);
            $upd->execute();
            $upd->close();

            return [
                'success' => false,
                'doc_id' => $docId,
                'exam_id' => $primaryExamId,
                'message' => "RAG ingestion retry failed: " . htmlspecialchars($errMsg)
            ];
        }
    }
}

