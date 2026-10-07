import secrets
import logging
from typing import Optional
from fastapi import Header, HTTPException, status
from app.config import settings

logger = logging.getLogger("ai_auth")

def verify_internal_api_key(
    x_internal_api_key: Optional[str] = Header(None, alias="X-Internal-API-Key"),
    x_api_key: Optional[str] = Header(None, alias="X-API-Key")
) -> str:
    """
    FastAPI dependency to verify internal API key authentication.
    Supports X-Internal-API-Key (primary) and X-API-Key (compatibility).
    """
    api_key = x_internal_api_key or x_api_key
    
    if not api_key:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Internal API authentication required."
        )
    
    expected_key = settings.INTERNAL_API_KEY
    if not expected_key or not secrets.compare_digest(api_key, expected_key):
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Invalid internal API credentials."
        )
    
    return api_key
