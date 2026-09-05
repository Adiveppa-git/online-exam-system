from typing import Dict, List, Optional, Literal
from pydantic import BaseModel, Field, field_validator

class QuestionGenerationRequest(BaseModel):
    subject: str = Field(..., min_length=1, max_length=100, description="Subject area")
    topic: str = Field(..., min_length=1, max_length=100, description="Specific topic")
    difficulty: Literal["easy", "medium", "hard"] = Field("medium", description="Difficulty level")
    question_type: str = Field("mcq", description="Question type (default mcq)")
    number_of_questions: int = Field(5, ge=1, le=20, description="Number of questions to generate (1-20)")
    additional_context: Optional[str] = Field(None, max_length=1000, description="Optional background material/notes")

    @field_validator('subject', 'topic')
    def strip_whitespace(cls, v: str) -> str:
        s = v.strip()
        if not s:
            raise ValueError("Field cannot be blank")
        return s

class GeneratedQuestionItem(BaseModel):
    question: str = Field(..., min_length=5)
    options: Dict[str, str] = Field(...)
    correct_answer: Literal["A", "B", "C", "D"] = Field(...)
    explanation: str = Field(..., min_length=5)
    subject: str = Field(...)
    topic: str = Field(...)
    difficulty: Literal["easy", "medium", "hard"] = Field(...)

    @field_validator('options')
    def validate_options(cls, opts: Dict[str, str]) -> Dict[str, str]:
        expected_keys = {"A", "B", "C", "D"}
        if set(opts.keys()) != expected_keys:
            raise ValueError(f"Options must contain exactly keys A, B, C, D. Got: {list(opts.keys())}")
        
        # Check non-empty values
        cleaned = {}
        for k, v in opts.items():
            val = str(v).strip()
            if not val:
                raise ValueError(f"Option {k} cannot be empty")
            cleaned[k] = val
            
        # Check unique option values
        unique_vals = set(cleaned.values())
        if len(unique_vals) < 4:
            raise ValueError("Duplicate option choices detected in generated question")
            
        return cleaned

class QuestionGenerationResponse(BaseModel):
    request_id: str = Field(...)
    status: Literal["success", "error"] = "success"
    model_used: str = Field(...)
    questions: List[GeneratedQuestionItem] = Field(default_factory=list)
    error_message: Optional[str] = None