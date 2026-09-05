import os
from pydantic_settings import BaseSettings, SettingsConfigDict

class Settings(BaseSettings):
    SERVICE_NAME: str = "ai-service"
    VERSION: str = "1.0.0"
    ENVIRONMENT: str = "development"
    DEBUG: bool = True
    HOST: str = "127.0.0.1"
    PORT: int = 8001
    API_V1_STR: str = "/api/v1"
    INTERNAL_API_KEY: str = "dev_secret_key_change_in_production"

    # LLM Settings
    LLM_PROVIDER: str = "heuristic" # "openai", "custom", or "heuristic"
    LLM_API_KEY: str = ""
    LLM_MODEL: str = "gpt-3.5-turbo"
    LLM_BASE_URL: str = "https://api.openai.com/v1"

    # RAG Settings
    CHROMA_PERSIST_DIR: str = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data", "chroma_db")
    EMBEDDING_MODEL_NAME: str = "sentence-transformers/all-MiniLM-L6-v2"
    EMBEDDING_DIMENSION: int = 384
    RAG_CHUNK_SIZE: int = 500
    RAG_CHUNK_OVERLAP: int = 50
    RAG_TOP_K: int = 3
    RAG_RELEVANCE_THRESHOLD: float = 0.35

    # Phase H Recommendation Engine Settings
    MIN_TOPIC_ATTEMPTS: int = 5
    STRONG_ACCURACY_THRESHOLD: float = 0.80
    DEVELOPING_ACCURACY_THRESHOLD: float = 0.50
    RECENT_ACCURACY_WEIGHT: float = 0.6
    HISTORICAL_ACCURACY_WEIGHT: float = 0.4
    TREND_MARGIN: float = 0.10
    PRIORITY_WEAKNESS_WEIGHT: float = 0.5
    PRIORITY_TREND_WEIGHT: float = 0.3
    PRIORITY_RECENCY_WEIGHT: float = 0.2
    DEFAULT_PRACTICE_COUNT: int = 5

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore"
    )

settings = Settings()
