import hashlib
import re
from typing import List, Dict, Any
from app.config import settings

class TextChunker:
    @classmethod
    def chunk_document_pages(
        cls,
        document_id: int,
        filename: str,
        subject: str,
        topic: str,
        pages: List[Dict[str, Any]],
        chunk_size: int = None,
        chunk_overlap: int = None
    ) -> List[Dict[str, Any]]:
        """
        Paragraph/section-aware chunker with configurable size and overlap.
        Preserves metadata: document_id, filename, subject, topic, page_number, chunk_id, chunk_hash.
        """
        chunk_size = chunk_size or settings.RAG_CHUNK_SIZE
        chunk_overlap = chunk_overlap or settings.RAG_CHUNK_OVERLAP

        chunks: List[Dict[str, Any]] = []
        global_chunk_idx = 0

        for page_obj in pages:
            page_num = page_obj.get("page_number", 1)
            raw_text = page_obj.get("text", "").strip()
            if not raw_text:
                continue

            page_chunks_text = cls._split_text_into_chunks(raw_text, chunk_size, chunk_overlap)

            for chunk_text in page_chunks_text:
                chunk_text_clean = chunk_text.strip()
                if not chunk_text_clean:
                    continue

                chunk_hash = hashlib.sha256(
                    f"{document_id}_{page_num}_{global_chunk_idx}_{chunk_text_clean}".encode("utf-8")
                ).hexdigest()

                chunk_id = f"doc_{document_id}_p{page_num}_c{global_chunk_idx}"

                chunks.append({
                    "chunk_id": chunk_id,
                    "document_id": document_id,
                    "filename": filename,
                    "subject": subject,
                    "topic": topic,
                    "page_number": page_num,
                    "chunk_index": global_chunk_idx,
                    "chunk_text": chunk_text_clean,
                    "chunk_hash": chunk_hash
                })
                global_chunk_idx += 1

        return chunks

    @classmethod
    def _split_text_into_chunks(cls, text: str, chunk_size: int, chunk_overlap: int) -> List[str]:
        """
        Splits text into paragraphs, combining them until chunk_size is met,
        with overlap between consecutive chunks.
        """
        paragraphs = [p.strip() for p in re.split(r'\n\s*\n', text) if p.strip()]
        if not paragraphs:
            paragraphs = [text]

        sub_blocks: List[str] = []
        for p in paragraphs:
            if len(p) <= chunk_size:
                sub_blocks.append(p)
            else:
                # Split large paragraph by sentence or period
                sentences = re.split(r'(?<=[.!?])\s+', p)
                current_sentence_block = ""
                for s in sentences:
                    if len(current_sentence_block) + len(s) + 1 <= chunk_size:
                        current_sentence_block += (" " if current_sentence_block else "") + s
                    else:
                        if current_sentence_block:
                            sub_blocks.append(current_sentence_block)
                        current_sentence_block = s
                if current_sentence_block:
                    sub_blocks.append(current_sentence_block)

        chunks: List[str] = []
        current_chunk = ""

        for block in sub_blocks:
            if not current_chunk:
                current_chunk = block
            elif len(current_chunk) + len(block) + 1 <= chunk_size:
                current_chunk += "\n" + block
            else:
                chunks.append(current_chunk)
                # Overlap logic
                if chunk_overlap > 0 and len(current_chunk) > chunk_overlap:
                    overlap_text = current_chunk[-chunk_overlap:]
                    current_chunk = overlap_text + "\n" + block
                else:
                    current_chunk = block

        if current_chunk:
            chunks.append(current_chunk)

        return chunks
