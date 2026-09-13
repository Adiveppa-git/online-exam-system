import os
import pypdf
from typing import List, Dict, Any

class DocumentLoaderError(Exception):
    pass

class DocumentLoader:
    MAX_FILE_SIZE = 15 * 1024 * 1024  # 15 MB limit

    @classmethod
    def load_document(cls, file_path: str, original_filename: str) -> Dict[str, Any]:
        """
        Validates file size, extension, and content integrity.
        Extracts text page-by-page.
        Returns metadata and page list.
        """
        temp_downloaded_file = None
        if file_path.startswith("http://") or file_path.startswith("https://"):
            import tempfile
            import httpx
            try:
                from app.config import settings
                headers = {}
                if getattr(settings, "SUPABASE_KEY", None):
                    headers["Authorization"] = f"Bearer {settings.SUPABASE_KEY}"
                    headers["apiKey"] = settings.SUPABASE_KEY

                resp = httpx.get(file_path, headers=headers, follow_redirects=True, timeout=20.0)
                if resp.status_code != 200:
                    raise DocumentLoaderError(f"Failed to download remote file from Supabase Storage (HTTP {resp.status_code}).")

                temp_fd, temp_downloaded_file = tempfile.mkstemp(suffix=os.path.splitext(original_filename)[1])
                with os.fdopen(temp_fd, 'wb') as tmp:
                    tmp.write(resp.content)
                file_path = temp_downloaded_file
            except Exception as e:
                if isinstance(e, DocumentLoaderError):
                    raise e
                raise DocumentLoaderError(f"Failed to fetch remote document from storage: {str(e)}")

        if not os.path.exists(file_path):
            raise DocumentLoaderError(f"File not found: {file_path}")

        file_size = os.path.getsize(file_path)
        if file_size == 0:
            if temp_downloaded_file and os.path.exists(temp_downloaded_file):
                os.unlink(temp_downloaded_file)
            raise DocumentLoaderError("File is empty (0 bytes).")
        if file_size > cls.MAX_FILE_SIZE:
            if temp_downloaded_file and os.path.exists(temp_downloaded_file):
                os.unlink(temp_downloaded_file)
            raise DocumentLoaderError(f"File exceeds maximum size limit of {cls.MAX_FILE_SIZE // (1024*1024)}MB.")

        ext = os.path.splitext(original_filename)[1].lower()
        if ext not in [".pdf", ".txt", ".md"]:
            raise DocumentLoaderError(f"Unsupported file format '{ext}'. Allowed formats: .pdf, .txt, .md")

        pages: List[Dict[str, Any]] = []

        if ext == ".pdf":
            try:
                reader = pypdf.PdfReader(file_path)
                total_pages = len(reader.pages)
                if total_pages == 0:
                    raise DocumentLoaderError("PDF document contains no readable pages.")
                
                for idx, page in enumerate(reader.pages):
                    text = page.extract_text() or ""
                    cleaned_text = text.strip()
                    if cleaned_text:
                        pages.append({
                            "page_number": idx + 1,
                            "text": cleaned_text
                        })
            except Exception as e:
                if isinstance(e, DocumentLoaderError):
                    raise e
                raise DocumentLoaderError(f"Failed to parse PDF document: {str(e)}")

        elif ext in [".txt", ".md"]:
            try:
                with open(file_path, "r", encoding="utf-8", errors="replace") as f:
                    content = f.read().strip()
                if not content:
                    raise DocumentLoaderError("Text/Markdown document contains no text.")

                # Split into pseudo-pages by double newlines or headers if very large
                pages.append({
                    "page_number": 1,
                    "text": content
                })
                total_pages = 1
            except Exception as e:
                raise DocumentLoaderError(f"Failed to read text file: {str(e)}")

        if not pages:
            if temp_downloaded_file and os.path.exists(temp_downloaded_file):
                os.unlink(temp_downloaded_file)
            raise DocumentLoaderError("No extractable text content found in document.")

        result = {
            "total_pages": len(pages),
            "file_size": file_size,
            "pages": pages
        }
        if temp_downloaded_file and os.path.exists(temp_downloaded_file):
            try:
                os.unlink(temp_downloaded_file)
            except Exception:
                pass
        return result
