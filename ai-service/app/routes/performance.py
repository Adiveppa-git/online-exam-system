from fastapi import APIRouter, HTTPException, status
from app.schemas.performance import StudentPerformanceInput, StudentPerformanceOutput
from app.services.performance_service import PerformanceService

router = APIRouter()

@router.post("/performance/analyze", response_model=StudentPerformanceOutput)
def analyze_student_performance(payload: StudentPerformanceInput):
    try:
        res = PerformanceService.analyze_performance(payload)
        return res
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Performance analysis failed: {str(e)}"
        )