from fastapi.testclient import TestClient
from app.main import app

client = TestClient(app)

def test_performance_no_history():
    payload = {
        "student_id": 101,
        "exams": [],
        "topics": []
    }
    response = client.post("/api/v1/performance/analyze", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["student_id"] == 101
    assert data["total_exams_attempted"] == 0
    assert data["overall_accuracy"] == 0.0
    assert data["trend"]["direction"] == "insufficient_data"

def test_performance_multiple_exams_and_topics():
    payload = {
        "student_id": 4,
        "strong_threshold": 80.0,
        "weak_threshold": 50.0,
        "exams": [
            {"exam_id": 24, "title": "IA 1", "score": 6, "total_marks": 10, "percentage": 60.0, "taken_at": "2026-03-10"},
            {"exam_id": 25, "title": "IA 2", "score": 9, "total_marks": 10, "percentage": 90.0, "taken_at": "2026-03-15"}
        ],
        "topics": [
            {"subject": "Python", "topic": "Functions", "attempted": 10, "correct": 9, "accuracy": 90.0},
            {"subject": "Python", "topic": "OOP", "attempted": 10, "correct": 4, "accuracy": 40.0},
            {"subject": "Database", "topic": "SQL", "attempted": 10, "correct": 7, "accuracy": 70.0}
        ]
    }
    response = client.post("/api/v1/performance/analyze", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["total_exams_attempted"] == 2
    assert data["total_questions_attempted"] == 30
    assert data["total_correct"] == 20
    assert data["overall_accuracy"] == 66.67
    assert data["average_exam_percentage"] == 75.0

    # Classifications
    strong_names = [t["topic"] for t in data["strong_topics"]]
    weak_names = [t["topic"] for t in data["weak_topics"]]
    assert "Functions" in strong_names
    assert "OOP" in weak_names

    # Trend
    assert data["trend"]["has_trend"] is True
    assert data["trend"]["direction"] == "improving"
    assert data["trend"]["trend_percentage_points"] == 30.0