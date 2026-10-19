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
        'math' => 'Maths',
        'maths' => 'Maths',
        'mathematics' => 'Maths',
        'english' => 'English',
        'gk' => 'GK',
        'general knowledge' => 'GK',
        'os' => 'Operating Systems',
        'operating system' => 'Operating Systems',
        'operating systems' => 'Operating Systems',
        'db' => 'Database',
        'dbms' => 'Database',
        'sql' => 'Database',
        'database' => 'Database',
        'cs' => 'Computer Science',
        'computer science' => 'Computer Science'
    ];

    /**
     * Resolves the subject from user query, specific exam reference, or database subjects.
     *
     * @param mysqli $conn
     * @param string $query
     * @return array [
     *     'status' => 'resolved' | 'ambiguous' | 'none',
     *     'subject' => string|null,
     *     'subjects' => array,
     *     'exam_id' => int|null,
     *     'exam_title' => string|null
     * ]
     */
    public static function resolve(mysqli $conn, string $query): array {
        $cleanQuery = trim($query);
        if ($cleanQuery === '') {
            return ['status' => 'none', 'subject' => null, 'subjects' => [], 'exam_id' => null, 'exam_title' => null];
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

        // 5. Match query against subjects and aliases
        $qLower = strtolower($cleanQuery);

        foreach ($aliasMap as $alias => $canonicalSubject) {
            $quotedAlias = preg_quote($alias, '/');
            if (preg_match('/\b' . $quotedAlias . '\b/i', $qLower)) {
                if (!in_array($canonicalSubject, $matchedSubjects, true)) {
                    $matchedSubjects[] = $canonicalSubject;
                }
            }
        }

        // If a specific language or discipline was matched (e.g. Java, Python, C++, OS, DB),
        // do not let general "Computer Science" trigger ambiguity unless "computer science" or "cs" was specifically typed
        if (count($matchedSubjects) > 1 && in_array('Computer Science', $matchedSubjects, true)) {
            $specifics = array_intersect($matchedSubjects, ['Java', 'Python', 'C++', 'Database', 'Operating Systems']);
            if (!empty($specifics) && !preg_match('/\b(computer science|cs)\b/i', $qLower)) {
                $matchedSubjects = array_values(array_diff($matchedSubjects, ['Computer Science']));
            }
        }

        // 6. Handle Ambiguity
        if (count($matchedSubjects) > 1) {
            return [
                'status' => 'ambiguous',
                'subject' => null,
                'subjects' => $matchedSubjects,
                'exam_id' => null,
                'exam_title' => null
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
                'exam_title' => $matchedExam ? $matchedExam['title'] : null
            ];
        }

        // 8. No subject detected (general query)
        return [
            'status' => 'none',
            'subject' => null,
            'subjects' => [],
            'exam_id' => null,
            'exam_title' => null
        ];
    }

    /**
     * Retrieves all authoritative subjects assigned in the system from questions, ai_documents, and ai_generated_questions.
     */
    public static function getAuthoritativeSubjects(mysqli $conn): array {
        $subjects = [];
        $sql = "SELECT DISTINCT subject FROM questions WHERE subject IS NOT NULL AND TRIM(subject) != ''
                UNION
                SELECT DISTINCT subject FROM ai_documents WHERE subject IS NOT NULL AND TRIM(subject) != ''
                UNION
                SELECT DISTINCT subject FROM ai_generated_questions WHERE status = 'approved' AND subject IS NOT NULL AND TRIM(subject) != ''";

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
     * Retrieves active exams and their assigned subjects derived from questions and documents.
     */
    public static function getActiveExams(mysqli $conn): array {
        $exams = [];
        $sql = "SELECT e.id, e.title, COALESCE(q.subject, d.subject, g.subject) AS subject
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
                WHERE COALESCE(q.subject, d.subject, g.subject) IS NOT NULL
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
     */
    public static function getSubjectSummary(mysqli $conn, string $subject, int $studentId): array {
        $cleanSubj = trim($subject);

        // 1. Count exams associated with this subject
        $stmt_ex = $conn->prepare("SELECT COUNT(DISTINCT e.id) AS cnt
                                    FROM exams e
                                    JOIN (
                                        SELECT exam_id, subject FROM questions WHERE subject IS NOT NULL AND TRIM(subject) != ''
                                        UNION
                                        SELECT exam_id, subject FROM ai_documents WHERE subject IS NOT NULL AND TRIM(subject) != ''
                                    ) s ON e.id = s.exam_id
                                    WHERE LOWER(TRIM(s.subject)) = LOWER(?)");
        $stmt_ex->bind_param("s", $cleanSubj);
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

        // 4. Distinct topics in subject
        $topics = [];
        $stmt_top = $conn->prepare("SELECT DISTINCT COALESCE(NULLIF(TRIM(topic), ''), 'Core Principles') AS top FROM questions WHERE LOWER(TRIM(subject)) = LOWER(?)");
        $stmt_top->bind_param("s", $cleanSubj);
        $stmt_top->execute();
        $res_top = $stmt_top->get_result();
        while ($row = $res_top->fetch_assoc()) {
            if (!empty($row['top']) && !in_array($row['top'], $topics, true)) {
                $topics[] = $row['top'];
            }
        }

        // 5. Check indexed course materials
        $stmt_doc = $conn->prepare("SELECT id, filename, original_name FROM ai_documents WHERE LOWER(TRIM(subject)) = LOWER(?)");
        $stmt_doc->bind_param("s", $cleanSubj);
        $stmt_doc->execute();
        $res_doc = $stmt_doc->get_result();
        $courseMaterials = [];
        while ($row = $res_doc->fetch_assoc()) {
            $courseMaterials[] = [
                'id' => (int)$row['id'],
                'filename' => $row['filename'],
                'original_name' => $row['original_name']
            ];
        }

        // 6. Student attempt metrics for this subject (strictly scoped to $studentId)
        // Calculated matching student/result.php:
        // total_marks = total_questions * marks_per_question
        // marks_obtained = correct_answers * marks_per_question
        // percentage = (marks_obtained / total_marks) * 100
        $studentAttempts = 0;
        $totalMarksObtained = 0.0;
        $totalMarksPossible = 0.0;
        $examResults = [];

        $stmt_res = $conn->prepare("SELECT r.id, r.exam_id, r.score AS correct_answers, e.title AS exam_title, e.marks_per_question,
                                           (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS total_questions,
                                           r.taken_at
                                    FROM results r
                                    JOIN exams e ON r.exam_id = e.id
                                    JOIN (
                                        SELECT DISTINCT exam_id, subject FROM questions WHERE subject IS NOT NULL AND TRIM(subject) != ''
                                        UNION
                                        SELECT DISTINCT exam_id, subject FROM ai_documents WHERE subject IS NOT NULL AND TRIM(subject) != ''
                                    ) s ON r.exam_id = s.exam_id
                                    WHERE r.user_id = ? AND LOWER(TRIM(s.subject)) = LOWER(?)
                                    ORDER BY r.taken_at DESC");
        $stmt_res->bind_param("is", $studentId, $cleanSubj);
        $stmt_res->execute();
        $res_std = $stmt_res->get_result();
        while ($row = $res_std->fetch_assoc()) {
            $studentAttempts++;
            $marksPerQ = !empty($row['marks_per_question']) ? (float)$row['marks_per_question'] : 1.0;
            $totalQ = (int)$row['total_questions'];
            $maxM = $totalQ * $marksPerQ;
            $score = (float)$row['correct_answers'] * $marksPerQ;

            $totalMarksObtained += $score;
            $totalMarksPossible += $maxM;
            $pct = ($maxM > 0) ? round(($score / $maxM) * 100, 2) : 0.0;

            $examResults[] = [
                'exam_title' => $row['exam_title'],
                'correct_answers' => (int)$row['correct_answers'],
                'total_questions' => $totalQ,
                'score' => $score,
                'total_marks' => $maxM,
                'percentage' => $pct,
                'taken_at' => $row['taken_at']
            ];
        }

        $overallPercentage = ($totalMarksPossible > 0)
            ? round(($totalMarksObtained / $totalMarksPossible) * 100, 2)
            : 0.0;

        // 7. Check question-level topic metadata for student attempts in this subject
        $stmt_topics = $conn->prepare("SELECT q.topic, sa.answer, q.correct_option
                                       FROM student_answers sa
                                       JOIN questions q ON sa.question_id = q.id
                                       WHERE sa.student_id = ? AND LOWER(TRIM(q.subject)) = LOWER(?)");
        $hasTopicEvidence = false;
        $topicBreakdown = [];
        if ($stmt_topics) {
            $stmt_topics->bind_param("is", $studentId, $cleanSubj);
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

        return [
            'subject' => $cleanSubj,
            'exists' => ($examCount > 0 || $totalExamQuestions > 0 || !empty($courseMaterials)),
            'exam_count' => $examCount,
            'total_exam_questions' => $totalExamQuestions,
            'approved_ai_questions' => $approvedAiQuestions,
            'available_topics' => $topics,
            'course_materials' => $courseMaterials,
            'student_attempts_count' => $studentAttempts,
            'marks_obtained' => $totalMarksObtained,
            'total_marks' => $totalMarksPossible,
            'student_accuracy' => $overallPercentage,
            'percentage' => $overallPercentage,
            'has_topic_evidence' => $hasTopicEvidence,
            'topic_breakdown' => $topicBreakdown,
            'exam_results' => $examResults
        ];
    }
}
