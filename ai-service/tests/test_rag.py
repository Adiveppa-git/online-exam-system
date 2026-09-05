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
    assert "couldn't find enough information" in ask_res["answer"]
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
