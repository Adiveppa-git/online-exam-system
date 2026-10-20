from typing import Dict, List, Optional, Literal, Any
from pydantic import BaseModel, Field, field_validator

class QuestionGenerationRequest(BaseModel):
    subject: str = Field(..., min_length=1, max_length=100, description="Subject area")
    topic: Optional[str] = Field("", max_length=100, description="Specific topic (optional)")
    difficulty: Literal["easy", "medium", "hard"] = Field("medium", description="Difficulty level")
    question_type: str = Field("mcq", description="Question type: mcq, coding, short_answer, descriptive, mixed")
    number_of_questions: int = Field(5, ge=1, le=100, description="Number of questions to generate (1-100)")
    additional_context: Optional[str] = Field(None, max_length=1000, description="Optional background material/notes")

    @field_validator('subject')
    def strip_whitespace_subject(cls, v: str) -> str:
        s = (v or "").strip()
        if not s:
            raise ValueError("Field cannot be blank")
        return s

    @field_validator('topic')
    def strip_whitespace_topic(cls, v: Optional[str]) -> str:
        return (v or "").strip()

    @field_validator('question_type')
    def normalize_question_type(cls, v: Optional[str]) -> str:
        s = (v or "mcq").strip().lower()
        mapping = {
            "mcq": "mcq",
            "multiple_choice": "mcq",
            "coding": "coding",
            "programming": "coding",
            "short_answer": "short_answer",
            "short": "short_answer",
            "descriptive": "descriptive",
            "long_answer": "descriptive",
            "long": "descriptive",
            "essay": "descriptive",
            "mixed": "mixed"
        }
        if s in mapping:
            return mapping[s]
        raise ValueError(f"Unsupported question_type '{v}'. Must be one of: mcq, coding, short_answer, descriptive, mixed")

class GeneratedQuestionItem(BaseModel):
    question: str = Field(..., min_length=5)
    question_type: str = Field("mcq", description="mcq, coding, short_answer, descriptive")
    options: Optional[Dict[str, str]] = Field(None, description="A, B, C, D choices for MCQ")
    correct_answer: Optional[str] = Field(None, description="Correct option key for MCQ, or concise answer")
    explanation: Optional[str] = Field("", description="Answer explanation or marking guide")
    subject: str = Field(...)
    topic: Optional[str] = Field("", description="Topic name")
    difficulty: Literal["easy", "medium", "hard"] = Field(...)

    # Coding / Programming specific attributes
    programming_language: Optional[str] = Field(None, description="Language e.g. Python, Java, C, C++, SQL, JavaScript")
    input_format: Optional[str] = Field(None, description="Input format specification")
    output_format: Optional[str] = Field(None, description="Output format specification")
    constraints: Optional[str] = Field(None, description="Complexity and value constraints")
    sample_test_cases: Optional[List[Dict[str, Any]]] = Field(default_factory=list, description="Examples with input and output")
    hidden_test_cases: Optional[List[Dict[str, Any]]] = Field(default_factory=list, description="Evaluation test cases")
    reference_solution: Optional[str] = Field(None, description="Reference code implementation or solution query")
    starter_code: Optional[str] = Field(None, description="Starter code template or function signature")
    time_complexity: Optional[str] = Field(None, description="Expected time complexity e.g. O(N)")
    space_complexity: Optional[str] = Field(None, description="Expected space complexity e.g. O(1)")

    @field_validator('options')
    def validate_options(cls, opts: Optional[Dict[str, str]]) -> Optional[Dict[str, str]]:
        if opts is None:
            return None
        if len(opts) == 0:
            return None
        expected_keys = {"A", "B", "C", "D"}
        if set(opts.keys()) != expected_keys:
            raise ValueError(f"MCQ Options must contain exactly keys A, B, C, D. Got: {list(opts.keys())}")
        
        cleaned = {}
        for k, v in opts.items():
            val = str(v).strip()
            if not val:
                raise ValueError(f"Option {k} cannot be empty")
            cleaned[k] = val
            
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

class FocusedNotesRequest(BaseModel):
    question_id: Optional[int] = Field(None, description="Optional active question ID")
    ai_question_id: Optional[int] = Field(None, description="Optional AI staged question ID")
    practice_answer_id: Optional[int] = Field(None, description="Optional practice answer ID")
    question: str = Field(..., min_length=5, description="Full question text")
    subject: str = Field(..., min_length=1, max_length=100, description="Subject name")
    topic: str = Field(..., min_length=1, max_length=100, description="Topic name")
    correct_answer: Optional[str] = Field("A", description="Correct answer option or text")
    explanation: Optional[str] = Field("", description="Existing brief answer explanation")
    options: Optional[Dict[str, str]] = Field(None, description="Optional choices dictionary")

    @field_validator('question', 'subject', 'topic')
    def validate_non_empty(cls, v: str) -> str:
        s = (v or "").strip()
        if not s:
            raise ValueError("Field cannot be blank")
        return s

class FocusedNotesResponse(BaseModel):
    status: Literal["success", "error"] = "success"
    question_id: Optional[int] = None
    ai_question_id: Optional[int] = None
    practice_answer_id: Optional[int] = None
    concept_title: str = Field(..., description="Title of the specific tested concept")
    notes_content: str = Field(..., description="Detailed structured study notes")
    rag_sources: List[Dict[str, Any]] = Field(default_factory=list, description="Citations from course materials")
    is_grounded: bool = Field(False, description="True if grounded in approved course material chunks")
    source_count: int = Field(0, description="Number of retrieved RAG source chunks")
    message: Optional[str] = None