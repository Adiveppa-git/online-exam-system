import time
import logging
from fastapi import FastAPI, Request, HTTPException
from fastapi.responses import JSONResponse
from fastapi.middleware.cors import CORSMiddleware
from app.config import settings
from app.routes import health, question_gen, performance, ml_difficulty, rag, recommendations
from app.services.vector_store import VectorStoreManager

# Structured Logging Setup
logging.basicConfig(
    level=logging.INFO if not settings.DEBUG else logging.DEBUG,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s"
)
logger = logging.getLogger("ai_service")

app = FastAPI(
    title=settings.SERVICE_NAME,
    version=settings.VERSION,
    openapi_url=f"{settings.API_V1_STR}/openapi.json"
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Structured Logging Middleware
@app.middleware("http")
async def log_requests(request: Request, call_next):
    start_time = time.time()
    response = await call_next(request)
    duration_ms = round((time.time() - start_time) * 1000, 2)
    logger.info(f"{request.method} {request.url.path} -> HTTP {response.status_code} ({duration_ms}ms)")
    return response

# Global Exception Handler
@app.exception_handler(Exception)
async def global_exception_handler(request: Request, exc: Exception):
    logger.error(f"Unhandled exception on {request.url.path}: {exc}", exc_info=True)
    return JSONResponse(
        status_code=500,
        content={
            "status": "error",
            "message": "Internal AI Service Exception",
            "detail": str(exc) if settings.DEBUG else "An unexpected error occurred in AI service."
        }
    )

# Include Routers
app.include_router(health.router, tags=["Health Check"])
app.include_router(health.router, prefix=settings.API_V1_STR, tags=["Health Check"])

app.include_router(question_gen.router, tags=["Question Generation"])
app.include_router(question_gen.router, prefix=settings.API_V1_STR, tags=["Question Generation"])

app.include_router(performance.router, tags=["Performance Analytics"])
app.include_router(performance.router, prefix=settings.API_V1_STR, tags=["Performance Analytics"])

app.include_router(ml_difficulty.router, tags=["ML Question Difficulty"])
app.include_router(ml_difficulty.router, prefix=settings.API_V1_STR, tags=["ML Question Difficulty"])

app.include_router(rag.router, tags=["RAG Study Assistant"])
app.include_router(rag.router, prefix=settings.API_V1_STR, tags=["RAG Study Assistant"])

app.include_router(recommendations.router, tags=["Personalized Adaptive Learning"])
app.include_router(recommendations.router, prefix=settings.API_V1_STR, tags=["Personalized Adaptive Learning"])

@app.get("/")
def root():
    return {
        "message": "Welcome to the AI-Powered Examination & Learning Platform Service",
        "health_check": "/health",
        "readiness_check": "/readiness",
        "docs": "/docs"
    }

@app.get("/readiness")
def readiness_check():
    """
    Readiness endpoint verifying vector store and internal service status.
    """
    try:
        vs = VectorStoreManager.get_instance()
        count = vs.collection.count()
        return {
            "status": "ready",
            "service": settings.SERVICE_NAME,
            "vector_store": "connected",
            "total_indexed_chunks": count
        }
    except Exception as e:
        return JSONResponse(
            status_code=503,
            content={
                "status": "not_ready",
                "error": str(e)
            }
        )
