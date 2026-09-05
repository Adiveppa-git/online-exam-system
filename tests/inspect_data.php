<?php
require_once __DIR__ . '/../config/db.php';

echo "=== DB INSPECTION ===\n";

$resResults = $conn->query("SELECT r.*, e.title, e.total_marks, e.marks_per_question FROM results r JOIN exams e ON r.exam_id = e.id LIMIT 5");
echo "Results Data:\n";
while ($r = $resResults->fetch_assoc()) {
    print_r($r);
}

$resAnswers = $conn->query("SELECT sa.*, q.question, q.correct_option, q.subject, q.topic, q.difficulty FROM student_answers sa JOIN questions q ON sa.question_id = q.id LIMIT 5");
echo "Student Answers Data:\n";
while ($a = $resAnswers->fetch_assoc()) {
    print_r($a);
}
