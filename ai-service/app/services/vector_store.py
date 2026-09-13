import os
import chromadb
from chromadb.utils import embedding_functions
from typing import List, Dict, Any, Optional
from app.config import settings

class EmbeddingModelManager:
    _instance = None

    @classmethod
    def get_instance(cls):
        if cls._instance is None:
            cls._instance = EmbeddingModelManager()
        return cls._instance

    def __init__(self):
        self._model = None
        self._backend = "sentence-transformers"

        # Try FastEmbed ONNX runtime first (low memory ~30MB footprint)
        try:
            from fastembed import TextEmbedding
            self._model = TextEmbedding(model_name="BAAI/bge-small-en-v1.5")
            self._backend = "fastembed"
        except Exception:
            try:
                from sentence_transformers import SentenceTransformer
                self._model = SentenceTransformer(settings.EMBEDDING_MODEL_NAME)
                self._backend = "sentence-transformers"
            except Exception:
                self._model = None

    def embed_texts(self, texts: List[str]) -> List[List[float]]:
        if not texts:
            return []
        if self._backend == "fastembed" and self._model is not None:
            embeddings = list(self._model.embed(texts))
            return [e.tolist() for e in embeddings]
        elif self._model is not None:
            embeddings = self._model.encode(texts, convert_to_numpy=True)
            return embeddings.tolist()
        else:
            return [[0.0] * settings.EMBEDDING_DIMENSION for _ in texts]

    def embed_query(self, query: str) -> List[float]:
        res = self.embed_texts([query])
        return res[0] if res else [0.0] * settings.EMBEDDING_DIMENSION

    def verify_embedding_model(self) -> Dict[str, Any]:
        test_vector = self.embed_query("Startup test query")
        dim = len(test_vector)
        return {
            "status": "ok",
            "backend": self._backend,
            "dimension": dim,
            "dimension_match": dim == settings.EMBEDDING_DIMENSION
        }

class ChromaVectorStoreManager:
    _instance = None

    def __init__(self):
        os.makedirs(settings.CHROMA_PERSIST_DIR, exist_ok=True)
        self.client = chromadb.PersistentClient(path=settings.CHROMA_PERSIST_DIR)

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
            cls._instance = ChromaVectorStoreManager()
        return cls._instance

    def add_chunks(self, chunks: List[Dict[str, Any]]) -> int:
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
        top_k = top_k or settings.RAG_TOP_K

        where_filter = {}
        if subject and subject.strip() and subject.lower() != "all":
            where_filter["subject"] = subject.strip()
        if topic and topic.strip() and topic.lower() != "all":
            where_filter["topic"] = topic.strip()

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
        except Exception:
            results = None

        output_chunks: List[Dict[str, Any]] = []

        if results and results.get("documents") and results["documents"][0]:
            docs = results["documents"][0]
            metas = results["metadatas"][0]
            ids = results["ids"][0]
            distances = results["distances"][0] if "distances" in results and results["distances"] else [0.0]*len(docs)

            for doc, meta, cid, dist in zip(docs, metas, ids, distances):
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

class PgVectorStoreManager:
    _instance = None

    def __init__(self):
        self.embedding_mgr = EmbeddingModelManager.get_instance()
        self.db_url = settings.SUPABASE_DB_URL

    @classmethod
    def get_instance(cls):
        if cls._instance is None:
            cls._instance = PgVectorStoreManager()
        return cls._instance

    def _get_connection(self):
        import psycopg2
        return psycopg2.connect(self.db_url)

    def add_chunks(self, chunks: List[Dict[str, Any]]) -> int:
        if not chunks:
            return 0
        texts = [c["chunk_text"] for c in chunks]
        embeddings = self.embedding_mgr.embed_texts(texts)

        conn = self._get_connection()
        try:
            with conn.cursor() as cur:
                for c, emb in zip(chunks, embeddings):
                    emb_str = "[" + ",".join(map(str, emb)) + "]"
                    cur.execute("""
                        INSERT INTO ai_document_chunks
                        (document_id, chunk_index, page_number, chunk_text, chunk_hash, embedding)
                        VALUES (%s, %s, %s, %s, %s, %s::vector)
                    """, (c["document_id"], c["chunk_index"], c["page_number"], c["chunk_text"], c["chunk_hash"], emb_str))
            conn.commit()
            return len(chunks)
        finally:
            conn.close()

    def delete_document_chunks(self, document_id: int) -> int:
        conn = self._get_connection()
        try:
            with conn.cursor() as cur:
                cur.execute("DELETE FROM ai_document_chunks WHERE document_id = %s", (document_id,))
                deleted_count = cur.rowcount
            conn.commit()
            return deleted_count
        finally:
            conn.close()

    def search_similarity(
        self,
        query: str,
        top_k: int = None,
        subject: Optional[str] = None,
        topic: Optional[str] = None
    ) -> List[Dict[str, Any]]:
        top_k = top_k or settings.RAG_TOP_K
        query_vector = self.embedding_mgr.embed_query(query)
        vec_str = "[" + ",".join(map(str, query_vector)) + "]"

        where_conditions = []
        params = [vec_str]

        if subject and subject.strip() and subject.lower() != "all":
            where_conditions.append("d.subject = %s")
            params.append(subject.strip())

        if topic and topic.strip() and topic.lower() != "all":
            where_conditions.append("d.topic = %s")
            params.append(topic.strip())

        where_clause = ""
        if where_conditions:
            where_clause = "WHERE " + " AND ".join(where_conditions)

        params.extend([vec_str, top_k])

        sql = f"""
            SELECT
                c.id, c.document_id, d.filename, d.subject, d.topic,
                c.page_number, c.chunk_index, c.chunk_text,
                (1 - (c.embedding <=> %s::vector)) AS similarity_score
            FROM ai_document_chunks c
            JOIN ai_documents d ON c.document_id = d.id
            {where_clause}
            ORDER BY c.embedding <=> %s::vector ASC
            LIMIT %s
        """

        conn = self._get_connection()
        output_chunks = []
        try:
            with conn.cursor() as cur:
                cur.execute(sql, params)
                rows = cur.fetchall()
                for row in rows:
                    cid, doc_id, filename, subj, top, page_num, chunk_idx, text, sim = row
                    sim_val = max(0.0, float(sim))
                    output_chunks.append({
                        "chunk_id": str(cid),
                        "document_id": doc_id,
                        "filename": filename,
                        "subject": subj,
                        "topic": top,
                        "page_number": page_num,
                        "chunk_index": chunk_idx,
                        "chunk_text": text,
                        "similarity_score": round(sim_val, 4),
                        "distance": round(1.0 - sim_val, 4)
                    })
            return output_chunks
        finally:
            conn.close()

class VectorStoreManager:
    @classmethod
    def get_instance(cls):
        vtype = str(getattr(settings, "VECTOR_STORE_TYPE", "chroma")).lower()
        if vtype in ["pgvector", "postgres", "supabase"]:
            return PgVectorStoreManager.get_instance()
        return ChromaVectorStoreManager.get_instance()
