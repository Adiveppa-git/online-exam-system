from fastapi.testclient import TestClient
from app.main import app

client = TestClient(app)

def test_generate_questions_valid():
    payload = {
        "subject": "Python Programming",
        "topic": "Object Oriented Programming",
        "difficulty": "medium",
        "number_of_questions": 3
    }
    response = client.post("/api/v1/questions/generate", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert "request_id" in data
    assert len(data["questions"]) == 3

    for item in data["questions"]:
        assert "question" in item
        assert "options" in item
        assert len(item["options"]) == 4
        assert set(item["options"].keys()) == {"A", "B", "C", "D"}
        assert item["correct_answer"] in ["A", "B", "C", "D"]
        assert "explanation" in item
        assert item["subject"] == "Python Programming"
        assert item["topic"] == "Object Oriented Programming"

def test_generate_questions_invalid_number_high():
    payload = {
        "subject": "Python",
        "topic": "Loops",
        "number_of_questions": 50
    }
    response = client.post("/api/v1/questions/generate", json=payload)
    assert response.status_code == 422  # Unprocessable Entity (Pydantic validation error)

def test_generate_questions_invalid_number_low():
    payload = {
        "subject": "Python",
        "topic": "Loops",
        "number_of_questions": 0
    }
    response = client.post("/api/v1/questions/generate", json=payload)
    assert response.status_code == 422

def test_generate_questions_invalid_difficulty():
    payload = {
        "subject": "Python",
        "topic": "Loops",
        "difficulty": "extreme",
        "number_of_questions": 5
    }
    response = client.post("/api/v1/questions/generate", json=payload)
    assert response.status_code == 422