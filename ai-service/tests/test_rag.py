import os
import tempfile
import pytest
from fastapi.testclient import TestClient
from app.main import app
from app.services.document_loader import DocumentLoader, DocumentLoaderError
from app.services.chunker import TextChunker
from app.services.vector_store import VectorStoreManager
from app.services.rag_service import RAGService

client = TestClient(app)

@pytest.fixture
def sample_text_file():
    with tempfile.NamedTemporaryFile(mode="w", suffix=".txt", delete=False, encoding="utf-8") as f:
        f.write(
            "Operating Systems Unit 1: Process Management.\n\n"
            "Process scheduling is the activity of the process manager that handles the removal of the running process "
            "from the CPU and the selection of another process on the basis of a particular strategy.\n\n"
            "Process scheduling is an essential part of a Multiprogramming operating system. "
            "Common algorithms include First-Come First-Served (FCFS), Shortest Job First (SJF), and Round Robin (RR)."
        )
        file_path = f.name
    yield file_path
    if os.path.exists(file_path):
        os.remove(file_path)

def test_document_loader_txt(sample_text_file):
    info = DocumentLoader.load_document(sample_text_file, "os_unit1.txt")
    assert info["total_pages"] == 1
    assert len(info["pages"]) == 1
    assert "Process scheduling" in info["pages"][0]["text"]

def test_document_loader_invalid_ext():
    with tempfile.NamedTemporaryFile(suffix=".exe", delete=False) as f:
        f.write(b"binary data")
        fpath = f.name
    
    with pytest.raises(DocumentLoaderError):
        DocumentLoader.load_document(fpath, "malicious.exe")
    
    if os.path.exists(fpath):
        os.remove(fpath)

def test_text_chunker():
    pages = [{"page_number": 1, "text": "Header Section.\n\nParagraph 1 detailing CPU scheduling algorithms.\n\nParagraph 2 detailing memory allocation."}]
    chunks = TextChunker.chunk_document_pages(
        document_id=99,
        filename="os_notes.txt",
        subject="OS",
        topic="CPU",
        pages=pages,
        chunk_size=100,
        chunk_overlap=10
    )
    assert len(chunks) >= 1
    assert chunks[0]["document_id"] == 99
    assert chunks[0]["filename"] == "os_notes.txt"
    assert "chunk_hash" in chunks[0]

def test_rag_ingest_search_ask_pipeline(sample_text_file):
    doc_id = 901
    
    # Ingest
    ingest_res = RAGService.ingest_document(
        file_path=sample_text_file,
        document_id=doc_id,
        filename="Operating_Systems_Unit_1.txt",
        subject="Operating Systems",
        topic="Process Management"
    )
    assert ingest_res["document_id"] == doc_id
    assert ingest_res["total_chunks"] >= 1

    # Search
    search_res = RAGService.search_context(
        query="What is process scheduling?",
        top_k=2,
        subject="Operating Systems"
    )
    assert search_res["has_sufficient_context"] is True
    assert len(search_res["chunks"]) >= 1
    assert "scheduling" in search_res["chunks"][0]["chunk_text"].lower()

    # Ask Grounded Q&A
    ask_res = RAGService.answer_question(
        question="What is process scheduling?",
        subject="Operating Systems"
    )
    assert ask_res["has_sufficient_context"] is True
    assert len(ask_res["sources"]) >= 1
    assert ask_res["sources"][0]["filename"] == "Operating_Systems_Unit_1.txt"
    assert "scheduling" in ask_res["answer"].lower()

    # Clean up ChromaDB vectors
    del_res = client.delete(f"/api/v1/rag/document/{doc_id}")
    assert del_res.status_code == 200

def test_rag_no_context_behavior():
    # Ask question about totally unrelated out-of-domain topic with high threshold
    ask_res = RAGService.answer_question(
        question="What is quantum chromodynamics in particle physics?",
        subject="Quantum Physics",
        threshold=0.85
    )
    assert ask_res["has_sufficient_context"] is False
    assert "don't have enough information about Quantum Physics" in ask_res["answer"]
    assert len(ask_res["sources"]) == 0

def test_fastapi_rag_endpoints(sample_text_file):
    # Ingest via API
    ingest_payload = {
        "file_path": sample_text_file,
        "document_id": 902,
        "filename": "API_Test_Doc.txt",
        "subject": "Computer Networks",
        "topic": "TCP/IP"
    }
    response = client.post("/api/v1/rag/ingest", json=ingest_payload)
    assert response.status_code == 200
    assert response.json()["status"] == "success"

    # Search via API
    search_payload = {
        "query": "scheduling algorithms",
        "subject": "Computer Networks"
    }
    search_response = client.post("/api/v1/rag/search", json=search_payload)
    assert search_response.status_code == 200

    # Ask via API
    ask_payload = {
        "question": "What algorithms are mentioned in the course notes?",
        "subject": "Computer Networks"
    }
    ask_response = client.post("/api/v1/rag/ask", json=ask_payload)
    assert ask_response.status_code == 200
    data = ask_response.json()
    assert "answer" in data
    assert "sources" in data

    # Delete via API
    del_res = client.delete("/api/v1/rag/document/902")
    assert del_res.status_code == 200

def test_intent_classification_chatbot_responses():
    # Casual Greeting
    res_hello = RAGService.answer_question("hello")
    assert res_hello["is_conversational"] is True
    assert res_hello["intent"] == "greeting"
    assert "Hello! 👋" in res_hello["answer"]
    assert len(res_hello["sources"]) == 0

    # Identity
    res_id = RAGService.answer_question("what is your name")
    assert res_id["is_conversational"] is True
    assert res_id["intent"] == "assistant_identity"
    assert "AI Assistant" in res_id["answer"]
    assert len(res_id["sources"]) == 0

    # Thanks
    res_thanks = RAGService.answer_question("thank you")
    assert res_thanks["is_conversational"] is True
    assert res_thanks["intent"] == "thanks"
    assert "You're welcome!" in res_thanks["answer"]

    # Capability
    res_cap = RAGService.answer_question("what can you do")
    assert res_cap["is_conversational"] is True
    assert "AI Assistant" in res_cap["answer"]

def test_personalized_performance_queries():
    # Insufficient Data Test
    res_no_data = RAGService.answer_question(
        question="I am not getting more marks in English. Suggest me how can I improve.",
        student_id=1,
        history=[]
    )
    assert res_no_data["intent"] in ["recommendation", "performance"]

    # Populated Performance History Test
    history_data = [
        {"subject": "English", "topic": "Grammar", "is_correct": False},
        {"subject": "English", "topic": "Grammar", "is_correct": False},
        {"subject": "English", "topic": "Vocabulary", "is_correct": True}
    ]
    res_with_data = RAGService.answer_question(
        question="I am not getting more marks in English. Suggest me how can I improve.",
        student_id=1,
        history=history_data
    )
    assert res_with_data["intent"] in ["recommendation", "performance"]
    assert "Grammar" in res_with_data["answer"]
    assert "English" in res_with_data["answer"]
