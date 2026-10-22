import pytest
from app.services.intent_classifier import IntentClassifier
from app.services.subject_detector import SubjectDetector
from app.services.rag_service import RAGService
from app.services.llm_service import llm_service

def test_language_detection_and_separation():
    # C++ vs C detection
    subj_cpp, _ = SubjectDetector.detect("What do you know about C++?")
    assert subj_cpp == "C++"

    subj_cpp_ptr, _ = SubjectDetector.detect("Explain pointers in C++ with a simple example.")
    assert subj_cpp_ptr == "C++"

    subj_c, _ = SubjectDetector.detect("Explain pointers in C language.")
    assert subj_c == "C"

    subj_py, _ = SubjectDetector.detect("How do I prepare for my upcoming Python exam?")
    assert subj_py == "Python"

    # Multiple language mentions in comparison
    all_langs = SubjectDetector.detect_all("Compare C++ and Python.")
    assert "C++" in all_langs
    assert "Python" in all_langs
    assert "C" not in all_langs  # "C" should be eliminated because "C++" was matched

def test_comparison_intent_and_handling():
    q = "Compare C++ and Python."
    assert SubjectDetector.is_comparison(q) is True
    # Should identify multiple subjects
    ambig_list = SubjectDetector.detect_ambiguity(q)
    assert len(ambig_list) == 2
    assert "C++" in ambig_list
    assert "Python" in ambig_list

    intent, _ = IntentClassifier.classify(q)
    assert intent == "concept_explanation"

    res = RAGService.answer_question(question=q, subject="Python and C++")
    assert res["intent"] == "concept_explanation"
    ans = res["answer"]
    assert "C++" in ans and "Python" in ans
    assert "Execution Model" in ans or "Memory Management" in ans or "Type System" in ans

def test_cpp_general_question_without_python_materials():
    q = "What do you know about C++?"
    # Provide Python course materials in context
    res = RAGService.answer_question(
        question=q,
        subject="C++",
        user_context={
            "subject_summary": {
                "course_materials": [
                    {"id": 1, "original_name": "python_basics.pdf", "subject": "Python"}
                ]
            }
        }
    )
    # Must answer about C++
    assert "C++" in res["answer"]
    # Must NOT cite Python materials
    assert "python_basics.pdf" not in res["answer"]
    assert len(res["sources"]) == 0

def test_cpp_pointers_explanation():
    q = "Explain pointers in C++ with a simple example."
    res = RAGService.answer_question(question=q, subject="C++")
    ans = res["answer"]
    assert "pointer" in ans.lower()
    assert "&" in ans
    assert "*" in ans
    assert "```cpp" in ans or "```c++" in ans or "```" in ans
    assert len(res["sources"]) == 0

def test_python_exam_prep_prioritizes_python_material():
    q = "How do I prepare for my upcoming Python exam?"
    res = RAGService.answer_question(
        question=q,
        subject="Python",
        user_context={
            "subject_summary": {
                "course_materials": [
                    {"id": 42, "original_name": "python_handbook.pdf", "subject": "Python"}
                ]
            }
        }
    )
    ans = res["answer"]
    assert "Python" in ans
    assert "Phase" in ans or "Roadmap" in ans
