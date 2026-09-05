import pytest
from fastapi.testclient import TestClient
from app.main import app
from app.services.recommendation_service import RecommendationService

client = TestClient(app)

def test_profile_no_history():
    profile = RecommendationService.build_student_profile(student_id=1, history=[])
    assert profile["status"] == "insufficient_data"
    assert profile["total_questions"] == 0
    assert len(profile["topic_metrics"]) == 0

def test_profile_insufficient_attempts_threshold():
    # Only 3 attempts (< MIN_TOPIC_ATTEMPTS=5)
    history = [
        {"subject": "OS", "topic": "Processes", "is_correct": False},
        {"subject": "OS", "topic": "Processes", "is_correct": False},
        {"subject": "OS", "topic": "Processes", "is_correct": True}
    ]
    profile = RecommendationService.build_student_profile(student_id=2, history=history)
    metrics = profile["topic_metrics"]
    assert len(metrics) == 1
    assert metrics[0]["classification"] == "INSUFFICIENT_DATA"
    assert metrics[0]["reason"] == "insufficient_attempts"

def test_profile_weak_topic_declining_trend():
    # 6 attempts with declining accuracy (first 3 correct, last 3 incorrect)
    history = [
        {"subject": "DBMS", "topic": "Normalization", "is_correct": True},
        {"subject": "DBMS", "topic": "Normalization", "is_correct": True},
        {"subject": "DBMS", "topic": "Normalization", "is_correct": True},
        {"subject": "DBMS", "topic": "Normalization", "is_correct": False},
        {"subject": "DBMS", "topic": "Normalization", "is_correct": False},
        {"subject": "DBMS", "topic": "Normalization", "is_correct": False}
    ]
    profile = RecommendationService.build_student_profile(student_id=3, history=history)
    metric = profile["topic_metrics"][0]
    assert metric["classification"] == "NEEDS_IMPROVEMENT"
    assert metric["trend"] == "declining"
    assert metric["recommended_difficulty"] == "easy"
    assert metric["priority_score"] > 0.5  # High priority due to weakness + declining trend

def test_profile_strong_topic_improving_trend():
    # 6 attempts with improving accuracy (first 3 incorrect, last 3 correct) -> Wait, 6 correct total = 100%
    history = [
        {"subject": "Networks", "topic": "TCP/IP", "is_correct": True},
        {"subject": "Networks", "topic": "TCP/IP", "is_correct": True},
        {"subject": "Networks", "topic": "TCP/IP", "is_correct": True},
        {"subject": "Networks", "topic": "TCP/IP", "is_correct": True},
        {"subject": "Networks", "topic": "TCP/IP", "is_correct": True},
        {"subject": "Networks", "topic": "TCP/IP", "is_correct": True}
    ]
    profile = RecommendationService.build_student_profile(student_id=4, history=history)
    metric = profile["topic_metrics"][0]
    assert metric["classification"] == "STRONG"
    assert metric["recommended_difficulty"] == "hard"
    assert metric["reason"] == "strong_topic_challenge"

def test_fastapi_recommendation_endpoints():
    history = [
        {"subject": "DS", "topic": "Trees", "is_correct": False},
        {"subject": "DS", "topic": "Trees", "is_correct": False},
        {"subject": "DS", "topic": "Trees", "is_correct": False},
        {"subject": "DS", "topic": "Trees", "is_correct": False},
        {"subject": "DS", "topic": "Trees", "is_correct": False}
    ]
    payload = {"student_id": 10, "history": history}

    # Profile API
    res_prof = client.post("/api/v1/recommendations/profile", json=payload)
    assert res_prof.status_code == 200
    assert res_prof.json()["status"] == "reliable"

    # Plan API
    res_plan = client.post("/api/v1/recommendations/plan", json=payload)
    assert res_plan.status_code == 200
    assert "plan_items" in res_plan.json()

    # Practice Gen API
    practice_payload = {
        "subject": "DS",
        "topic": "Trees",
        "difficulty": "easy",
        "number_of_questions": 5
    }
    res_prac = client.post("/api/v1/recommendations/practice-questions", json=practice_payload)
    assert res_prac.status_code == 200
    assert len(res_prac.json()["questions"]) == 5
