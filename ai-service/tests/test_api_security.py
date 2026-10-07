from fastapi.testclient import TestClient
from app.main import app
from app.config import settings

client = TestClient(app)

PROTECTED_ENDPOINTS = [
    ("POST", "/api/v1/questions/generate", {"subject": "Python", "topic": "Functions"}),
    ("POST", "/api/v1/questions/focused-notes", {"question": "What is Python?", "subject": "Python", "topic": "Basics"}),
    ("POST", "/api/v1/rag/ingest", {"document_id": 1, "filename": "test.txt", "file_content_base64": "dGVzdA=="}),
    ("POST", "/api/v1/rag/search", {"query": "Python"}),
    ("POST", "/api/v1/rag/ask", {"question": "What is OOP?"}),
    ("DELETE", "/api/v1/rag/document/999", None),
    ("POST", "/api/v1/performance/analyze", {"student_id": 1, "history": []}),
    ("POST", "/api/v1/ml/question-difficulty", {"question_id": 1, "attempts": []}),
    ("POST", "/api/v1/recommendations/profile", {"student_id": 1, "history": []}),
    ("POST", "/api/v1/recommendations/plan", {"student_id": 1, "history": []}),
    ("POST", "/api/v1/recommendations/practice-questions", {"subject": "Math", "topic": "Algebra"}),
]

def test_protected_endpoints_missing_key_returns_401():
    for method, path, payload in PROTECTED_ENDPOINTS:
        if method == "POST":
            response = client.post(path, json=payload)
        elif method == "DELETE":
            response = client.delete(path)
        
        assert response.status_code == 401, f"Expected 401 for unauthenticated {method} {path}, got {response.status_code}"
        data = response.json()
        assert data.get("detail") == "Internal API authentication required."

def test_protected_endpoints_invalid_key_returns_403():
    bad_headers = {"X-Internal-API-Key": "completely_invalid_secret_key"}
    for method, path, payload in PROTECTED_ENDPOINTS:
        if method == "POST":
            response = client.post(path, json=payload, headers=bad_headers)
        elif method == "DELETE":
            response = client.delete(path, headers=bad_headers)
        
        assert response.status_code == 403, f"Expected 403 for invalid key on {method} {path}, got {response.status_code}"
        data = response.json()
        assert data.get("detail") == "Invalid internal API credentials."

def test_protected_endpoint_valid_key_succeeds():
    valid_headers = {"X-Internal-API-Key": settings.INTERNAL_API_KEY}
    payload = {"subject": "Python", "topic": "Variables", "difficulty": "medium", "number_of_questions": 1}
    response = client.post("/api/v1/questions/generate", json=payload, headers=valid_headers)
    assert response.status_code == 200
    assert response.json().get("status") == "success"

def test_protected_endpoint_legacy_x_api_key_succeeds():
    legacy_headers = {"X-API-Key": settings.INTERNAL_API_KEY}
    payload = {"subject": "Python", "topic": "Variables", "difficulty": "medium", "number_of_questions": 1}
    response = client.post("/api/v1/questions/generate", json=payload, headers=legacy_headers)
    assert response.status_code == 200

def test_unauthenticated_health_endpoints_accessible():
    r1 = client.get("/health")
    assert r1.status_code == 200
    assert r1.json().get("status") == "ok"

    r2 = client.get("/api/v1/health")
    assert r2.status_code == 200
    assert r2.json().get("status") == "ok"

def test_unauthenticated_readiness_endpoint_accessible():
    r = client.get("/readiness")
    assert r.status_code in (200, 503)
    data = r.json()
    assert "status" in data
    assert "service" in data

def test_responses_never_expose_secrets():
    bad_secret = "super_secret_invalid_key_12345"
    bad_headers = {"X-Internal-API-Key": bad_secret}
    
    response = client.post("/api/v1/questions/generate", json={}, headers=bad_headers)
    body_text = response.text
    
    assert bad_secret not in body_text
    assert settings.INTERNAL_API_KEY not in body_text
    assert "postgres://" not in body_text
    assert "SUPABASE_DB_URL" not in body_text
