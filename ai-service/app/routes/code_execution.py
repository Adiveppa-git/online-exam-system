import shutil
import sys
from fastapi import APIRouter, HTTPException, Depends
from app.schemas.code_execution import (
    CodeExecutionRequest,
    CodeExecutionResponse,
    CodeCheckRequest,
    CodeCheckResponse
)
from app.services.code_executor import CodeExecutor

router = APIRouter(prefix="/code", tags=["Code Execution"])

@router.get("/status")
def get_execution_status():
    """
    Returns the availability of code execution runtimes on this server.
    """
    return {
        "status": "online",
        "python": {
            "available": True,
            "version": sys.version.split()[0]
        },
        "java": {
            "available": bool(shutil.which("javac") and shutil.which("java")),
            "javac_path": shutil.which("javac") or "",
            "java_path": shutil.which("java") or ""
        },
        "c_cpp": {
            "gcc_available": bool(shutil.which("gcc")),
            "gpp_available": bool(shutil.which("g++"))
        },
        "supported_languages": list(CodeExecutor.SUPPORTED_LANGUAGES.keys())
    }

@router.post("/execute", response_model=CodeExecutionResponse)
def execute_code(req: CodeExecutionRequest):
    """
    Executes student code securely in an isolated sandbox environment.
    """
    result = CodeExecutor.execute(
        language=req.language,
        code=req.code,
        stdin=req.stdin or "",
        timeout=req.timeout_seconds or 5.0
    )
    return CodeExecutionResponse(**result)

@router.post("/check", response_model=CodeCheckResponse)
def check_code(req: CodeCheckRequest):
    """
    Executes student code against sample test cases and returns structured pass/fail results.
    """
    # Convert Pydantic TestCaseItems to dict
    cases = [{"input": tc.input, "output": tc.output, "explanation": tc.explanation} for tc in req.test_cases]
    result = CodeExecutor.check_test_cases(
        language=req.language,
        code=req.code,
        test_cases=cases,
        timeout=req.timeout_seconds or 5.0
    )
    return CodeCheckResponse(**result)
