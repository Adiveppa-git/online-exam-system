from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field
from typing import List, Optional, Dict, Any
from app.services.recommendation_service import RecommendationService
from app.services.rag_service import RAGService
from app.services.llm_service import llm_service
from app.schemas.question import QuestionGenerationRequest

router = APIRouter()

class ProfileRequest(BaseModel):
    student_id: int = Field(..., description="Student ID")
    history: List[Dict[str, Any]] = Field(default=[], description="List of student attempt records")

class PlanRequest(BaseModel):
    student_id: int = Field(..., description="Student ID")
    history: List[Dict[str, Any]] = Field(default=[], description="List of student attempt records")

class PracticeGenRequest(BaseModel):
    subject: str = Field(..., description="Subject name")
    topic: str = Field(..., description="Topic name")
    difficulty: str = Field(default="medium", description="Recommended difficulty")
    number_of_questions: int = Field(default=5, ge=1, le=10)

@router.post("/recommendations/profile")
def get_student_learning_profile(request: ProfileRequest):
    try:
        profile = RecommendationService.build_student_profile(
            student_id=request.student_id,
            history=request.history
        )
        return profile
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to generate learning profile: {str(e)}")

@router.post("/recommendations/plan")
def get_personalized_study_plan(request: PlanRequest):
    try:
        profile = RecommendationService.build_student_profile(
            student_id=request.student_id,
            history=request.history
        )
        plan = RecommendationService.generate_personalized_plan(
            profile=profile,
            rag_service_fn=RAGService.search_context,
            llm_service_obj=llm_service
        )
        return plan
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to generate study plan: {str(e)}")

@router.post("/recommendations/practice-questions")
def generate_targeted_practice(request: PracticeGenRequest):
    try:
        gen_req = QuestionGenerationRequest(
            subject=request.subject,
            topic=request.topic,
            difficulty=request.difficulty,
            number_of_questions=request.number_of_questions
        )
        res = llm_service.generate_questions(gen_req)
        return res
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to generate practice questions: {str(e)}")
