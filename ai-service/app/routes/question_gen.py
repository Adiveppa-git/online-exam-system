from fastapi import APIRouter, HTTPException, status
from app.schemas.question import QuestionGenerationRequest, QuestionGenerationResponse
from app.services.llm_service import LLMService

router = APIRouter()

@router.post("/questions/generate", response_model=QuestionGenerationResponse)
def generate_questions(req: QuestionGenerationRequest):
    try:
        res = LLMService.generate_questions(req)
        return res
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"AI Question Generation failed: {str(e)}"
        )