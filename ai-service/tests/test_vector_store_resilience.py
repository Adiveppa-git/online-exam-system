import os
import pytest
from fastapi.testclient import TestClient
from app.main import app
from app.config import settings
from app.services.vector_store import (
    VectorStoreManager,
    PgVectorStoreManager,
    ChromaVectorStoreManager,
    VectorStoreError,
    VectorStoreUnavailableError
)

client = TestClient(app)
AUTH_HEADERS = {"X-Internal-API-Key": settings.INTERNAL_API_KEY}

def test_chroma_vector_store_health():
    vs = ChromaVectorStoreManager.get_instance()
    health = vs.check_health()
    assert health["healthy"] is True
    assert health["status"] == "ready"
    assert health["vector_store"] == "chroma"
    assert "total_indexed_chunks" in health

def test_pgvector_missing_url_resilience(monkeypatch):
    mgr = PgVectorStoreManager()
    monkeypatch.setattr(mgr, "db_url", "")
    
    health = mgr.check_health()
    assert health["healthy"] is False
    assert health["status"] == "degraded"
    assert health["vector_store"] == "pgvector"

    with pytest.raises(VectorStoreUnavailableError):
        mgr._get_connection()

def test_pgvector_invalid_url_resilience(monkeypatch):
    mgr = PgVectorStoreManager()
    monkeypatch.setattr(mgr, "db_url", "postgresql://invalid_user:invalid_pass@127.0.0.1:54321/invalid_db")

    health = mgr.check_health()
    assert health["healthy"] is False
    assert health["status"] == "degraded"

    with pytest.raises(VectorStoreUnavailableError):
        mgr._get_connection()

def test_readiness_degraded_when_pgvector_unconfigured(monkeypatch):
    monkeypatch.setattr(settings, "VECTOR_STORE_TYPE", "pgvector")
    monkeypatch.setattr(settings, "SUPABASE_DB_URL", "")
    
    # Force singleton to use PgVectorStoreManager with empty db_url
    pg_mgr = PgVectorStoreManager()
    pg_mgr.db_url = ""
    monkeypatch.setattr(VectorStoreManager, "get_instance", classmethod(lambda cls: pg_mgr))

    res = client.get("/readiness")
    assert res.status_code == 503
    data = res.json()
    assert data["status"] == "degraded"
    assert data["message"] == "RAG vector store configuration is unavailable."

def test_rag_endpoints_degraded_responses(monkeypatch):
    pg_mgr = PgVectorStoreManager()
    pg_mgr.db_url = "postgresql://invalid_user:invalid_pass@127.0.0.1:54321/invalid_db"
    monkeypatch.setattr(VectorStoreManager, "get_instance", classmethod(lambda cls: pg_mgr))

    # Search
    s_res = client.post("/api/v1/rag/search", json={"query": "test query", "subject": "OS"}, headers=AUTH_HEADERS)
    assert s_res.status_code == 503
    assert s_res.json()["detail"] == "RAG vector store is temporarily unavailable."

    # Ask
    a_res = client.post("/api/v1/rag/ask", json={"question": "What is process scheduling?", "subject": "OS"}, headers=AUTH_HEADERS)
    assert a_res.status_code == 503
    assert a_res.json()["detail"] == "RAG vector store is temporarily unavailable."

    # Ingest
    i_res = client.post("/api/v1/rag/ingest", json={
        "file_content_base64": "SGVsbG8gV29ybGQ=",
        "document_id": 888,
        "filename": "test.txt",
        "subject": "OS",
        "topic": "Process"
    }, headers=AUTH_HEADERS)
    assert i_res.status_code == 503
    assert i_res.json()["detail"] == "RAG vector store is temporarily unavailable."

def test_pgvector_sslmode_enforcement(monkeypatch):
    captured_urls = []
    
    import sys
    import types
    
    mock_psycopg2 = types.ModuleType("psycopg2")
    def mock_connect(url):
        captured_urls.append(url)
        raise Exception("Mock connection error")
    mock_psycopg2.connect = mock_connect
    monkeypatch.setitem(sys.modules, "psycopg2", mock_psycopg2)

    mgr = PgVectorStoreManager()

    # Case 1: Remote PostgreSQL URL without sslmode should append sslmode=require
    mgr.db_url = "postgresql://user:pass@aws-0-ap-south-1.pooler.supabase.com:5432/postgres"
    with pytest.raises(VectorStoreUnavailableError):
        mgr._get_connection()
    assert captured_urls[-1] == "postgresql://user:pass@aws-0-ap-south-1.pooler.supabase.com:5432/postgres?sslmode=require"

    # Case 2: URL with existing query parameters should append &sslmode=require
    mgr.db_url = "postgresql://user:pass@aws-0-ap-south-1.pooler.supabase.com:5432/postgres?connect_timeout=10"
    with pytest.raises(VectorStoreUnavailableError):
        mgr._get_connection()
    assert captured_urls[-1] == "postgresql://user:pass@aws-0-ap-south-1.pooler.supabase.com:5432/postgres?connect_timeout=10&sslmode=require"

    # Case 3: URL already specifying sslmode should not duplicate sslmode
    mgr.db_url = "postgresql://user:pass@aws-0-ap-south-1.pooler.supabase.com:5432/postgres?sslmode=require"
    with pytest.raises(VectorStoreUnavailableError):
        mgr._get_connection()
    assert captured_urls[-1] == "postgresql://user:pass@aws-0-ap-south-1.pooler.supabase.com:5432/postgres?sslmode=require"

    # Case 4: Localhost URL should NOT append sslmode=require
    mgr.db_url = "postgresql://user:pass@127.0.0.1:5432/postgres"
    with pytest.raises(VectorStoreUnavailableError):
        mgr._get_connection()
    assert captured_urls[-1] == "postgresql://user:pass@127.0.0.1:5432/postgres"

