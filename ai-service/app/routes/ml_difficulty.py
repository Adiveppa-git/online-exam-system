from fastapi import APIRouter, HTTPException, status
from app.schemas.ml_difficulty import DifficultyPredictRequest, DifficultyPredictResponse
from app.ml.inference.predictor import DifficultyPredictor

router = APIRouter()

@router.post("/ml/question-difficulty", response_model=DifficultyPredictResponse)
def predict_question_difficulty(req: DifficultyPredictRequest):
    try:
        res = DifficultyPredictor.predict(req)
        return res
    except FileNotFoundError as fnf:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail=str(fnf)
        )
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Difficulty prediction failed: {str(e)}"
        )