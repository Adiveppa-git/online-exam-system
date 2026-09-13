from fastapi import APIRouter, HTTPException, status
from app.schemas.question import (
    QuestionGenerationRequest,
    QuestionGenerationResponse,
    FocusedNotesRequest,
    FocusedNotesResponse
)
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

@router.post("/questions/focused-notes", response_model=FocusedNotesResponse)
def generate_focused_study_notes(req: FocusedNotesRequest):
    try:
        res = LLMService.generate_focused_study_notes(req)
        return FocusedNotesResponse(**res)
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Focused Study Notes generation failed: {str(e)}"
        )