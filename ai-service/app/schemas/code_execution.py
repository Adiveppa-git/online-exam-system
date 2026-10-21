from typing import List, Optional, Any
from pydantic import BaseModel, Field, field_validator

class CodeExecutionRequest(BaseModel):
    language: str = Field(..., description="Programming language (e.g. python, java, c, cpp)")
    code: str = Field(..., min_length=1, max_length=65536, description="Source code to execute")
    stdin: Optional[str] = Field("", description="Standard input to provide to the running program")
    timeout_seconds: Optional[float] = Field(5.0, ge=0.5, le=10.0, description="Execution timeout in seconds")

    @field_validator('language')
    def normalize_language(cls, v: str) -> str:
        s = (v or "").strip().lower()
        if not s:
            raise ValueError("Language cannot be empty")
        return s

class TestCaseItem(BaseModel):
    input: Any = Field("", description="Sample input for test case")
    output: Optional[Any] = Field("", description="Expected sample output")
    expected_output: Optional[Any] = Field(None, description="Alternative field for expected sample output")
    explanation: Optional[str] = Field("", description="Optional explanation of test case")

    def model_post_init(self, __context: Any) -> None:
        if (self.output is None or self.output == "") and self.expected_output is not None:
            self.output = self.expected_output
        elif (self.expected_output is None or self.expected_output == "") and self.output is not None:
            self.expected_output = self.output

class CodeCheckRequest(BaseModel):
    language: str = Field(..., description="Programming language (e.g. python, java, c, cpp)")
    code: str = Field(..., min_length=1, max_length=65536, description="Source code to execute")
    test_cases: List[TestCaseItem] = Field(default_factory=list, description="List of sample test cases")
    timeout_seconds: Optional[float] = Field(5.0, ge=0.5, le=10.0, description="Execution timeout per test case")

    @field_validator('language')
    def normalize_language(cls, v: str) -> str:
        s = (v or "").strip().lower()
        if not s:
            raise ValueError("Language cannot be empty")
        return s

class CodeExecutionResponse(BaseModel):
    status: str = Field(..., description="'success', 'compilation_error', 'runtime_error', 'timeout', or 'error'")
    stdout: str = Field("", description="Standard output from executed code")
    stderr: str = Field("", description="Standard error or compilation/runtime error details")
    exit_code: int = Field(0, description="Process exit code")
    execution_time_ms: float = Field(0.0, description="Execution elapsed time in milliseconds")
    timeout: bool = Field(False, description="Whether execution timed out")
    timed_out: bool = Field(False, description="Alias for timeout")

    def model_post_init(self, __context: Any) -> None:
        if self.timeout and not self.timed_out:
            self.timed_out = True
        elif self.timed_out and not self.timeout:
            self.timeout = True

class TestCaseResult(BaseModel):
    case_index: int = Field(..., description="1-based index of the test case")
    input: str = Field("", description="Input given to test case")
    expected_output: str = Field("", description="Expected output")
    actual_output: str = Field("", description="Actual program stdout")
    passed: bool = Field(..., description="Whether actual output matches expected output")
    error: Optional[str] = Field("", description="Runtime or compilation error details if any")
    execution_time_ms: float = Field(0.0, description="Elapsed time in milliseconds")

class CodeCheckResponse(BaseModel):
    status: str = Field(..., description="'success', 'compilation_error', 'runtime_error', 'timeout', or 'error'")
    total_cases: int = Field(0, description="Total number of test cases checked")
    total: int = Field(0, description="Alias for total_cases")
    passed_cases: int = Field(0, description="Number of test cases passed")
    passed: int = Field(0, description="Alias for passed_cases")
    all_passed: bool = Field(False, description="Whether all sample test cases passed")
    results: List[TestCaseResult] = Field(default_factory=list, description="Detailed per-case results")
    error_summary: Optional[str] = Field("", description="Summary error message if check failed")

    def model_post_init(self, __context: Any) -> None:
        if self.total_cases and not self.total:
            self.total = self.total_cases
        elif self.total and not self.total_cases:
            self.total_cases = self.total
        if self.passed_cases and not self.passed:
            self.passed = self.passed_cases
        elif self.passed and not self.passed_cases:
            self.passed_cases = self.passed
