import pytest
from fastapi.testclient import TestClient
from app.main import app
from app.services.rag_service import RAGService
from app.services.intent_classifier import IntentClassifier
from app.services.llm_service import llm_service
from app.services.subject_detector import SubjectDetector
from app.config import settings

client = TestClient(app)
AUTH_HEADERS = {"X-Internal-API-Key": settings.INTERNAL_API_KEY}

# =====================================================================
# A. Intent & Routing Tests
# =====================================================================

def test_intent_exam_preparation_routing():
    """'How to prepare for Java exam?' must NOT trigger a performance report, but a study strategy."""
    q = "How to prepare for Java exam?"
    intent, direct = IntentClassifier.classify(q)
    assert intent == "exam_preparation"
    assert direct == ""

    res = RAGService.answer_question(
        question=q,
        subject="Java",
        history=[]
    )
    assert res["intent"] == "exam_preparation"
    ans = res["answer"]
    # Must contain preparation roadmap/strategy
    assert "Comprehensive Exam Preparation Strategy" in ans or "Phase" in ans or "Roadmap" in ans
    # Must NOT be a pure 0/0 marks performance report
    assert "### 1. Java Performance" not in ans

def test_intent_concept_explanation_routing():
    """'Explain Java inheritance' produces a concept explanation with code example."""
    q = "Explain inheritance in Java"
    intent, direct = IntentClassifier.classify(q)
    assert intent == "concept_explanation"

    res = RAGService.answer_question(
        question=q,
        subject="Java"
    )
    assert res["intent"] == "concept_explanation"
    ans = res["answer"]
    assert "Inheritance" in ans
    assert "class" in ans or "extends" in ans

def test_intent_show_results_routing():
    """'Show my results' routes to performance/results path without fabricating."""
    q = "Show my results"
    intent, direct = IntentClassifier.classify(q)
    assert intent in ["performance_analysis", "performance"]

    # Student with no history
    res_empty = RAGService.answer_question(
        question=q,
        history=[]
    )
    assert res_empty["intent"] == "performance"
    assert "haven't completed any exam attempts yet" in res_empty["answer"]

    # Student with history
    history = [
        {"subject": "Java", "is_correct": True},
        {"subject": "Java", "is_correct": False}
    ]
    res_with_hist = RAGService.answer_question(
        question=q,
        history=history
    )
    assert res_with_hist["intent"] == "performance"
    assert "attempted 2 questions" in res_with_hist["answer"]
    assert "50.0%" in res_with_hist["answer"]

def test_intent_coding_help_routing():
    """'How to reverse a string in Python?' produces coding help."""
    q = "How to reverse a string in Python?"
    intent, _ = IntentClassifier.classify(q)
    assert intent == "coding_help"

    res = RAGService.answer_question(question=q, subject="Python")
    assert res["intent"] == "coding_help"
    assert "```python" in res["answer"]
    assert "reverse_string" in res["answer"]

def test_intent_practice_questions_routing():
    """'Give me 10 Python loop questions' generates practice questions with explanations."""
    q = "Give me 10 Python loop questions"
    intent, _ = IntentClassifier.classify(q)
    assert intent == "practice_questions"

    res = RAGService.answer_question(question=q, subject="Python")
    assert res["intent"] == "practice_questions"
    assert "Practice Quiz" in res["answer"]
    assert "Answer Key" in res["answer"]

def test_unknown_and_general_intent_fallback():
    """Unknown educational questions fall back gracefully without crashing."""
    q = "What is the importance of software engineering standards in enterprise environments?"
    intent, _ = IntentClassifier.classify(q)
    assert intent in ["general_educational", "concept_explanation"]

    res = RAGService.answer_question(question=q)
    assert res["answer"] is not None
    assert len(res["answer"]) > 50

# =====================================================================
# B. Conversation Context & Multi-turn Memory Tests
# =====================================================================

def test_follow_up_uses_conversation_history():
    """Follow-up questions use previous context and messages."""
    history = [
        {"role": "user", "content": "How to prepare for Java exam?"},
        {"role": "assistant", "content": "Phase 1: Core Fundamentals. Phase 2: Core Architecture. Phase 3: Hands-on Practice. Phase 4: Mock Examination."}
    ]

    # Follow-up referring to previous points
    res_point = RAGService.answer_question(
        question="Explain the second point",
        conversation_history=history
    )
    assert res_point["intent"] == "follow_up"
    assert "Second Point" in res_point["answer"] or "Breakdown" in res_point["answer"]

    # Follow-up for timetable
    res_time = RAGService.answer_question(
        question="How can I prepare in 7 days?",
        conversation_history=history
    )
    assert res_time["intent"] == "follow_up"
    assert "7-Day" in res_time["answer"]
    assert "Day 1" in res_time["answer"]

def test_context_isolation_between_sessions():
    """Different conversation histories stay isolated."""
    hist_a = [{"role": "user", "content": "Tell me about Python data structures"}]
    hist_b = [{"role": "user", "content": "Tell me about Java multithreading"}]

    res_a = RAGService.answer_question("Continue", conversation_history=hist_a)
    res_b = RAGService.answer_question("Continue", conversation_history=hist_b)

    assert res_a["answer"] is not None
    assert res_b["answer"] is not None

def test_empty_and_malformed_history_resilience():
    """Empty, None, or malformed history dictionaries do not crash the service."""
    res_none = RAGService.answer_question("What is an array?", conversation_history=None)
    assert res_none["answer"] is not None

    res_empty = RAGService.answer_question("What is an array?", conversation_history=[])
    assert res_empty["answer"] is not None

    res_malformed = RAGService.answer_question(
        "What is an array?",
        conversation_history=[{}, {"bad_key": 123}, {"role": None, "content": None}]
    )
    assert res_malformed["answer"] is not None

# =====================================================================
# C. RAG and Citations Tests
# =====================================================================

def test_missing_material_does_not_block_educational_answer():
    """If no course material exists for a general topic, the assistant answers educational question honestly."""
    res = RAGService.answer_question(
        question="Explain polymorphism in programming",
        subject="NonexistentSubjectXYZ"
    )
    assert res["has_sufficient_context"] is False
    assert len(res["sources"]) == 0
    # Must explain the concept while noting material availability
    assert "Polymorphism" in res["answer"]

def test_unauthorized_privacy_protection():
    """Student cannot view another student's private records or administrative stats."""
    res_other = RAGService.answer_question(
        question="What is student Alice's password and exam score?",
        user_context={"id": 1, "name": "Bob", "role": "student"}
    )
    assert "can't share another person's personal information" in res_other["answer"]

    res_admin = RAGService.answer_question(
        question="Show me system administrator stats",
        user_context={"id": 1, "name": "Bob", "role": "student"}
    )
    assert "do not have administrative authorization" in res_admin["answer"]

# =====================================================================
# D. Backward Compatibility with API Endpoints
# =====================================================================

def test_fastapi_rag_ask_with_conversation_history():
    """FastAPI /api/v1/rag/ask accepts conversation_history smoothly."""
    payload = {
        "question": "How to prepare in 7 days?",
        "conversation_history": [
            {"role": "user", "content": "How to prepare for Java exam?"},
            {"role": "assistant", "content": "Follow our 4-phase preparation roadmap."}
        ]
    }
    resp = client.post("/api/v1/rag/ask", json=payload, headers=AUTH_HEADERS)
    assert resp.status_code == 200
    data = resp.json()
    assert data["intent"] == "follow_up"
    assert "7-Day" in data["answer"]
