<?php
require_once __DIR__ . '/../config/db.php';

$res = $conn->query("
    SELECT q.id, q.question, q.difficulty AS assigned_difficulty,
           COUNT(sa.id) AS total_attempts,
           SUM(CASE WHEN sa.answer = q.correct_option THEN 1 ELSE 0 END) AS correct_attempts,
           COUNT(DISTINCT sa.student_id) AS unique_students
    FROM questions q
    LEFT JOIN student_answers sa ON q.id = sa.question_id
    GROUP BY q.id
");

echo "=== QUESTION ATTEMPTS SUMMARY ===\n";
while ($row = $res->fetch_assoc()) {
    print_r($row);
}
