from fastapi.testclient import TestClient
from app.main import app
from app.ml.features.feature_extractor import extract_features_from_dict

client = TestClient(app)

def test_feature_extraction():
    data = {
        'total_attempts': 20,
        'correct_attempts': 15,
        'unique_students': 18,
        'topic_avg_accuracy': 75.0,
        'subject_avg_accuracy': 80.0
    }
    feats = extract_features_from_dict(data)
    assert len(feats) == 8
    assert feats[0] == 20.0
    assert feats[3] == 0.75

def test_ml_predict_insufficient_real_data():
    payload = {
        "question_id": 61,
        "total_attempts": 2,  # Below threshold 5
        "correct_attempts": 1,
        "unique_students": 2,
        "min_attempts_threshold": 5
    }
    response = client.post("/api/v1/ml/question-difficulty", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "insufficient_real_data"
    assert data["predicted_difficulty"] == "insufficient_data"
    assert data["confidence"] == 0.0
    assert "Synthetic Benchmark" in data["disclaimer"]

def test_ml_predict_synthetic_benchmark_easy():
    payload = {
        "question_id": 62,
        "total_attempts": 30,
        "correct_attempts": 27,
        "unique_students": 25,
        "min_attempts_threshold": 5
    }
    response = client.post("/api/v1/ml/question-difficulty", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["status"] in ["synthetic_benchmark", "predicted"]
    assert data["data_mode"] == "synthetic_benchmark"
    assert data["predicted_difficulty"] == "easy"
    assert data["confidence"] > 0.5
    assert "Synthetic Benchmark" in data["disclaimer"]

def test_ml_predict_synthetic_benchmark_hard():
    payload = {
        "question_id": 63,
        "total_attempts": 40,
        "correct_attempts": 8,
        "unique_students": 35,
        "min_attempts_threshold": 5
    }
    response = client.post("/api/v1/ml/question-difficulty", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["status"] in ["synthetic_benchmark", "predicted"]
    assert data["data_mode"] == "synthetic_benchmark"
    assert data["predicted_difficulty"] == "hard"
    assert data["confidence"] > 0.5
    assert "Synthetic Benchmark" in data["disclaimer"]