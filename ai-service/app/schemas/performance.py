from typing import List, Dict, Optional, Literal
from pydantic import BaseModel, Field, field_validator

class ExamAttemptItem(BaseModel):
    exam_id: int
    title: str
    score: float
    total_marks: float
    percentage: float
    taken_at: Optional[str] = None

class TopicStatItem(BaseModel):
    subject: str
    topic: str
    attempted: int = Field(..., ge=0)
    correct: int = Field(..., ge=0)
    accuracy: float = Field(..., ge=0.0, le=100.0)

class StudentPerformanceInput(BaseModel):
    student_id: int
    strong_threshold: float = Field(80.0, ge=0.0, le=100.0)
    weak_threshold: float = Field(50.0, ge=0.0, le=100.0)
    exams: List[ExamAttemptItem] = Field(default_factory=list)
    topics: List[TopicStatItem] = Field(default_factory=list)

class TopicClassificationItem(BaseModel):
    subject: str
    topic: str
    attempted: int
    correct: int
    accuracy: float
    classification: Literal["Strong", "Developing", "Weak"]

class TrendAnalysis(BaseModel):
    has_trend: bool
    previous_percentage: Optional[float] = None
    current_percentage: Optional[float] = None
    trend_percentage_points: Optional[float] = None
    direction: Literal["improving", "declining", "stable", "insufficient_data"]
    message: str

class StudentPerformanceOutput(BaseModel):
    student_id: int
    total_exams_attempted: int
    total_questions_attempted: int
    total_correct: int
    total_incorrect: int
    overall_accuracy: float
    average_exam_percentage: float
    strong_topics: List[TopicClassificationItem]
    weak_topics: List[TopicClassificationItem]
    all_topic_performance: List[TopicClassificationItem]
    trend: TrendAnalysis
    thresholds: Dict[str, float]