<?php
/**
 * SubjectResolver
 * Resolves authoritative exam subjects dynamically from the database and user query.
 * Connects student queries to exams, questions, results, and course materials.
 */

class SubjectResolver {
    /**
     * Common known aliases and shorthand mapping to canonical subjects.
     */
    private static array $knownAliases = [
        'py' => 'Python',
        'python' => 'Python',
        'java' => 'Java',
        'c++' => 'C++',
        'cpp' => 'C++',
        'c' => 'C',
        'c programming' => 'C',
        'c language' => 'C',
        'javascript' => 'JavaScript',
        'js' => 'JavaScript',
        'typescript' => 'JavaScript',
        'ts' => 'JavaScript',
        'sql' => 'SQL',
        'c#' => 'C#',
        'csharp' => 'C#',
        'php' => 'PHP',
        'math' => 'Maths',
        'maths' => 'Maths',
        'mathematics' => 'Maths',
        'physics' => 'Physics',
        'chemistry' => 'Chemistry',
        'biology' => 'Biology',
        'electronics' => 'Electronics',
        'communication systems' => 'Communication Systems',
        'communication system' => 'Communication Systems',
        'electrical engineering' => 'Electrical Engineering',
        'mechanical engineering' => 'Mechanical Engineering',
        'civil engineering' => 'Civil Engineering',
        'english' => 'English',
        'gk' => 'GK',
        'general knowledge' => 'GK',
        'os' => 'Operating Systems',
        'operating system' => 'Operating Systems',
        'operating systems' => 'Operating Systems',
        'db' => 'Database',
        'dbms' => 'Database',
        'database' => 'Database',
        'cs' => 'Computer Science',
        'computer science' => 'Computer Science',
        'dsa' => 'Computer Science',
        'data structures' => 'Computer Science'
    ];

    /**
     * Resolves the subject from user query, specific exam reference, database subjects, or optional user selection.
     *
     * @param mysqli $conn
     * @param string $query
     * @param string|null $preferredSubject Optional subject selected from subject selector
     * @return array [
     *     'status' => 'resolved' | 'ambiguous' | 'none',
     *     'subject' => string|null,
     *     'subjects' => array,
     *     'exam_id' => int|null,
     *     'exam_title' => string|null,
     *     'is_comparison' => bool
     * ]
     */
    public static function resolve(mysqli $conn, string $query, ?string $preferredSubject = null): array {
        $cleanQuery = trim($query);
        $normPreferred = ($preferredSubject && trim($preferredSubject) !== '' && strtolower(trim($preferredSubject)) !== 'all subjects')
            ? trim($preferredSubject)
            : null;

        if ($cleanQuery === '') {
            return [
                'status' => $normPreferred ? 'resolved' : 'none',
                'subject' => $normPreferred,
                'subjects' => $normPreferred ? [$normPreferred] : [],
                'exam_id' => null,
                'exam_title' => null,
                'is_comparison' => false
            ];
        }

        // 1. Fetch authoritative subjects and exams from database
        $authoritativeSubjects = self::getAuthoritativeSubjects($conn);
        $exams = self::getActiveExams($conn);
        $matchedSubjects = [];
        $matchedExam = null;

        // 2. Check if a specific exam ID is mentioned (e.g., "Exam #331" or "exam 330")
        if (preg_match('/\bexam\s*(?:#|id|number)?\s*(\d+)\b/i', $cleanQuery, $matches)) {
            $examId = (int)$matches[1];
            foreach ($exams as $ex) {
                if ($ex['id'] === $examId && !empty($ex['subject'])) {
                    $matchedExam = $ex;
                    if (!in_array($ex['subject'], $matchedSubjects, true)) {
                        $matchedSubjects[] = $ex['subject'];
                    }
                }
            }
        }

        // 3. Check if a specific exam title is mentioned
        foreach ($exams as $ex) {
            $title = trim($ex['title']);
            if (strlen($title) >= 4 && stripos($cleanQuery, $title) !== false && !empty($ex['subject'])) {
                if ($matchedExam === null) {
                    $matchedExam = $ex;
                }
                if (!in_array($ex['subject'], $matchedSubjects, true)) {
                    $matchedSubjects[] = $ex['subject'];
                }
            }
        }

        // 4. Build complete alias lookup map combining known aliases and DB subjects
        $aliasMap = self::$knownAliases;
        foreach ($authoritativeSubjects as $authSubj) {
            $aliasMap[strtolower(trim($authSubj))] = $authSubj;
        }

        // Sort aliases by length descending so longer tokens (e.g. 'c++', 'c programming') match first
        uksort($aliasMap, function($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        // 5. Match query against subjects and aliases
        $qLower = strtolower($cleanQuery);

        foreach ($aliasMap as $alias => $canonicalSubject) {
            $quotedAlias = preg_quote($alias, '/');
            // For single-letter 'c', do not match if followed by '+' (e.g. C++) or '#' (e.g. C#)
            if ($alias === 'c') {
                $pattern = '/(?<![a-zA-Z0-9_])c(?![a-zA-Z0-9_+#])/i';
            } else {
                $pattern = '/(?<![a-zA-Z0-9_])' . $quotedAlias . '(?![a-zA-Z0-9_])/i';
            }
            if (preg_match($pattern, $qLower)) {
                if (!in_array($canonicalSubject, $matchedSubjects, true)) {
                    $matchedSubjects[] = $canonicalSubject;
                }
            }
        }

        // If C++ was matched, do not allow 'C' unless specifically mentioned as 'c programming' or 'c language'
        if (in_array('C++', $matchedSubjects, true) && in_array('C', $matchedSubjects, true)) {
            if (!preg_match('/\b(c\s+programming|c\s+language|language\s+c)\b/i', $qLower)) {
                $matchedSubjects = array_values(array_diff($matchedSubjects, ['C']));
            }
        }

        // If SQL was matched alongside Database, prefer SQL unless 'database' or 'dbms' was explicitly typed
        if (in_array('SQL', $matchedSubjects, true) && in_array('Database', $matchedSubjects, true)) {
            if (!preg_match('/\b(database|dbms|rdbms)\b/i', $qLower)) {
                $matchedSubjects = array_values(array_diff($matchedSubjects, ['Database']));
            }
        }

        // If a specific language or discipline was matched (e.g. Java, Python, C++, OS, DB, SQL, Physics, etc.),
        // do not let general "Computer Science" trigger ambiguity unless "computer science" or "cs" was specifically typed
        if (count($matchedSubjects) > 1 && in_array('Computer Science', $matchedSubjects, true)) {
            $specifics = array_intersect($matchedSubjects, ['Java', 'Python', 'C++', 'C', 'JavaScript', 'SQL', 'Database', 'Operating Systems', 'Electronics', 'Communication Systems', 'Physics', 'Chemistry', 'Biology']);
            if (!empty($specifics) && !preg_match('/\b(computer science|cs)\b/i', $qLower)) {
                $matchedSubjects = array_values(array_diff($matchedSubjects, ['Computer Science']));
            }
        }

        $isComparison = (bool)preg_match('/\b(compare|comparison|difference\s+between|vs|versus|compared\s+to|differ\s+from)\b/i', $qLower);

        // 6. Handle Ambiguity vs Explicit Comparison
        if (count($matchedSubjects) > 1) {
            // If user explicitly selected a preferred subject that is one of the matched subjects, use it
            if ($normPreferred && in_array($normPreferred, $matchedSubjects, true)) {
                return [
                    'status' => 'resolved',
                    'subject' => $normPreferred,
                    'subjects' => [$normPreferred],
                    'exam_id' => $matchedExam ? $matchedExam['id'] : null,
                    'exam_title' => $matchedExam ? $matchedExam['title'] : null,
                    'is_comparison' => $isComparison
                ];
            }
            return [
                'status' => 'ambiguous',
                'subject' => null,
                'subjects' => $matchedSubjects,
                'exam_id' => null,
                'exam_title' => null,
                'is_comparison' => $isComparison
            ];
        }

        // 7. Single Resolved Subject
        if (count($matchedSubjects) === 1) {
            $subj = $matchedSubjects[0];
            return [
                'status' => 'resolved',
                'subject' => $subj,
                'subjects' => [$subj],
                'exam_id' => $matchedExam ? $matchedExam['id'] : null,
                'exam_title' => $matchedExam ? $matchedExam['title'] : null,
                'is_comparison' => $isComparison
            ];
        }

        // 8. If no subject detected in query, fall back to preferredSubject if provided
        if ($normPreferred) {
            return [
                'status' => 'resolved',
                'subject' => $normPreferred,
                'subjects' => [$normPreferred],
                'exam_id' => null,
                'exam_title' => null,
                'is_comparison' => $isComparison
            ];
        }

        // 9. No subject detected (general query)
        return [
            'status' => 'none',
            'subject' => null,
            'subjects' => [],
            'exam_id' => null,
            'exam_title' => null,
            'is_comparison' => $isComparison
        ];
    }

    /**
     * Retrieves all authoritative subjects assigned in the system from questions, ai_documents, ai_generated_questions, and exams.
     */
    public static function getAuthoritativeSubjects(mysqli $conn): array {
        $subjects = [];
        $sql = "SELECT DISTINCT subject FROM questions WHERE subject IS NOT NULL AND TRIM(subject) != ''
                UNION
                SELECT DISTINCT subject FROM ai_documents WHERE subject IS NOT NULL AND TRIM(subject) != ''
                UNION
                SELECT DISTINCT subject FROM ai_generated_questions WHERE status = 'approved' AND subject IS NOT NULL AND TRIM(subject) != ''
                UNION
                SELECT DISTINCT title AS subject FROM exams WHERE title IS NOT NULL AND TRIM(title) != ''";

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
     * Retrieves active exams and their assigned subjects derived from questions, documents, and exam titles.
     */
    public static function getActiveExams(mysqli $conn): array {
        $exams = [];
        $sql = "SELECT e.id, e.title, COALESCE(q.subject, d.subject, g.subject, e.title) AS subject
                FROM exams e
                LEFT JOIN (
                    SELECT exam_id, subject FROM questions WHERE subject IS NOT NULL AND TRIM(subject) != '' GROUP BY exam_id
                ) q ON e.id = q.exam_id
                LEFT JOIN (
                    SELECT exam_id, subject FROM ai_documents WHERE subject IS NOT NULL AND TRIM(subject) != '' GROUP BY exam_id
                ) d ON e.id = d.exam_id
                LEFT JOIN (
                    SELECT exam_id, subject FROM ai_generated_questions WHERE status = 'approved' AND subject IS NOT NULL AND TRIM(subject) != '' GROUP BY exam_id
                ) g ON e.id = g.exam_id
                ORDER BY e.id ASC";

        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $exams[] = [
                    'id' => (int)$row['id'],
                    'title' => $row['title'],
                    'subject' => $row['subject']
                ];
            }
        }
        return $exams;
    }

    /**
     * Retrieves aggregated subject summary and student performance without exposing answer keys.
     * Evaluates exact figures from the database using a clearly defined policy (latest completed attempt).
     * Calculates marks percentage and question accuracy separately without conflation.
     */
    public static function getSubjectSummary(mysqli $conn, string $subject, int $studentId): array {
        $cleanSubj = trim($subject);

        // 1. Count exams associated with this subject
        $stmt_ex = $conn->prepare("SELECT COUNT(DISTINCT e.id) AS cnt
                                    FROM exams e
                                    LEFT JOIN (
                                        SELECT exam_id, subject FROM questions WHERE subject IS NOT NULL AND TRIM(subject) != ''
                                        UNION
                                        SELECT exam_id, subject FROM ai_documents WHERE subject IS NOT NULL AND TRIM(subject) != ''
                                    ) s ON e.id = s.exam_id
                                    WHERE LOWER(TRIM(COALESCE(s.subject, ''))) = LOWER(?)
                                       OR LOWER(TRIM(e.title)) = LOWER(?)");
        $stmt_ex->bind_param("ss", $cleanSubj, $cleanSubj);
        $stmt_ex->execute();
        $examCount = (int)($stmt_ex->get_result()->fetch_assoc()['cnt'] ?? 0);

        // 2. Count official exam questions
        $stmt_q = $conn->prepare("SELECT COUNT(*) AS cnt FROM questions WHERE LOWER(TRIM(subject)) = LOWER(?)");
        $stmt_q->bind_param("s", $cleanSubj);
        $stmt_q->execute();
        $totalExamQuestions = (int)($stmt_q->get_result()->fetch_assoc()['cnt'] ?? 0);

        // 3. Count approved AI questions
        $stmt_ai = $conn->prepare("SELECT COUNT(*) AS cnt FROM ai_generated_questions WHERE LOWER(TRIM(subject)) = LOWER(?) AND status = 'approved'");
        $stmt_ai->bind_param("s", $cleanSubj);
        $stmt_ai->execute();
        $approvedAiQuestions = (int)($stmt_ai->get_result()->fetch_assoc()['cnt'] ?? 0);

        // 4. Distinct topics in subject (only real, non-empty topics; never fabricate placeholders)
        $topics = [];
        $stmt_top = $conn->prepare("SELECT DISTINCT TRIM(topic) AS top FROM questions WHERE LOWER(TRIM(subject)) = LOWER(?) AND topic IS NOT NULL AND TRIM(topic) != ''");
        $stmt_top->bind_param("s", $cleanSubj);
        $stmt_top->execute();
        $res_top = $stmt_top->get_result();
        while ($row = $res_top->fetch_assoc()) {
            if (!empty($row['top']) && !in_array($row['top'], $topics, true)) {
                $topics[] = $row['top'];
            }
        }

        // 5. Check course materials in database with complete status and chunk metadata
        $stmt_doc = $conn->prepare("
            SELECT d.id, d.filename, d.original_name, d.subject, d.topic, d.status, d.error_message, d.total_chunks, d.exam_id, d.created_at
            FROM ai_documents d
            LEFT JOIN exams e ON d.exam_id = e.id
            WHERE LOWER(TRIM(d.subject)) = LOWER(?)
               OR (d.exam_id IS NOT NULL AND LOWER(TRIM(e.title)) = LOWER(?))
            ORDER BY d.id DESC
        ");
        $stmt_doc->bind_param("ss", $cleanSubj, $cleanSubj);
        $stmt_doc->execute();
        $res_doc = $stmt_doc->get_result();
        $courseMaterials = [];
        $hasIngestedMaterial = false;
        $hasFailedMaterial = false;
        $failedErrors = [];

        while ($row = $res_doc->fetch_assoc()) {
            $docStatus = $row['status'] ?? 'pending';
            if ($docStatus === 'ingested') {
                $hasIngestedMaterial = true;
            } elseif ($docStatus === 'failed') {
                $hasFailedMaterial = true;
                if (!empty($row['error_message'])) {
                    $failedErrors[] = $row['error_message'];
                }
            }
            $courseMaterials[] = [
                'id' => (int)$row['id'],
                'filename' => $row['filename'],
                'original_name' => $row['original_name'],
                'subject' => $row['subject'],
                'topic' => $row['topic'],
                'status' => $docStatus,
                'error_message' => $row['error_message'],
                'total_chunks' => (int)($row['total_chunks'] ?? 0),
                'exam_id' => $row['exam_id'] ? (int)$row['exam_id'] : null,
                'created_at' => $row['created_at']
            ];
        }

        // 6. Student attempt metrics for this subject (strictly scoped to $studentId)
        // Calculated matching student/result.php:
        // total_marks = total_questions * marks_per_question
        // marks_obtained = correct_answers * marks_per_question
        // percentage = (marks_obtained / total_marks) * 100
        // accuracy = (correct_answers / questions_answered) * 100 (from student_answers)
        $examResults = [];

        $stmt_res = $conn->prepare("
            SELECT r.id, r.exam_id, r.score AS correct_answers, e.title AS exam_title, e.marks_per_question,
                   (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS total_questions,
                   r.taken_at
            FROM results r
            JOIN exams e ON r.exam_id = e.id
            LEFT JOIN (
                SELECT DISTINCT exam_id, subject FROM questions WHERE subject IS NOT NULL AND TRIM(subject) != ''
                UNION
                SELECT DISTINCT exam_id, subject FROM ai_documents WHERE subject IS NOT NULL AND TRIM(subject) != ''
            ) s ON r.exam_id = s.exam_id
            WHERE r.user_id = ? AND (LOWER(TRIM(COALESCE(s.subject, ''))) = LOWER(?) OR LOWER(TRIM(e.title)) = LOWER(?))
            ORDER BY r.taken_at DESC, r.id DESC
        ");
        $stmt_res->bind_param("iss", $studentId, $cleanSubj, $cleanSubj);
        $stmt_res->execute();
        $res_std = $stmt_res->get_result();

        while ($row = $res_std->fetch_assoc()) {
            $examId = (int)$row['exam_id'];
            $marksPerQ = !empty($row['marks_per_question']) ? (float)$row['marks_per_question'] : 1.0;
            $totalQ = (int)$row['total_questions'];
            $maxM = $totalQ * $marksPerQ;
            $score = (float)$row['correct_answers'] * $marksPerQ;
            $pct = ($maxM > 0) ? round(($score / $maxM) * 100, 2) : 0.0;

            // Fetch actual answered questions count and correct count from student_answers
            $answeredCount = 0;
            $correctAnsweredCount = 0;
            $stmt_ans = $conn->prepare("
                SELECT COUNT(*) AS answered_count,
                       SUM(CASE WHEN TRIM(sa.answer) = TRIM(q.correct_option) THEN 1 ELSE 0 END) AS correct_count
                FROM student_answers sa
                JOIN questions q ON sa.question_id = q.id
                WHERE sa.student_id = ? AND sa.exam_id = ?
            ");
            if ($stmt_ans) {
                $stmt_ans->bind_param("ii", $studentId, $examId);
                $stmt_ans->execute();
                $ansRow = $stmt_ans->get_result()->fetch_assoc();
                $answeredCount = (int)($ansRow['answered_count'] ?? 0);
                $correctAnsweredCount = (int)($ansRow['correct_count'] ?? 0);
            }

            $questionAccuracy = ($answeredCount > 0)
                ? round(($correctAnsweredCount / $answeredCount) * 100, 2)
                : null;

            $dispMarks = intval($score) == $score ? intval($score) : $score;
            $dispTotal = intval($maxM) == $maxM ? intval($maxM) : $maxM;

            $examResults[] = [
                'result_id' => (int)$row['id'],
                'exam_id' => $examId,
                'exam_title' => $row['exam_title'],
                'correct_answers' => (int)$row['correct_answers'],
                'total_questions' => $totalQ,
                'marks_per_question' => $marksPerQ,
                'score' => $score,
                'marks_obtained' => $score,
                'total_marks' => $maxM,
                'marks_display' => "{$dispMarks}/{$dispTotal}",
                'percentage' => $pct,
                'questions_answered' => $answeredCount,
                'answered_correct' => $correctAnsweredCount,
                'question_accuracy' => $questionAccuracy,
                'has_question_accuracy' => ($answeredCount > 0),
                'taken_at' => $row['taken_at']
            ];
        }

        // Apply clearly defined policy: use the latest completed attempt for specific performance figures
        $latestAttempt = !empty($examResults) ? $examResults[0] : null;
        $studentAttempts = count($examResults);

        $primaryMarksObtained = $latestAttempt ? $latestAttempt['marks_obtained'] : 0.0;
        $primaryTotalMarks = $latestAttempt ? $latestAttempt['total_marks'] : 0.0;
        $primaryPercentage = $latestAttempt ? $latestAttempt['percentage'] : 0.0;
        $primaryMarksDisplay = $latestAttempt ? $latestAttempt['marks_display'] : "0/0";
        $primaryQuestionsAnswered = $latestAttempt ? $latestAttempt['questions_answered'] : 0;
        $primaryQuestionAccuracy = $latestAttempt ? $latestAttempt['question_accuracy'] : null;

        // 7. Check question-level topic metadata for student attempts in this subject
        // Only consider actual non-empty topics from answered questions
        $hasTopicEvidence = false;
        $topicBreakdown = [];
        if ($latestAttempt) {
            $latestExamId = $latestAttempt['exam_id'];
            $stmt_topics = $conn->prepare("
                SELECT q.topic, sa.answer, q.correct_option
                FROM student_answers sa
                JOIN questions q ON sa.question_id = q.id
                WHERE sa.student_id = ? AND sa.exam_id = ?
            ");
            if ($stmt_topics) {
                $stmt_topics->bind_param("ii", $studentId, $latestExamId);
                $stmt_topics->execute();
                $res_top_chk = $stmt_topics->get_result();
                while ($tRow = $res_top_chk->fetch_assoc()) {
                    $rawTopic = trim($tRow['topic'] ?? '');
                    if ($rawTopic !== '') {
                        $hasTopicEvidence = true;
                        if (!isset($topicBreakdown[$rawTopic])) {
                            $topicBreakdown[$rawTopic] = ['total' => 0, 'correct' => 0];
                        }
                        $topicBreakdown[$rawTopic]['total']++;
                        if (trim($tRow['answer']) === trim($tRow['correct_option'])) {
                            $topicBreakdown[$rawTopic]['correct']++;
                        }
                    }
                }
            }
        }

        return [
            'subject' => $cleanSubj,
            'exists' => ($examCount > 0 || $totalExamQuestions > 0 || !empty($courseMaterials)),
            'exam_count' => $examCount,
            'total_exam_questions' => $totalExamQuestions,
            'approved_ai_questions' => $approvedAiQuestions,
            'available_topics' => $topics,
            'course_materials' => $courseMaterials,
            'has_ingested_material' => $hasIngestedMaterial,
            'has_failed_material' => $hasFailedMaterial,
            'failed_errors' => $failedErrors,
            'student_attempts_count' => $studentAttempts,
            'marks_obtained' => $primaryMarksObtained,
            'total_marks' => $primaryTotalMarks,
            'marks_display' => $primaryMarksDisplay,
            'percentage' => $primaryPercentage,
            'student_accuracy' => $primaryPercentage, // Kept for backwards compatibility
            'questions_answered' => $primaryQuestionsAnswered,
            'question_accuracy' => $primaryQuestionAccuracy,
            'has_question_accuracy' => ($primaryQuestionAccuracy !== null),
            'latest_attempt' => $latestAttempt,
            'attempt_policy' => 'latest_completed_attempt',
            'has_topic_evidence' => $hasTopicEvidence,
            'topic_breakdown' => $topicBreakdown,
            'exam_results' => $examResults
        ];
    }
}
