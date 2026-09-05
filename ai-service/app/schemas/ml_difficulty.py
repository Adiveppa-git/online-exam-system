from typing import Optional, Literal
from pydantic import BaseModel, Field

class DifficultyPredictRequest(BaseModel):
    question_id: int
    total_attempts: int = Field(..., ge=0)
    correct_attempts: int = Field(..., ge=0)
    unique_students: int = Field(0, ge=0)
    topic_avg_accuracy: float = Field(50.0, ge=0.0, le=100.0)
    subject_avg_accuracy: float = Field(50.0, ge=0.0, le=100.0)
    min_attempts_threshold: int = Field(5, ge=1, le=100)

class DifficultyPredictResponse(BaseModel):
    question_id: int
    status: Literal["predicted", "insufficient_real_data", "synthetic_benchmark"]
    data_mode: Literal["synthetic_benchmark", "real_data_production"]
    predicted_difficulty: Literal["easy", "medium", "hard", "insufficient_data"]
    confidence: float = Field(..., ge=0.0, le=1.0)
    observed_correct_rate: float = Field(..., ge=0.0, le=1.0)
    model_version: str
    message: str
    disclaimer: str