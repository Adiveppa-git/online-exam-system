from fastapi.testclient import TestClient
from app.main import app
from app.config import settings

client = TestClient(app)
AUTH_HEADERS = {"X-Internal-API-Key": settings.INTERNAL_API_KEY}

def test_generate_coding_question_python_second_largest():
    payload = {
        "subject": "Python",
        "topic": "Arrays",
        "difficulty": "medium",
        "question_type": "coding",
        "number_of_questions": 1,
        "additional_context": "Find the second-largest distinct element in an array"
    }
    response = client.post("/api/v1/questions/generate", json=payload, headers=AUTH_HEADERS)
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert len(data["questions"]) == 1
    q = data["questions"][0]
    assert q["question_type"] == "coding"
    assert q["programming_language"] == "Python"
    assert "second-largest" in q["question"].lower() or "second-largest" in q["question"].lower() or "second" in q["question"].lower()
    assert len(q["sample_test_cases"]) >= 1
    assert q["reference_solution"] is not None and len(q["reference_solution"]) > 10
    assert q["time_complexity"] is not None
    assert q["options"] is None

def test_generate_coding_question_java_palindrome():
    payload = {
        "subject": "Java",
        "topic": "Strings",
        "difficulty": "easy",
        "question_type": "coding",
        "number_of_questions": 1,
        "additional_context": "Check whether a string is a palindrome"
    }
    response = client.post("/api/v1/questions/generate", json=payload, headers=AUTH_HEADERS)
    assert response.status_code == 200
    data = response.json()
    q = data["questions"][0]
    assert q["question_type"] == "coding"
    assert q["programming_language"] == "Java"
    assert "palindrome" in q["question"].lower()
    assert len(q["sample_test_cases"]) >= 1
    assert "isPalindrome" in q["reference_solution"] or "palindrome" in q["reference_solution"].lower()
    assert q["options"] is None

def test_generate_coding_question_c_binary_search():
    payload = {
        "subject": "C",
        "topic": "Algorithms",
        "difficulty": "medium",
        "question_type": "coding",
        "number_of_questions": 1,
        "additional_context": "Implement binary search"
    }
    response = client.post("/api/v1/questions/generate", json=payload, headers=AUTH_HEADERS)
    assert response.status_code == 200
    data = response.json()
    q = data["questions"][0]
    assert q["question_type"] == "coding"
    assert q["programming_language"] == "C"
    assert "binary search" in q["question"].lower()
    assert "binarySearch" in q["reference_solution"] or "binary" in q["reference_solution"].lower()
    assert q["options"] is None

def test_generate_coding_question_sql_salary():
    payload = {
        "subject": "SQL",
        "topic": "Queries",
        "difficulty": "medium",
        "question_type": "coding",
        "number_of_questions": 1,
        "additional_context": "Write a query to find the second-highest salary"
    }
    response = client.post("/api/v1/questions/generate", json=payload, headers=AUTH_HEADERS)
    assert response.status_code == 200
    data = response.json()
    q = data["questions"][0]
    assert q["question_type"] == "coding"
    assert q["programming_language"] == "SQL"
    assert "salary" in q["question"].lower()
    assert "SELECT" in q["reference_solution"].upper()
    assert q["options"] is None

def test_generate_coding_question_dsa_stack_array():
    payload = {
        "subject": "Data Structures",
        "topic": "Stacks",
        "difficulty": "medium",
        "question_type": "coding",
        "number_of_questions": 1,
        "additional_context": "Implement a stack using an array"
    }
    response = client.post("/api/v1/questions/generate", json=payload, headers=AUTH_HEADERS)
    assert response.status_code == 200
    data = response.json()
    q = data["questions"][0]
    assert q["question_type"] == "coding"
    assert "stack" in q["question"].lower()
    assert q["options"] is None

def test_generate_coding_arbitrary_custom_subject():
    payload = {
        "subject": "Quantum Computing",
        "topic": "Qubit State Processing",
        "difficulty": "hard",
        "question_type": "coding",
        "number_of_questions": 2
    }
    response = client.post("/api/v1/questions/generate", json=payload, headers=AUTH_HEADERS)
    assert response.status_code == 200
    data = response.json()
    assert len(data["questions"]) == 2
    for q in data["questions"]:
        assert q["question_type"] == "coding"
        assert q["reference_solution"] is not None
        assert q["options"] is None

def test_generate_short_answer_questions():
    payload = {
        "subject": "Operating Systems",
        "topic": "Deadlocks",
        "difficulty": "medium",
        "question_type": "short_answer",
        "number_of_questions": 3
    }
    response = client.post("/api/v1/questions/generate", json=payload, headers=AUTH_HEADERS)
    assert response.status_code == 200
    data = response.json()
    assert len(data["questions"]) == 3
    for q in data["questions"]:
        assert q["question_type"] == "short_answer"
        assert q["options"] is None
        assert q["correct_answer"] is not None and len(q["correct_answer"]) > 5

def test_generate_descriptive_questions():
    payload = {
        "subject": "Computer Networks",
        "topic": "TCP/IP Protocol Architecture",
        "difficulty": "hard",
        "question_type": "descriptive",
        "number_of_questions": 2
    }
    response = client.post("/api/v1/questions/generate", json=payload, headers=AUTH_HEADERS)
    assert response.status_code == 200
    data = response.json()
    assert len(data["questions"]) == 2
    for q in data["questions"]:
        assert q["question_type"] == "descriptive"
        assert q["options"] is None
        assert q["reference_solution"] is not None
        assert "Rubric" in q["explanation"] or "evaluation" in q["explanation"].lower() or "rubric" in q["explanation"].lower()

def test_generate_mixed_questions_count_and_types():
    payload = {
        "subject": "Software Engineering",
        "topic": "Microservices",
        "difficulty": "medium",
        "question_type": "mixed",
        "number_of_questions": 4
    }
    response = client.post("/api/v1/questions/generate", json=payload, headers=AUTH_HEADERS)
    assert response.status_code == 200
    data = response.json()
    assert len(data["questions"]) == 4
    types_found = {q["question_type"] for q in data["questions"]}
    assert "mcq" in types_found
    assert "coding" in types_found
    assert "short_answer" in types_found
    assert "descriptive" in types_found

def test_generate_python_10_coding_mcqs_on_loops():
    payload = {
        "subject": "Python",
        "topic": "loops",
        "difficulty": "medium",
        "question_type": "mcq",
        "number_of_questions": 10
    }
    response = client.post("/api/v1/questions/generate", json=payload, headers=AUTH_HEADERS)
    assert response.status_code == 200
    data = response.json()
    assert len(data["questions"]) == 10
    for q in data["questions"]:
        assert q["question_type"] == "mcq"
        assert q["programming_language"] == "Python"
        assert set(q["options"].keys()) == {"A", "B", "C", "D"}
        assert q["correct_answer"] in ["A", "B", "C", "D"]
        # Verify question is genuinely loop-related
        q_lower = q["question"].lower()
        assert any(k in q_lower for k in ["loop", "for", "while", "range", "enumerate", "zip", "iteration"])

def test_generate_java_coding_mcqs():
    payload = {
        "subject": "Java",
        "topic": "Inheritance and Polymorphism",
        "difficulty": "medium",
        "question_type": "mcq",
        "number_of_questions": 5
    }
    response = client.post("/api/v1/questions/generate", json=payload, headers=AUTH_HEADERS)
    assert response.status_code == 200
    data = response.json()
    assert len(data["questions"]) == 5
    for q in data["questions"]:
        assert q["question_type"] == "mcq"
        assert q["programming_language"] == "Java"
        assert set(q["options"].keys()) == {"A", "B", "C", "D"}
        assert q["correct_answer"] in ["A", "B", "C", "D"]

def test_generate_sql_mcqs():
    payload = {
        "subject": "SQL",
        "topic": "Queries and Aggregations",
        "difficulty": "medium",
        "question_type": "mcq",
        "number_of_questions": 5
    }
    response = client.post("/api/v1/questions/generate", json=payload, headers=AUTH_HEADERS)
    assert response.status_code == 200
    data = response.json()
    assert len(data["questions"]) == 5
    for q in data["questions"]:
        assert q["question_type"] == "mcq"
        assert q["programming_language"] == "SQL"
        assert set(q["options"].keys()) == {"A", "B", "C", "D"}
        assert q["correct_answer"] in ["A", "B", "C", "D"]
        assert any(k in q["question"].lower() for k in ["sql", "salary", "having", "join", "count", "query", "select"])

def test_generate_arbitrary_custom_topic_mcqs():
    payload = {
        "subject": "Bioinformatics",
        "topic": "DNA Sequence Alignment",
        "difficulty": "hard",
        "question_type": "mcq",
        "number_of_questions": 3
    }
    response = client.post("/api/v1/questions/generate", json=payload, headers=AUTH_HEADERS)
    assert response.status_code == 200
    data = response.json()
    assert len(data["questions"]) == 3
    for q in data["questions"]:
        assert q["question_type"] == "mcq"
        assert set(q["options"].keys()) == {"A", "B", "C", "D"}
        assert q["correct_answer"] in ["A", "B", "C", "D"]
        assert len(q["explanation"]) > 10
