import os
import chromadb
from chromadb.utils import embedding_functions
from typing import List, Dict, Any, Optional
from app.config import settings

class VectorStoreManager:
    _instance = None

    def __init__(self):
        os.makedirs(settings.CHROMA_PERSIST_DIR, exist_ok=True)
        self.client = chromadb.PersistentClient(path=settings.CHROMA_PERSIST_DIR)

        # Initialize embedding function using sentence-transformers
        self.embedding_fn = embedding_functions.SentenceTransformerEmbeddingFunction(
            model_name=settings.EMBEDDING_MODEL_NAME
        )

        self.collection = self.client.get_or_create_collection(
            name="course_materials",
            embedding_function=self.embedding_fn,
            metadata={
                "embedding_model": settings.EMBEDDING_MODEL_NAME,
                "dimension": settings.EMBEDDING_DIMENSION,
                "hnsw:space": "cosine"
            }
        )

    @classmethod
    def get_instance(cls):
        if cls._instance is None:
            cls._instance = VectorStoreManager()
        return cls._instance

    def add_chunks(self, chunks: List[Dict[str, Any]]) -> int:
        """
        Adds text chunks into ChromaDB collection with full metadata.
        """
        if not chunks:
            return 0

        ids = [c["chunk_id"] for c in chunks]
        documents = [c["chunk_text"] for c in chunks]
        metadatas = [
            {
                "document_id": c["document_id"],
                "filename": c["filename"],
                "subject": c["subject"],
                "topic": c["topic"],
                "page_number": c["page_number"],
                "chunk_index": c["chunk_index"],
                "chunk_hash": c["chunk_hash"]
            }
            for c in chunks
        ]

        self.collection.upsert(
            ids=ids,
            documents=documents,
            metadatas=metadatas
        )
        return len(chunks)

    def delete_document_chunks(self, document_id: int) -> int:
        """
        Deletes all chunks belonging to a document to maintain index consistency.
        """
        try:
            results = self.collection.get(where={"document_id": document_id})
            existing_ids = results.get("ids", [])
            if existing_ids:
                self.collection.delete(ids=existing_ids)
                return len(existing_ids)
        except Exception:
            pass
        return 0

    def search_similarity(
        self,
        query: str,
        top_k: int = None,
        subject: Optional[str] = None,
        topic: Optional[str] = None
    ) -> List[Dict[str, Any]]:
        """
        Searches for top-k matching chunks with metadata filtering and relevance scoring.
        """
        top_k = top_k or settings.RAG_TOP_K
        
        where_filter = {}
        if subject and subject.strip() and subject.lower() != "all":
            where_filter["subject"] = subject.strip()
        if topic and topic.strip() and topic.lower() != "all":
            where_filter["topic"] = topic.strip()

        # Handle ChromaDB filter format
        if len(where_filter) == 0:
            filter_arg = None
        elif len(where_filter) == 1:
            filter_arg = where_filter
        else:
            filter_arg = {"$and": [{k: v} for k, v in where_filter.items()]}

        try:
            results = self.collection.query(
                query_texts=[query],
                n_results=top_k,
                where=filter_arg
            )
        except Exception as e:
            # Fallback query without where filter if filter fails or empty
            results = self.collection.query(
                query_texts=[query],
                n_results=top_k
            )

        output_chunks: List[Dict[str, Any]] = []

        if results and results.get("documents") and results["documents"][0]:
            docs = results["documents"][0]
            metas = results["metadatas"][0]
            ids = results["ids"][0]
            distances = results["distances"][0] if "distances" in results and results["distances"] else [0.0]*len(docs)

            for doc, meta, cid, dist in zip(docs, metas, ids, distances):
                # Cosine distance to similarity: similarity = 1.0 - (distance / 2.0) or 1.0 - dist
                similarity = max(0.0, 1.0 - float(dist))

                output_chunks.append({
                    "chunk_id": cid,
                    "document_id": meta.get("document_id"),
                    "filename": meta.get("filename"),
                    "subject": meta.get("subject"),
                    "topic": meta.get("topic"),
                    "page_number": meta.get("page_number", 1),
                    "chunk_index": meta.get("chunk_index", 0),
                    "chunk_text": doc,
                    "similarity_score": round(similarity, 4),
                    "distance": round(float(dist), 4)
                })

        return output_chunks
