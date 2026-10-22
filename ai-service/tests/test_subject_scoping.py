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

def test_9_java_query_subject_and_rag_isolation():
    query = "How can I improve my marks in Java?"
    subj, top = SubjectDetector.detect(query)
    assert subj == "Java"
    assert subj != "Computer Science"

    history = [
        {"subject": "Java", "topic": "Core Principles", "is_correct": False},
        {"subject": "Python", "topic": "codes", "is_correct": True}
    ]
    res = RAGService.answer_question(question=query, subject="Java", history=history)
    ans = res["answer"]
    assert "Computer Science" not in ans
    assert "Java" in ans
    assert "Python" not in ans

def test_10_python_query_subject_and_rag_isolation():
    query = "How can I improve my marks in Python?"
    subj, top = SubjectDetector.detect(query)
    assert subj == "Python"
    assert subj != "Computer Science"

    history = [
        {"subject": "Java", "topic": "Core Principles", "is_correct": False},
        {"subject": "Python", "topic": "codes", "is_correct": False}
    ]
    res = RAGService.answer_question(question=query, subject="Python", history=history)
    ans = res["answer"]
    assert "Computer Science" not in ans
    assert "Python" in ans
    assert "Java" not in ans

def test_11_ambiguous_subject_clarification():
    query = "Compare Java and Python"
    ambig = SubjectDetector.detect_ambiguity(query)
    assert len(ambig) > 1
    assert "Java" in ambig
    assert "Python" in ambig

    res = RAGService.answer_question(question=query)
    assert res["intent"] == "clarification"
    assert "multiple subjects" in res["answer"].lower()
    assert res["sources"] == []

def test_12_zero_performance_study_guidance():
    # Student with 0 attempts in Java should receive guidance and clear explanation
    user_context = {
        "subject_summary": {
            "exists": True,
            "total_exam_questions": 50,
            "approved_ai_questions": 50,
            "available_topics": ["Core Principles"]
        }
    }
    res = RAGService.answer_question(
        question="How can I prepare for Java exam?",
        subject="Java",
        history=[],
        user_context=user_context
    )
    ans = res["answer"]
    assert "Computer Science" not in ans
    assert "Java" in ans
    assert "haven't completed any exam attempts in Java" in ans

def test_13_java_vs_python_comparison_detection():
    # Regression test: Java vs Python comparison identifies both subjects
    query = "Compare Java and Python"
    assert SubjectDetector.is_comparison(query) is True
    matched = SubjectDetector.detect_all(query)
    assert "Java" in matched
    assert "Python" in matched
    assert len(matched) == 2

    ambig = SubjectDetector.detect_ambiguity(query)
    assert len(ambig) == 2
    assert "Java" in ambig
    assert "Python" in ambig

def test_14_other_subject_comparisons_detection():
    # Test C++ vs Python comparison
    ambig_cpp_py = SubjectDetector.detect_ambiguity("Compare C++ and Python")
    assert "C++" in ambig_cpp_py
    assert "Python" in ambig_cpp_py
    assert "C" not in ambig_cpp_py  # C must not falsely trigger for C++
    assert len(ambig_cpp_py) == 2

    # Test C vs C++ comparison
    ambig_c_cpp = SubjectDetector.detect_ambiguity("Compare C and C++")
    assert "C++" in ambig_c_cpp
    assert "C" in ambig_c_cpp
    assert len(ambig_c_cpp) == 2

    # Test Python vs JavaScript comparison
    ambig_py_js = SubjectDetector.detect_ambiguity("Difference between Python and JavaScript")
    assert "Python" in ambig_py_js
    assert "JavaScript" in ambig_py_js
    assert len(ambig_py_js) == 2

def test_15_single_subject_queries_not_ambiguous():
    # Normal single-subject queries should NOT be flagged as ambiguous
    assert SubjectDetector.detect_ambiguity("How do I prepare for my upcoming Python exam?") == []
    assert SubjectDetector.detect_ambiguity("What do you know about C++?") == []
    assert SubjectDetector.detect_ambiguity("Explain inheritance in Java") == []
    assert SubjectDetector.detect_ambiguity("How can I improve my GK marks?") == []
