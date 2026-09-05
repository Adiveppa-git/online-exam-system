from fastapi import APIRouter, HTTPException, Depends
from pydantic import BaseModel, Field
from typing import List, Optional, Dict, Any
from app.services.rag_service import RAGService
from app.services.document_loader import DocumentLoaderError
from app.services.vector_store import VectorStoreManager

router = APIRouter()

class IngestRequest(BaseModel):
    file_path: str = Field(..., description="Absolute path to the uploaded document on server")
    document_id: int = Field(..., description="MySQL document ID")
    filename: str = Field(..., description="Original filename")
    subject: str = Field(default="General")
    topic: str = Field(default="General")

class IngestResponse(BaseModel):
    status: str
    document_id: int
    filename: str
    total_pages: int
    total_chunks: int
    message: str

class SearchRequest(BaseModel):
    query: str = Field(..., min_length=2, description="Student search query")
    top_k: Optional[int] = Field(default=None, ge=1, le=10)
    subject: Optional[str] = Field(default=None)
    topic: Optional[str] = Field(default=None)
    threshold: Optional[float] = Field(default=None, ge=0.0, le=1.0)

class AskRequest(BaseModel):
    question: str = Field(..., min_length=2, description="Student question")
    subject: Optional[str] = Field(default=None)
    topic: Optional[str] = Field(default=None)
    top_k: Optional[int] = Field(default=None, ge=1, le=10)
    threshold: Optional[float] = Field(default=None, ge=0.0, le=1.0)

class SourceCitation(BaseModel):
    filename: str
    page_number: int

class AskResponse(BaseModel):
    question: str
    answer: str
    has_sufficient_context: bool
    sources: List[SourceCitation]
    retrieved_chunks: List[Dict[str, Any]]

@router.post("/rag/ingest", response_model=IngestResponse)
def ingest_document(request: IngestRequest):
    try:
        res = RAGService.ingest_document(
            file_path=request.file_path,
            document_id=request.document_id,
            filename=request.filename,
            subject=request.subject,
            topic=request.topic
        )
        return IngestResponse(
            status="success",
            document_id=res["document_id"],
            filename=res["filename"],
            total_pages=res["total_pages"],
            total_chunks=res["total_chunks"],
            message=f"Successfully ingested '{res['filename']}' ({res['total_pages']} pages, {res['total_chunks']} chunks)."
        )
    except DocumentLoaderError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Document ingestion failed: {str(e)}")

@router.post("/rag/search")
def search_rag(request: SearchRequest):
    try:
        res = RAGService.search_context(
            query=request.query,
            top_k=request.top_k,
            subject=request.subject,
            topic=request.topic,
            threshold=request.threshold
        )
        return res
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Vector search failed: {str(e)}")

@router.post("/rag/ask", response_model=AskResponse)
def ask_rag(request: AskRequest):
    try:
        res = RAGService.answer_question(
            question=request.question,
            subject=request.subject,
            topic=request.topic,
            top_k=request.top_k,
            threshold=request.threshold
        )
        return AskResponse(**res)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"RAG query processing failed: {str(e)}")

@router.delete("/rag/document/{doc_id}")
def delete_rag_document(doc_id: int):
    try:
        vector_store = VectorStoreManager.get_instance()
        deleted_count = vector_store.delete_document_chunks(doc_id)
        return {
            "status": "success",
            "document_id": doc_id,
            "deleted_chunks": deleted_count,
            "message": f"Deleted {deleted_count} vectors for document ID {doc_id}."
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to delete vectors for document {doc_id}: {str(e)}")
