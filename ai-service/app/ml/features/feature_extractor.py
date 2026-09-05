from typing import Dict, Any, List
import numpy as np

FEATURE_NAMES = [
    'total_attempts',
    'correct_attempts',
    'incorrect_attempts',
    'correct_rate',
    'incorrect_rate',
    'student_ratio',
    'topic_avg_accuracy_norm',
    'subject_avg_accuracy_norm'
]

def extract_features_from_dict(d: Dict[str, Any]) -> List[float]:
    attempts = max(0, int(d.get('total_attempts', 0)))
    correct = max(0, min(attempts, int(d.get('correct_attempts', 0))))
    incorrect = attempts - correct
    students = max(0, min(attempts, int(d.get('unique_students', 0))))
    
    correct_rate = (correct / attempts) if attempts > 0 else 0.0
    incorrect_rate = 1.0 - correct_rate
    student_ratio = (students / attempts) if attempts > 0 else 1.0
    
    topic_acc = float(d.get('topic_avg_accuracy', 50.0)) / 100.0
    subject_acc = float(d.get('subject_avg_accuracy', 50.0)) / 100.0

    return [
        float(attempts),
        float(correct),
        float(incorrect),
        float(correct_rate),
        float(incorrect_rate),
        float(student_ratio),
        float(topic_acc),
        float(subject_acc)
    ]