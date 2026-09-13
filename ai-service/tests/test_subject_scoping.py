import pytest
from app.services.subject_detector import SubjectDetector
from app.services.rag_service import RAGService
from app.services.llm_service import LLMService, FocusedNotesRequest

def test_1_gk_query_subject_detection():
    query = "How can I improve my GK marks?"
    subj, top = SubjectDetector.detect(query)
    assert subj == "GK"

def test_2_gk_query_english_doc_not_retrieved():
    # RAG search for GK query must NOT return chunks from English
    res = RAGService.search_context(query="How can I improve my GK marks?", subject="GK")
    for chunk in res.get("chunks", []):
        assert SubjectDetector.normalize_subject(chunk.get("subject")) == "GK"

def test_3_gk_query_maths_performance_not_used():
    history = [
        {"subject": "Maths", "topic": "Differentiation", "is_correct": False},
        {"subject": "Maths", "topic": "Differentiation", "is_correct": False},
    ]
    res = RAGService.answer_question(
        question="How can I improve my GK marks?",
        history=history
    )
    # The answer MUST NOT recommend Maths or Differentiation
    ans = res["answer"]
    assert "Maths" not in ans
    assert "Differentiation" not in ans

def test_4_gk_query_no_gk_material_no_sources():
    # If no GK material exists, sources list MUST be empty
    res = RAGService.answer_question(
        question="How can I improve my GK marks?",
        history=[]
    )
    assert res["sources"] == []
    assert res["has_sufficient_context"] is False
    assert "English" not in res["answer"]

def test_5_gk_query_only_gk_documents_retrieved():
    res = RAGService.search_context(query="GK question", subject="GK")
    for chunk in res.get("chunks", []):
        assert SubjectDetector.normalize_subject(chunk.get("subject")) == "GK"

def test_6_general_query_weakest_subject_allowed():
    query = "Which subject should I improve?"
    subj, _ = SubjectDetector.detect(query)
    assert subj is None # General query (Mode B)

    history = [
        {"subject": "English", "topic": "Grammar", "is_correct": True},
        {"subject": "Maths", "topic": "Differentiation", "is_correct": False},
    ]
    res = RAGService.answer_question(question=query, history=history)
    # Mode B is allowed to identify Maths as weakest
    assert "Maths" in res["answer"] or "Differentiation" in res["answer"]

def test_7_english_grammar_query():
    query = "How can I improve my English Grammar?"
    subj, top = SubjectDetector.detect(query)
    assert subj == "English"
    assert top == "Grammar"

    res = RAGService.search_context(query=query)
    for chunk in res.get("chunks", []):
        assert SubjectDetector.normalize_subject(chunk.get("subject")) == "English"
        if chunk.get("topic"):
            assert chunk.get("topic").lower() == "grammar"

def test_8_focused_study_notes_english_grammar():
    req = FocusedNotesRequest(
        question="Which rule applies to subject-verb agreement in English grammar?",
        subject="English",
        topic="Grammar",
        correct_answer="A",
        explanation="Singular subjects take singular verbs."
    )
    notes_res = LLMService.generate_focused_study_notes(req)
    assert notes_res is not None
    assert "concept_title" in notes_res
    assert "notes_content" in notes_res
    for src in notes_res.get("rag_sources", []):
        if "subject" in src:
            assert SubjectDetector.normalize_subject(src["subject"]) == "English"
