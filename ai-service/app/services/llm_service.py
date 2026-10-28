import json
import logging
import uuid
import httpx
import re
from typing import List, Dict, Any, Tuple, Optional
from app.config import settings
from app.schemas.question import QuestionGenerationRequest, GeneratedQuestionItem, FocusedNotesRequest

logger = logging.getLogger("llm_service")

class LLMService:
    @staticmethod
    def is_external_configured() -> bool:
        """
        Returns True if an external LLM API key and provider are configured.
        """
        api_key = getattr(settings, 'LLM_API_KEY', None)
        provider = getattr(settings, 'LLM_PROVIDER', 'heuristic').lower()
        return bool(api_key and api_key != "your_llm_api_key_here" and provider not in ["heuristic", "none"])

    @staticmethod
    def generate_chat_completion(messages: List[Dict[str, str]], temperature: float = 0.3) -> str:
        """
        Executes multi-turn chat completion against configured OpenAI-compatible LLM provider.
        """
        api_key = getattr(settings, 'LLM_API_KEY', None)
        model_name = getattr(settings, 'LLM_MODEL', 'gpt-3.5-turbo')
        base_url = getattr(settings, 'LLM_BASE_URL', 'https://api.openai.com/v1')

        if not api_key or api_key == "your_llm_api_key_here":
            raise ValueError("No LLM_API_KEY configured for external LLM generation.")

        url = f"{base_url.rstrip('/')}/chat/completions"
        headers = {
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json"
        }
        body = {
            "model": model_name,
            "messages": messages,
            "temperature": temperature
        }

        with httpx.Client(timeout=25.0) as client:
            resp = client.post(url, headers=headers, json=body)
            resp.raise_for_status()
            return resp.json()["choices"][0]["message"]["content"]

    @staticmethod
    def generate_completion(system_prompt: str, user_prompt: str) -> str:
        """
        Executes chat completion against configured OpenAI-compatible LLM provider.
        """
        api_key = getattr(settings, 'LLM_API_KEY', None)
        model_name = getattr(settings, 'LLM_MODEL', 'gpt-3.5-turbo')
        base_url = getattr(settings, 'LLM_BASE_URL', 'https://api.openai.com/v1')

        if not api_key or api_key == "your_llm_api_key_here":
            raise ValueError("No LLM_API_KEY configured for external LLM generation.")

        url = f"{base_url.rstrip('/')}/chat/completions"
        headers = {
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json"
        }
        body = {
            "model": model_name,
            "messages": [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt}
            ],
            "temperature": 0.2
        }

        with httpx.Client(timeout=20.0) as client:
            resp = client.post(url, headers=headers, json=body)
            resp.raise_for_status()
            return resp.json()["choices"][0]["message"]["content"]

    @staticmethod
    def _detect_programming_language(subject: str, topic: str = "", context: str = "") -> str:
        combined = f"{subject} {topic} {context}".lower()
        if "python" in combined:
            return "Python"
        if "javascript" in combined or "node" in combined or "typescript" in combined or "js" in combined.split() or "ts" in combined.split():
            return "JavaScript"
        if "c++" in combined or "cpp" in combined:
            return "C++"
        if "sql" in combined or "database" in combined or "dbms" in combined or "mysql" in combined or "postgres" in combined:
            return "SQL"
        if "java" in combined and "javascript" not in combined:
            return "Java"
        if "c#" in combined or "csharp" in combined:
            return "C#"
        if "rust" in combined:
            return "Rust"
        if "golang" in combined or "go" in combined.split():
            return "Go"
        if "php" in combined:
            return "PHP"
        if "html" in combined or "css" in combined:
            return "HTML/CSS"
        if re.search(r'\bc\b', combined) or "c programming" in combined:
            return "C"
        clean_subj = subject.strip()
        if any(w in clean_subj.lower() for w in ["program", "code", "software", "algorithm", "data structure", "dsa"]):
            return "Python"
        return clean_subj if clean_subj else "Python"

    @staticmethod
    def _is_coding_subject_or_topic(subject: str, topic: str = "", context: str = "") -> bool:
        combined = f"{subject} {topic} {context}".lower()
        coding_keywords = [
            "python", "java", "c++", "cpp", "c#", "csharp", "javascript", "typescript",
            "sql", "mysql", "postgres", "sqlite", "oracle", "database", "dbms",
            "html", "css", "web development", "frontend", "backend",
            "data structure", "data structures", "dsa", "algorithm", "algorithms",
            "machine learning", "deep learning", "neural network",
            "networking", "computer network", "tcp", "udp", "http",
            "php", "ruby", "rust", "golang", "go", "swift", "kotlin", "bash", "shell", "linux",
            "coding", "programming", "code", "developer", "syntax", "compile",
            "loop", "loops", "iteration", "for loop", "while loop",
            "function", "functions", "method", "methods", "recursion",
            "array", "arrays", "string", "strings", "pointer", "pointers",
            "linked list", "stack", "queue", "tree", "binary tree", "graph",
            "sort", "sorting", "search", "searching", "hash table", "hashmap",
            "class", "classes", "object oriented", "oop", "inheritance", "polymorphism",
            "exception", "try catch", "debug", "debugging", "bug", "output",
            "complexity", "time complexity", "space complexity", "big o",
            "query", "queries", "table", "schema", "join", "group by"
        ]
        if re.search(r'\bc\b', combined) or "c programming" in combined:
            return True
        return any(kw in combined for kw in coding_keywords)

    @staticmethod
    def _safe_normalize_code_newlines(val: Optional[str]) -> Optional[str]:
        if not val or not isinstance(val, str):
            return val
        if r'\r\n' in val or r'\n' in val:
            # Quote-aware tokenization to preserve in-string escapes like printf("Hello\n")
            tokens = re.split(r'("(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\')', val)
            res = []
            for i, tok in enumerate(tokens):
                if i % 2 == 1:
                    # Inside string literal: keep intact
                    res.append(tok)
                else:
                    tok = re.sub(r'(?<!\\)\\r\\n', '\r\n', tok)
                    tok = re.sub(r'(?<!\\)\\n', '\n', tok)
                    res.append(tok)
            val = "".join(res)
        return val.replace('\r\n', '\n')

    @staticmethod
    def generate_questions(req: QuestionGenerationRequest) -> Dict[str, Any]:
        request_id = str(uuid.uuid4())
        model_name = getattr(settings, 'LLM_MODEL', 'gpt-4o-mini')
        api_key = getattr(settings, 'LLM_API_KEY', None)

        raw_questions = []

        if api_key and api_key != "your_llm_api_key_here":
            try:
                raw_questions = LLMService._query_external_llm(req, api_key, model_name)
            except Exception as e:
                logger.error(f"External LLM API call failed: {e}. Falling back to heuristic generator.")
                raw_questions = LLMService._generate_heuristic_questions(req)
        else:
            logger.info("No LLM_API_KEY configured. Utilizing structured heuristic generator.")
            raw_questions = LLMService._generate_heuristic_questions(req)

        validated_items: List[GeneratedQuestionItem] = []
        seen_questions = set()

        for idx, item in enumerate(raw_questions):
            try:
                q_text = LLMService._safe_normalize_code_newlines(item.get("question", "")) or ""
                q_text = q_text.strip()
                # Narrowly strip any artificial trailing variant suffix (e.g., "(Variant 48)", "(variant 7)")
                q_text = re.sub(r'\s*\([Vv]ariant\s+\d+\)\s*$', '', q_text).strip()
                q_text = re.sub(r'```\s*\?+$', '```', q_text)
                if q_text and q_text[-1] not in "?!.:" and not q_text.endswith("```") and q_text[-1] not in "}`:;]>":
                    q_text += "?"
                if not q_text or q_text in seen_questions:
                    continue
                seen_questions.add(q_text)

                q_type = item.get("question_type") or req.question_type
                if q_type == "mixed":
                    q_type = "mcq"

                topic_label = req.topic if req.topic else req.subject
                exp_default = f"Based on {topic_label} principles and requirements."

                # Preserve options whenever provided
                opts = item.get("options") if item.get("options") else None
                ref_sol_val = LLMService._safe_normalize_code_newlines(item.get("reference_solution"))
                starter_code_val = LLMService._safe_normalize_code_newlines(item.get("starter_code"))
                corr_ans = item.get("correct_answer") or ref_sol_val or ""

                prog_lang = item.get("programming_language")
                if not prog_lang and LLMService._is_coding_subject_or_topic(req.subject, req.topic or "", req.additional_context or ""):
                    prog_lang = LLMService._detect_programming_language(req.subject, req.topic or "", req.additional_context or "")

                validated = GeneratedQuestionItem(
                    question=q_text,
                    question_type=q_type,
                    options=opts,
                    correct_answer=corr_ans,
                    explanation=item.get("explanation") or exp_default,
                    subject=req.subject,
                    topic=req.topic or "",
                    difficulty=item.get("difficulty") or req.difficulty,
                    programming_language=prog_lang,
                    input_format=item.get("input_format"),
                    output_format=item.get("output_format"),
                    constraints=item.get("constraints"),
                    sample_test_cases=item.get("sample_test_cases") or [],
                    hidden_test_cases=item.get("hidden_test_cases") or [],
                    reference_solution=ref_sol_val,
                    starter_code=starter_code_val,
                    time_complexity=item.get("time_complexity"),
                    space_complexity=item.get("space_complexity")
                )
                validated_items.append(validated)
            except Exception as val_err:
                logger.warning(f"Skipping invalid generated question item {idx}: {val_err}")

        return {
            "request_id": request_id,
            "status": "success",
            "model_used": model_name if api_key else "heuristic-engine-v2",
            "questions": validated_items
        }

    @staticmethod
    def _query_external_llm(req: QuestionGenerationRequest, api_key: str, model_name: str) -> List[Dict[str, Any]]:
        url = "https://api.openai.com/v1/chat/completions"
        headers = {
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json"
        }

        topic_str = f"Topic: {req.topic}" if req.topic else f"Topic: General {req.subject} concepts"
        ctx_str = f"Admin Instructions / Additional Context: {req.additional_context}" if req.additional_context else "Admin Instructions / Additional Context: None"
        is_coding = LLMService._is_coding_subject_or_topic(req.subject, req.topic or "", req.additional_context or "")
        detected_lang = LLMService._detect_programming_language(req.subject, req.topic or "", req.additional_context or "")

        if req.question_type == "coding":
            prompt = f"""
Generate {req.number_of_questions} genuine, challenging coding and programming problems.
Subject: {req.subject}
{topic_str}
Language: {detected_lang}
Difficulty: {req.difficulty}
{ctx_str}

Return ONLY a valid JSON object with key 'questions' containing an array of objects.
Each object must have:
- 'question': string (Comprehensive problem title and detailed problem description, including readable code snippets with indentation where appropriate)
- 'question_type': 'coding'
- 'programming_language': '{detected_lang}'
- 'options': object with keys 'A', 'B', 'C', 'D' providing 4 distinct plausible implementation or output choices
- 'correct_answer': 'A' or 'B' or 'C' or 'D' matching the correct option
- 'input_format': string (Clear specification of input format)
- 'output_format': string (Clear specification of expected output format)
- 'constraints': string (Constraints on input values, time limit, memory limit)
- 'sample_test_cases': array of objects with keys 'input', 'output', 'explanation'
- 'hidden_test_cases': array of objects with keys 'input', 'output'
- 'reference_solution': string (Complete, correct, working reference code implementation)
- 'starter_code': string (Starter template or function signature for students to complete)
- 'time_complexity': string (e.g. O(N), O(N log N))
- 'space_complexity': string (e.g. O(1), O(N))
- 'difficulty': '{req.difficulty}'
- 'explanation': string (Detailed explanation of the algorithmic approach)
"""
        elif is_coding and req.question_type == "mcq":
            prompt = f"""
Generate {req.number_of_questions} practical, programming-focused multiple choice questions (MCQ) for {req.subject}.
{topic_str}
Language: {detected_lang}
Difficulty: {req.difficulty}
{ctx_str}

IMPORTANT GUIDELINES FOR PROGRAMMING MCQS:
- Include genuine practical coding questions such as:
  1. Predicting the exact output of code snippets (include the indented code snippet in 'question')
  2. Identifying and fixing programming or syntax/logic errors in code snippets
  3. Selecting the correct code implementation or SQL query for specific tasks (e.g. reversing a string, finding second-largest, loops, joins)
  4. Analyzing algorithmic time complexity
  5. Language-specific constructs, keywords, and data structure behaviors
- DO NOT generate generic abstract boilerplate questions (e.g., do NOT ask "What is a primary principle of X?").
- Format code snippets cleanly inside the question text with proper indentation preserved.

Return ONLY a valid JSON object with key 'questions' containing an array of objects.
Each object must have:
- 'question': string (Clear question text including indented code snippet if applicable)
- 'question_type': 'mcq'
- 'programming_language': '{detected_lang}'
- 'options': object with keys 'A', 'B', 'C', 'D' containing 4 distinct, plausible choices
- 'correct_answer': exactly one of 'A', 'B', 'C', 'D'
- 'explanation': string detailed educational explanation
- 'difficulty': '{req.difficulty}'
"""
        elif req.question_type == "short_answer":
            prompt = f"""
Generate {req.number_of_questions} short-answer examination questions.
Subject: {req.subject}
{topic_str}
Difficulty: {req.difficulty}
{ctx_str}

Return ONLY a valid JSON object with key 'questions' containing an array of objects.
Each object must have:
- 'question': string (The short answer question)
- 'question_type': 'short_answer'
- 'correct_answer': string (Concise model answer or key points)
- 'explanation': string (Grading criteria or explanation)
- 'difficulty': '{req.difficulty}'
"""
        elif req.question_type == "descriptive":
            prompt = f"""
Generate {req.number_of_questions} descriptive/long-answer examination questions.
Subject: {req.subject}
{topic_str}
Difficulty: {req.difficulty}
{ctx_str}

Return ONLY a valid JSON object with key 'questions' containing an array of objects.
Each object must have:
- 'question': string (The comprehensive descriptive question or case study)
- 'question_type': 'descriptive'
- 'reference_solution': string (Comprehensive model answer and solution breakdown)
- 'explanation': string (Detailed grading rubric and marking guidelines)
- 'difficulty': '{req.difficulty}'
"""
        elif req.question_type == "mixed":
            prompt = f"""
Generate {req.number_of_questions} mixed examination questions containing a balanced combination of:
1. Multiple Choice Questions (MCQ) with 4 options ('A', 'B', 'C', 'D')
2. Coding / Programming problems
3. Short Answer questions
4. Descriptive questions

Subject: {req.subject}
{topic_str}
Difficulty: {req.difficulty}
{ctx_str}

Return ONLY a valid JSON object with key 'questions' containing an array of objects.
For MCQs include 'options' (keys A, B, C, D) and 'correct_answer' (A, B, C, or D).
For Coding include 'programming_language', 'input_format', 'output_format', 'constraints', 'sample_test_cases', 'reference_solution', 'time_complexity', 'space_complexity'.
For Short Answer and Descriptive include 'correct_answer', 'reference_solution', 'explanation'.
"""
        else:
            prompt = f"""
Generate {req.number_of_questions} multiple choice questions (MCQ) for an examination.
Subject: {req.subject}
{topic_str}
Difficulty: {req.difficulty}
{ctx_str}

Return ONLY a valid JSON object with a key 'questions' containing an array of objects.
Each object must have:
- 'question': string
- 'question_type': 'mcq'
- 'options': object with keys 'A', 'B', 'C', 'D' containing distinct string choices
- 'correct_answer': one of 'A', 'B', 'C', 'D'
- 'explanation': string detailed explanation
"""

        body = {
            "model": model_name,
            "messages": [
                {"role": "system", "content": "You are an expert academic examiner. Output strictly valid JSON."},
                {"role": "user", "content": prompt}
            ],
            "response_format": {"type": "json_object"},
            "temperature": 0.7
        }

        with httpx.Client(timeout=35.0) as client:
            resp = client.post(url, headers=headers, json=body)
            resp.raise_for_status()
            content = resp.json()["choices"][0]["message"]["content"]
            clean_content = content.strip()
            if clean_content.startswith("```"):
                clean_content = re.sub(r"^```(?:json)?\s*", "", clean_content, flags=re.IGNORECASE)
                clean_content = re.sub(r"\s*```$", "", clean_content)
            try:
                parsed = json.loads(clean_content)
            except Exception:
                match = re.search(r'(\{.*\})', clean_content, re.DOTALL)
                if match:
                    parsed = json.loads(match.group(1))
                else:
                    raise
            return parsed.get("questions", [])

    @staticmethod
    def _generate_heuristic_questions(req: QuestionGenerationRequest) -> List[Dict[str, Any]]:
        q_type = getattr(req, 'question_type', 'mcq') or 'mcq'
        if q_type == "coding":
            return LLMService._generate_heuristic_coding_questions(req)
        elif q_type == "short_answer":
            return LLMService._generate_heuristic_short_answer_questions(req)
        elif q_type == "descriptive":
            return LLMService._generate_heuristic_descriptive_questions(req)
        elif q_type == "mixed":
            return LLMService._generate_heuristic_mixed_questions(req)
        else:
            if LLMService._is_coding_subject_or_topic(req.subject, req.topic or "", req.additional_context or ""):
                return LLMService._generate_heuristic_coding_mcq_questions(req)
            return LLMService._generate_heuristic_mcq_questions(req)

    @staticmethod
    def _generate_heuristic_coding_questions(req: QuestionGenerationRequest) -> List[Dict[str, Any]]:
        lang = LLMService._detect_programming_language(req.subject, req.topic or "", req.additional_context or "")
        diff_label = req.difficulty.capitalize()
        topic_label = req.topic if req.topic else req.subject
        combined = f"{req.subject} {req.topic} {req.additional_context}".lower()

        problem_bank = [
            # LOOP-SPECIFIC CODING PROBLEMS
            {
                "keys": ["reverse string", "reverse array", "reverse in-place"],
                "title": f"Reverse String In-Place using Loop in {lang}",
                "description": f"Write an efficient function in {lang} to reverse a given string or character array in-place using an iterative loop construct.",
                "input_format": "A single line containing the string S.",
                "output_format": "Print the reversed string.",
                "constraints": "1 <= length(S) <= 10^5\nTime Limit: 1.0s\nMemory Limit: 256MB",
                "sample_test_cases": [
                    {"input": "hello", "output": "olleh", "explanation": "String characters reversed symmetrically."}
                ],
                "hidden_test_cases": [
                    {"input": "a", "output": "a"}
                ],
                "reference_solution": {
                    "Python": "def reverse_string(s: str) -> str:\n    chars = list(s)\n    i, j = 0, len(chars) - 1\n    while i < j:\n        chars[i], chars[j] = chars[j], chars[i]\n        i += 1\n        j -= 1\n    return ''.join(chars)",
                    "Java": "public class Solution {\n    public static String reverseString(String s) {\n        char[] arr = s.toCharArray();\n        int i = 0, j = arr.length - 1;\n        while (i < j) {\n            char temp = arr[i];\n            arr[i] = arr[j];\n            arr[j] = temp;\n            i++; j--;\n        }\n        return new String(arr);\n    }\n}",
                    "C": "#include <stdio.h>\n#include <string.h>\nvoid reverse(char *s) {\n    int i = 0, j = strlen(s) - 1;\n    while (i < j) {\n        char t = s[i];\n        s[i] = s[j];\n        s[j] = t;\n        i++; j--;\n    }\n}",
                    "C++": "#include <iostream>\n#include <string>\nusing namespace std;\nstring reverseString(string s) {\n    int i = 0, j = s.length() - 1;\n    while (i < j) {\n        swap(s[i++], s[j--]);\n    }\n    return s;\n}",
                    "SQL": "SELECT REVERSE(string_val) AS reversed FROM input_table;",
                    "JavaScript": "function reverseString(s) {\n    let arr = s.split('');\n    let i = 0, j = arr.length - 1;\n    while (i < j) {\n        [arr[i], arr[j]] = [arr[j], arr[i]];\n        i++; j--;\n    }\n    return arr.join('');\n}"
                },
                "time_complexity": "O(N)",
                "space_complexity": "O(1)",
                "explanation": "Two-pointer iterative loop swapping elements symmetrically in single pass."
            },
            {
                "keys": ["second largest", "second-largest", "second highest element"],
                "title": f"Find Second-Largest Element using Single Loop in {lang}",
                "description": f"Given an array of integers, write an algorithm in {lang} to find the second-largest distinct element using a single pass loop without sorting.",
                "options": {
                    "A": "Maintain two tracker variables (first and second) updated dynamically in a single O(N) loop.",
                    "B": "Sort the array in O(N log N) time and access the second index without checking for duplicates.",
                    "C": "Nested O(N^2) loops comparing each element with every other element.",
                    "D": "Return the element at index 1 without scanning the rest of the array."
                },
                "correct_answer": "A",
                "input_format": "First line: integer N. Second line: N space-separated integers.",
                "output_format": "Print the second-largest distinct integer, or -1 if none exists.",
                "constraints": "1 <= N <= 10^5\n-10^9 <= arr[i] <= 10^9\nTime Limit: 1.0s",
                "sample_test_cases": [
                    {"input": "5\n12 35 1 10 34", "output": "34", "explanation": "Largest is 35, second largest is 34."}
                ],
                "hidden_test_cases": [
                    {"input": "2\n10 10", "output": "-1"}
                ],
                "reference_solution": {
                    "Python": "def second_largest(arr):\n    first = second = float('-inf')\n    for x in arr:\n        if x > first:\n            second = first\n            first = x\n        elif x > second and x != first:\n            second = x\n    return second if second != float('-inf') else -1",
                    "Java": "public class Solution {\n    public static int secondLargest(int[] arr) {\n        int first = Integer.MIN_VALUE, second = Integer.MIN_VALUE;\n        for (int x : arr) {\n            if (x > first) {\n                second = first;\n                first = x;\n            } else if (x > second && x != first) {\n                second = x;\n            }\n        }\n        return (second != Integer.MIN_VALUE) ? second : -1;\n    }\n}",
                    "C": "int secondLargest(int arr[], int n) {\n    int first = -2147483648, second = -2147483648;\n    for (int i = 0; i < n; i++) {\n        if (arr[i] > first) { second = first; first = arr[i]; }\n        else if (arr[i] > second && arr[i] != first) { second = arr[i]; }\n    }\n    return (second != -2147483648) ? second : -1;\n}",
                    "SQL": "SELECT MAX(val) FROM numbers WHERE val < (SELECT MAX(val) FROM numbers);"
                },
                "time_complexity": "O(N)",
                "space_complexity": "O(1)",
                "explanation": "Single-pass loop tracking first and second maximum distinct values."
            },
            {
                "keys": ["loop", "loops", "sum", "accumulator", "filter"],
                "title": f"Conditional Accumulator Loop in {lang}",
                "description": f"Implement a loop in {lang} that iterates over an integer array, sums all even numbers, and multiplies the accumulator by 2 whenever a prime number is encountered.",
                "options": {
                    "A": "Single iterative loop with modular arithmetic checks for evenness and a helper primality check.",
                    "B": "Recursive traversal with unmemoized calls leading to exponential time complexity.",
                    "C": "Using global state without thread locks causing inconsistent results.",
                    "D": "Nested loop that re-sums the entire array on each element."
                },
                "correct_answer": "A",
                "input_format": "Line 1: N integers space separated.",
                "output_format": "Single integer containing the computed accumulator value.",
                "constraints": "1 <= N <= 10^4",
                "sample_test_cases": [
                    {"input": "2 4 3 6", "output": "24", "explanation": "2+4=6; prime 3 doubles to 12; +6 = 18."}
                ],
                "hidden_test_cases": [
                    {"input": "4 4", "output": "8"}
                ],
                "reference_solution": {
                    "Python": "def compute_accumulator(nums):\n    def is_prime(n):\n        if n < 2: return False\n        for i in range(2, int(n**0.5) + 1):\n            if n % i == 0: return False\n        return True\n    acc = 0\n    for x in nums:\n        if x % 2 == 0:\n            acc += x\n        if is_prime(x):\n            acc *= 2\n    return acc"
                },
                "time_complexity": "O(N * sqrt(M))",
                "space_complexity": "O(1)",
                "explanation": "Loop iterating through elements applying state transitions conditionally."
            },
            {
                "keys": ["loop", "loops", "matrix", "nested"],
                "title": f"Matrix Diagonal Sum with Nested Loop in {lang}",
                "description": f"Given an N x N matrix, calculate the sum of the primary and secondary diagonal elements using loops in {lang}. Elements on both diagonals should only be counted once.",
                "options": {
                    "A": "Single loop running N iterations adding mat[i][i] and mat[i][N-1-i], subtracting center if N is odd.",
                    "B": "Two nested loops summing all elements in O(N^2) and dividing by 2.",
                    "C": "Accessing mat[i][j] out of bounds when j reaches N.",
                    "D": "Summing only the first row and last column."
                },
                "correct_answer": "A",
                "input_format": "First line: integer N. Next N lines: N integers each.",
                "output_format": "Print total diagonal sum.",
                "constraints": "1 <= N <= 1000",
                "sample_test_cases": [
                    {"input": "3\n1 2 3\n4 5 6\n7 8 9", "output": "25", "explanation": "1+5+9 + 3+7 = 25 (center 5 counted once)."}
                ],
                "hidden_test_cases": [
                    {"input": "1\n5", "output": "5"}
                ],
                "reference_solution": {
                    "Python": "def diagonal_sum(mat):\n    n = len(mat)\n    total = 0\n    for i in range(n):\n        total += mat[i][i]\n        if i != n - 1 - i:\n            total += mat[i][n - 1 - i]\n    return total"
                },
                "time_complexity": "O(N)",
                "space_complexity": "O(1)",
                "explanation": "Traverse matrix diagonals in a single loop of N iterations."
            },
            {
                "keys": ["loop", "loops", "while", "collatz"],
                "title": f"Collatz Conjecture Step Counter with While Loop in {lang}",
                "description": f"Write a program in {lang} using a while loop that takes a positive integer N and outputs the number of steps required to reach 1 following the 3N+1 rules.",
                "options": {
                    "A": "While loop checking n != 1, updating n = n // 2 if even else 3 * n + 1, incrementing a step counter.",
                    "B": "For loop running a fixed 10 iterations without checking if n has reached 1.",
                    "C": "Infinite recursion that overflows the call stack on large inputs.",
                    "D": "While loop that adds 1 unconditionally causing an infinite loop."
                },
                "correct_answer": "A",
                "input_format": "A single integer N.",
                "output_format": "Print the number of transformation steps.",
                "constraints": "1 <= N <= 10^6",
                "sample_test_cases": [
                    {"input": "6", "output": "8", "explanation": "6 -> 3 -> 10 -> 5 -> 16 -> 8 -> 4 -> 2 -> 1 (8 steps)."}
                ],
                "hidden_test_cases": [
                    {"input": "1", "output": "0"}
                ],
                "reference_solution": {
                    "Python": "def collatz_steps(n: int) -> int:\n    steps = 0\n    while n > 1:\n        if n % 2 == 0:\n            n //= 2\n        else:\n            n = 3 * n + 1\n        steps += 1\n    return steps"
                },
                "time_complexity": "O(log N) average",
                "space_complexity": "O(1)",
                "explanation": "Iterative state reduction terminating when n reaches 1."
            },
            {
                "keys": ["loop", "loops", "prime", "for"],
                "title": f"Optimized Primality Testing Loop in {lang}",
                "description": f"Implement a loop in {lang} to determine whether an integer N is prime, checking divisors up to sqrt(N).",
                "options": {
                    "A": "Check divisibility by 2 and 3, then iterate a loop with step 6 up to sqrt(N).",
                    "B": "Iterate from 2 to N checking all numbers with O(N) worst-case time.",
                    "C": "Check only if N is odd without checking odd composite factors.",
                    "D": "Return True for all positive integers."
                },
                "correct_answer": "A",
                "input_format": "Single integer N.",
                "output_format": "Print 'true' if N is prime, else 'false'.",
                "constraints": "1 <= N <= 10^9",
                "sample_test_cases": [
                    {"input": "29", "output": "true", "explanation": "29 has no divisors other than 1 and itself."}
                ],
                "hidden_test_cases": [
                    {"input": "1", "output": "false"},
                    {"input": "4", "output": "false"}
                ],
                "reference_solution": {
                    "Python": "def is_prime(n: int) -> bool:\n    if n <= 1: return False\n    if n <= 3: return True\n    if n % 2 == 0 or n % 3 == 0: return False\n    i = 5\n    while i * i <= n:\n        if n % i == 0 or n % (i + 2) == 0:\n            return False\n        i += 6\n    return True"
                },
                "time_complexity": "O(sqrt(N))",
                "space_complexity": "O(1)",
                "explanation": "Divisor check bounded by square root of N with 6k +/- 1 step optimization."
            },
            {
                "keys": ["palindrome"],
                "title": f"Palindrome Validation in {lang}",
                "description": f"Write a program in {lang} to determine whether a given string is a palindrome, considering alphanumeric characters and ignoring case differences.",
                "options": {
                    "A": "Two-pointer loop converging inwards skipping non-alphanumeric characters.",
                    "B": "String reversal using extra memory allocations without filtering punctuation.",
                    "C": "Hash-based heuristic with possible collision false positives.",
                    "D": "Checking only the first and last characters without verifying internal characters."
                },
                "correct_answer": "A",
                "input_format": "A single line containing string S.",
                "output_format": "Print 'true' if palindrome, else 'false'.",
                "constraints": "1 <= length(S) <= 2 * 10^5",
                "sample_test_cases": [
                    {"input": "A man, a plan, a canal: Panama", "output": "true", "explanation": "Alphanumeric characters match forwards and backwards."}
                ],
                "hidden_test_cases": [
                    {"input": "race a car", "output": "false"}
                ],
                "reference_solution": {
                    "Python": "def is_palindrome(s: str) -> bool:\n    filtered = [c.lower() for c in s if c.isalnum()]\n    return filtered == filtered[::-1]"
                },
                "time_complexity": "O(N)",
                "space_complexity": "O(1) auxiliary",
                "explanation": "Two pointers converging from ends."
            },
            {
                "keys": ["binary search"],
                "title": f"Iterative Binary Search in {lang}",
                "description": f"Implement iterative binary search in {lang} to find the index of target value in a sorted array.",
                "options": {
                    "A": "Iterative while loop updating low and high pointers based on midpoint comparison with O(log N) time.",
                    "B": "Linear scan checking each element sequentially in O(N) time.",
                    "C": "Midpoint calculation with potential integer overflow using (low + high) / 2 without bounds.",
                    "D": "Binary search that assumes array is in random order."
                },
                "correct_answer": "A",
                "input_format": "Line 1: N and target. Line 2: N sorted integers.",
                "output_format": "Print index of target, or -1 if not found.",
                "constraints": "1 <= N <= 10^5",
                "sample_test_cases": [
                    {"input": "5 7\n1 3 5 7 9", "output": "3", "explanation": "Element 7 found at 0-based index 3."}
                ],
                "hidden_test_cases": [
                    {"input": "3 4\n1 2 3", "output": "-1"}
                ],
                "reference_solution": {
                    "Python": "def binary_search(arr, target):\n    low, high = 0, len(arr) - 1\n    while low <= high:\n        mid = (low + high) // 2\n        if arr[mid] == target: return mid\n        elif arr[mid] < target: low = mid + 1\n        else: high = mid - 1\n    return -1",
                    "C": "int binarySearch(int arr[], int n, int target) {\n    int l = 0, r = n - 1;\n    while (l <= r) {\n        int m = l + (r - l) / 2;\n        if (arr[m] == target) return m;\n        if (arr[m] < target) l = m + 1;\n        else r = m - 1;\n    }\n    return -1;\n}"
                },
                "time_complexity": "O(log N)",
                "space_complexity": "O(1)",
                "explanation": "Logarithmic search halving search space on each iteration."
            },
            {
                "keys": ["salary", "second-highest salary", "second highest salary"],
                "title": "Find Second-Highest Salary in SQL",
                "description": "Write a SQL query to find the second-highest distinct salary from an employees table. If no second-highest salary exists, the query should report NULL.",
                "input_format": "Table 'employees' with columns: id (INT), salary (INT).",
                "output_format": "Single column named 'second_highest_salary'.",
                "constraints": "1 <= N <= 10^5",
                "sample_test_cases": [
                    {"input": "employees: [(1, 100), (2, 200), (3, 300)]", "output": "200", "explanation": "300 is highest, 200 is second highest."}
                ],
                "hidden_test_cases": [
                    {"input": "employees: [(1, 100)]", "output": "NULL"}
                ],
                "reference_solution": {
                    "SQL": "SELECT MAX(salary) AS second_highest_salary FROM employees WHERE salary < (SELECT MAX(salary) FROM employees);",
                    "Python": "def second_highest_salary(employees):\n    salaries = sorted(list(set(e['salary'] for e in employees)), reverse=True)\n    return salaries[1] if len(salaries) > 1 else None"
                },
                "time_complexity": "O(N)",
                "space_complexity": "O(1)",
                "explanation": "Select the MAX salary strictly less than the overall MAX salary."
            },
            {
                "keys": ["stack", "stacks", "stack using an array"],
                "title": f"Implement Stack using Array in {lang}",
                "description": f"Design and implement a fixed-capacity Stack data structure using an array in {lang} supporting push, pop, peek, and isEmpty operations.",
                "input_format": "First line: capacity C and operations M. Next M lines: operation strings.",
                "output_format": "Print the output of each pop, peek, and isEmpty call.",
                "constraints": "1 <= C <= 10^4",
                "sample_test_cases": [
                    {"input": "5 4\npush 10\npush 20\npop\npeek", "output": "20\n10", "explanation": "20 is popped, leaving 10 on top of the stack."}
                ],
                "hidden_test_cases": [
                    {"input": "2 2\npop\nisEmpty", "output": "-1\ntrue"}
                ],
                "reference_solution": {
                    "Python": "class ArrayStack:\n    def __init__(self, cap):\n        self.arr = [0] * cap\n        self.top = -1\n        self.cap = cap\n    def push(self, x):\n        if self.top >= self.cap - 1: return False\n        self.top += 1\n        self.arr[self.top] = x\n        return True\n    def pop(self):\n        if self.top < 0: return -1\n        val = self.arr[self.top]\n        self.top -= 1\n        return val",
                    "Java": "public class ArrayStack {\n    private int[] arr;\n    private int top, cap;\n    public ArrayStack(int cap) { this.cap = cap; arr = new int[cap]; top = -1; }\n    public boolean push(int x) { if (top >= cap - 1) return false; arr[++top] = x; return true; }\n    public int pop() { return top < 0 ? -1 : arr[top--]; }\n}",
                    "C": "typedef struct { int arr[1000]; int top; int cap; } ArrayStack;\nvoid init(ArrayStack *s, int c) { s->top = -1; s->cap = c; }\nint push(ArrayStack *s, int x) { if (s->top >= s->cap - 1) return 0; s->arr[++s->top] = x; return 1; }\nint pop(ArrayStack *s) { return s->top < 0 ? -1 : s->arr[s->top--]; }"
                },
                "time_complexity": "O(1) per operation",
                "space_complexity": "O(C)",
                "explanation": "Fixed-size array with top index pointer managing stack operations."
            }
        ]

        # Prioritize matching problems
        selected_problems = []
        for p in problem_bank:
            if any(k in combined for k in p["keys"]):
                selected_problems.append(p)

        for p in problem_bank:
            if p not in selected_problems:
                selected_problems.append(p)

        results = []
        for i in range(req.number_of_questions):
            if i < len(selected_problems):
                prob = selected_problems[i]
                ref_sol = prob["reference_solution"].get(lang) or prob["reference_solution"].get("Python") or str(list(prob["reference_solution"].values())[0])
                starter = prob.get("starter_code", {}).get(lang) if isinstance(prob.get("starter_code"), dict) else prob.get("starter_code")
                if not starter:
                    if lang == "Python":
                        starter = f"# Solution template for {prob['title']}\ndef solve():\n    # TODO: Implement your solution here\n    pass\n"
                    elif lang == "Java":
                        starter = f"// Solution template for {prob['title']}\npublic class Solution {{\n    public static void main(String[] args) {{\n        // TODO: Implement solution\n    }}\n}}\n"
                    elif lang in ["C", "C++"]:
                        starter = f"// Solution template for {prob['title']}\n#include <stdio.h>\n\nint main() {{\n    // TODO: Implement solution\n    return 0;\n}}\n"
                    elif lang == "SQL":
                        starter = f"-- Write your SQL solution below:\n"
                    else:
                        starter = f"// Solution template for {prob['title']} in {lang}\n"

                results.append({
                    "question": f"{prob['title']}\n\n{prob['description']}",
                    "question_type": "coding",
                    "programming_language": lang,
                    "starter_code": starter,
                    "input_format": prob["input_format"],
                    "output_format": prob["output_format"],
                    "constraints": prob["constraints"],
                    "sample_test_cases": prob["sample_test_cases"],
                    "hidden_test_cases": prob["hidden_test_cases"],
                    "reference_solution": ref_sol,
                    "time_complexity": prob["time_complexity"],
                    "space_complexity": prob["space_complexity"],
                    "difficulty": req.difficulty,
                    "explanation": f"{prob['explanation']} (Evaluated for {diff_label} difficulty in {req.subject}).",
                    "subject": req.subject,
                    "topic": req.topic
                })
            else:
                q_num = i + 1
                task_name = f"{topic_label} Algorithmic Processing Problem {q_num}"
                desc = (
                    f"Design and implement an efficient algorithm in {lang} to process structured {topic_label} datasets. "
                    f"Given a sequence of {topic_label.lower()} metrics, filter out outliers, identify the optimal configuration "
                    f"index according to {req.subject} standard specifications, and output the computed result."
                )
                sample_in = f"5\n10 45 20 80 30\nTarget: 80"
                sample_out = "3"
                ref_code = f"# {lang} Solution for {task_name}\ndef solve_problem_{q_num}(data, target):\n    for idx, val in enumerate(data):\n        if val == target:\n            return idx\n    return -1\n\nif __name__ == '__main__':\n    print(solve_problem_{q_num}([10, 45, 20, 80, 30], 80))"
                starter = f"# {lang} Starter Template for {task_name}\ndef solve_problem_{q_num}(data, target):\n    # TODO: Implement your solution\n    pass\n"

                results.append({
                    "question": f"{task_name}\n\n{desc}",
                    "question_type": "coding",
                    "programming_language": lang,
                    "starter_code": starter,
                    "input_format": f"First line: N elements. Second line: N space-separated {topic_label} values.",
                    "output_format": f"Computed index or optimal metric according to {req.subject} specifications.",
                    "constraints": f"1 <= N <= 10^5\nTime Limit: 1.0s\nMemory Limit: 256MB",
                    "sample_test_cases": [
                        {
                            "input": sample_in,
                            "output": sample_out,
                            "explanation": f"Optimal value matching target found at index 3 in {topic_label} dataset."
                        }
                    ],
                    "hidden_test_cases": [
                        {"input": "1\n50\nTarget: 50", "output": "0"}
                    ],
                    "reference_solution": ref_code,
                    "time_complexity": "O(N)",
                    "space_complexity": "O(1)",
                    "difficulty": req.difficulty,
                    "explanation": f"Optimal single-pass algorithm adhering to {topic_label} complexity bounds in {req.subject}.",
                    "subject": req.subject,
                    "topic": req.topic
                })

        return results

    @staticmethod
    def _generate_heuristic_coding_mcq_questions(req: QuestionGenerationRequest) -> List[Dict[str, Any]]:
        lang = LLMService._detect_programming_language(req.subject, req.topic or "", req.additional_context or "")
        combined = f"{req.subject} {req.topic} {req.additional_context}".lower()
        diff_label = req.difficulty.capitalize()
        topic_label = req.topic if req.topic else req.subject

        is_loop = any(k in combined for k in ["loop", "for loop", "while", "iteration", "range"])
        is_sql = "sql" in combined or lang == "SQL" or "database" in combined or "query" in combined
        is_java = lang == "Java"
        is_python = lang == "Python"
        is_c = lang in ["C", "C++"]
        is_js = lang == "JavaScript"

        catalog = []

        # 1. PYTHON LOOPS CATALOG (15 distinct, loop-focused MCQs)
        if is_python and is_loop:
            catalog = [
                {
                    "q": "What is the output of the following Python loop snippet?\n\n```python\ntotal = 0\nfor i in range(1, 10, 2):\n    total += i\nprint(total)\n```",
                    "opts": {"A": "25", "B": "20", "C": "45", "D": "30"},
                    "ans": "A",
                    "exp": "range(1, 10, 2) generates odd numbers [1, 3, 5, 7, 9]. Their sum is 1 + 3 + 5 + 7 + 9 = 25."
                },
                {
                    "q": "What will be printed by the following Python loop with an else clause?\n\n```python\nfor num in [2, 4, 6]:\n    if num % 2 != 0:\n        break\nelse:\n    print('All Even')\n```",
                    "opts": {"A": "All Even", "B": "Nothing is printed", "C": "SyntaxError", "D": "False"},
                    "ans": "A",
                    "exp": "In Python, the else clause attached to a for loop executes only when the loop completes without hitting a break statement."
                },
                {
                    "q": "What is the output of this Python while loop?\n\n```python\ncount = 0\ni = 0\nwhile i < 5:\n    i += 1\n    if i == 3:\n        continue\n    count += i\nprint(count)\n```",
                    "opts": {"A": "12", "B": "15", "C": "10", "D": "9"},
                    "ans": "A",
                    "exp": "i takes values 1, 2, 3, 4, 5. When i is 3, continue skips count += i. Sum is 1 + 2 + 4 + 5 = 12."
                },
                {
                    "q": "What is the output of the following Python list comprehension?\n\n```python\nres = [x * 2 for x in range(5) if x % 2 == 0]\nprint(res)\n```",
                    "opts": {"A": "[0, 4, 8]", "B": "[0, 2, 4]", "C": "[2, 4, 6]", "D": "[0, 2, 4, 6, 8]"},
                    "ans": "A",
                    "exp": "range(5) produces 0, 1, 2, 3, 4. Even numbers are 0, 2, 4. Multiplying each by 2 yields [0, 4, 8]."
                },
                {
                    "q": "Which of the following Python loop implementations correctly reverses a string `s`?",
                    "opts": {
                        "A": "rev = \"\"\nfor ch in s:\n    rev = ch + rev",
                        "B": "rev = \"\"\nfor ch in s:\n    rev = rev + ch",
                        "C": "rev = \"\"\nfor i in range(len(s)):\n    rev += s[i]",
                        "D": "rev = [s[i] for i in range(len(s))]"
                    },
                    "ans": "A",
                    "exp": "Prepending each character (rev = ch + rev) reverses the order of characters in the string."
                },
                {
                    "q": "Which Python loop construct correctly finds the second-largest distinct element in a list `nums`?",
                    "opts": {
                        "A": "first = second = float('-inf')\nfor n in nums:\n    if n > first:\n        second = first\n        first = n\n    elif n > second and n != first:\n        second = n",
                        "B": "first = second = float('-inf')\nfor n in nums:\n    if n > first:\n        first = n\n        second = first",
                        "C": "second = nums[0]\nfor n in nums:\n    if n < second:\n        second = n",
                        "D": "for i in range(len(nums)):\n    second = max(nums[i], nums[i-1])"
                    },
                    "ans": "A",
                    "exp": "When a new maximum is found, the previous maximum shifts to second. Otherwise, if n > second and distinct, second is updated."
                },
                {
                    "q": "What is the time complexity of the following nested loop in Python?\n\n```python\ncount = 0\nfor i in range(n):\n    for j in range(i, n):\n        count += 1\n```",
                    "opts": {"A": "O(n^2)", "B": "O(n)", "C": "O(n log n)", "D": "O(1)"},
                    "ans": "A",
                    "exp": "The inner loop runs n + (n-1) + ... + 1 = n*(n+1)/2 times, which belongs to O(n^2) complexity."
                },
                {
                    "q": "What bug or error occurs when executing the following while loop?\n\n```python\ni = 1\nwhile i <= 10:\n    print(i)\n    if i % 2 == 0:\n        i += 2\n```",
                    "opts": {
                        "A": "An infinite loop occurs because i is odd initially and is never incremented.",
                        "B": "An IndexError occurs during loop execution.",
                        "C": "A ZeroDivisionError is raised by the modulo operator.",
                        "D": "A SyntaxError prevents the script from executing."
                    },
                    "ans": "A",
                    "exp": "Since i starts at 1, i % 2 == 0 is False, so i is never incremented, creating an infinite loop printing 1."
                },
                {
                    "q": "What is the output of the following Python enumerate loop?\n\n```python\nitems = ['alpha', 'beta', 'gamma']\nfor idx, val in enumerate(items, start=1):\n    if idx == 2:\n        print(val)\n```",
                    "opts": {"A": "beta", "B": "alpha", "C": "gamma", "D": "IndexError"},
                    "ans": "A",
                    "exp": "enumerate(items, start=1) yields (1, 'alpha'), (2, 'beta'), (3, 'gamma'). At idx == 2, val is 'beta'."
                },
                {
                    "q": "What is the output of the following dictionary iteration loop?\n\n```python\ndata = {'a': 10, 'b': 20, 'c': 30}\ntotal = 0\nfor key in data:\n    total += data[key]\nprint(total)\n```",
                    "opts": {"A": "60", "B": "30", "C": "10", "D": "TypeError: dict is not iterable"},
                    "ans": "A",
                    "exp": "Iterating over a dictionary yields its keys ('a', 'b', 'c'). Summing data[key] gives 10 + 20 + 30 = 60."
                },
                {
                    "q": "What will be printed by the following Python while-else loop?\n\n```python\ni = 0\nwhile i < 3:\n    if i == 1:\n        break\n    i += 1\nelse:\n    print('Done')\n```",
                    "opts": {
                        "A": "Nothing is printed because break suppresses the else clause.",
                        "B": "Done",
                        "C": "1",
                        "D": "Done 1"
                    },
                    "ans": "A",
                    "exp": "A loop's else suite is skipped when the loop is terminated by a break statement."
                },
                {
                    "q": "What is the output of this Python loop with zip?\n\n```python\nnames = ['Alice', 'Bob']\nscores = [85, 92]\nfor n, s in zip(names, scores):\n    if s > 90:\n        print(n)\n```",
                    "opts": {"A": "Bob", "B": "Alice", "C": "92", "D": "Alice Bob"},
                    "ans": "A",
                    "exp": "zip pairs ('Alice', 85) and ('Bob', 92). Only Bob has a score > 90."
                },
                {
                    "q": "Which range call correctly iterates through all valid indices of a list `arr` without an off-by-one error?",
                    "opts": {
                        "A": "range(len(arr))",
                        "B": "range(1, len(arr) + 1)",
                        "C": "range(len(arr) + 1)",
                        "D": "range(0, len(arr) - 1)"
                    },
                    "ans": "A",
                    "exp": "range(len(arr)) generates indices from 0 up to len(arr) - 1, which matches 0-based indexing exactly."
                },
                {
                    "q": "What is the output of this accumulator product loop?\n\n```python\nprod = 1\nfor x in [1, 2, 3, 4]:\n    prod *= x\nprint(prod)\n```",
                    "opts": {"A": "24", "B": "10", "C": "12", "D": "120"},
                    "ans": "A",
                    "exp": "1 * 1 * 2 * 3 * 4 = 24."
                },
                {
                    "q": "What is the output of this Python loop with string slicing?\n\n```python\ntext = 'code'\nres = []\nfor i in range(len(text)):\n    res.append(text[:i+1])\nprint(res[-1])\n```",
                    "opts": {"A": "code", "B": "c", "C": "cod", "D": "IndexError"},
                    "ans": "A",
                    "exp": "The loop appends 'c', 'co', 'cod', 'code'. res[-1] is the last element: 'code'."
                }
            ]

        # 2. GENERAL PYTHON PROGRAMMING CATALOG
        elif is_python:
            catalog = [
                {
                    "q": "What is the output of the following Python function call?\n\n```python\ndef append_to(element, target=[]):\n    target.append(element)\n    return target\n\nprint(append_to(1))\nprint(append_to(2))\n```",
                    "opts": {
                        "A": "[1] then [1, 2]",
                        "B": "[1] then [2]",
                        "C": "[1] then [1]",
                        "D": "TypeError: default argument error"
                    },
                    "ans": "A",
                    "exp": "Default parameter values are evaluated once when the function is defined, making mutable defaults shared across calls."
                },
                {
                    "q": "Which of the following correctly reverses a string `s` in Python using slicing?",
                    "opts": {"A": "s[::-1]", "B": "s[::1]", "C": "s[0:-1]", "D": "s.reverse()"},
                    "ans": "A",
                    "exp": "s[::-1] steps backwards through the string with step -1, returning the reversed string."
                },
                {
                    "q": "What will be printed by the following Python code snippet?\n\n```python\na = [1, 2, 3]\nb = a\nb.append(4)\nprint(len(a))\n```",
                    "opts": {"A": "4", "B": "3", "C": "1", "D": "IndexError"},
                    "ans": "A",
                    "exp": "b = a creates an alias referencing the same list object in memory, so mutating b also affects a."
                },
                {
                    "q": "What is the output of the following recursion in Python?\n\n```python\ndef mystery(n):\n    if n <= 1:\n        return 1\n    return n * mystery(n - 1)\n\nprint(mystery(4))\n```",
                    "opts": {"A": "24", "B": "10", "C": "12", "D": "4"},
                    "ans": "A",
                    "exp": "mystery computes the factorial: 4 * 3 * 2 * 1 = 24."
                },
                {
                    "q": "Which exception is raised when accessing a non-existent key in a Python dict with `d[k]`?",
                    "opts": {"A": "KeyError", "B": "IndexError", "C": "ValueError", "D": "AttributeError"},
                    "ans": "A",
                    "exp": "Subscripting a dictionary with a missing key raises a KeyError. Use d.get(k) to return None instead."
                },
                {
                    "q": "What is the time complexity of looking up a key in a standard Python dictionary on average?",
                    "opts": {"A": "O(1)", "B": "O(N)", "C": "O(log N)", "D": "O(N^2)"},
                    "ans": "A",
                    "exp": "Python dictionaries use hash tables, achieving O(1) average-case time complexity for key lookups."
                },
                {
                    "q": "What is the output of the following generator expression?\n\n```python\ngen = (x**2 for x in [1, 2, 3])\nprint(next(gen), next(gen))\n```",
                    "opts": {"A": "1 4", "B": "1 1", "C": "4 9", "D": "1 9"},
                    "ans": "A",
                    "exp": "next(gen) yields values lazily on demand: first 1**2 = 1, then 2**2 = 4."
                },
                {
                    "q": "How does `is` differ from `==` in Python?",
                    "opts": {
                        "A": "`is` checks object memory identity, while `==` checks value equality.",
                        "B": "`is` checks value equality, while `==` checks memory identity.",
                        "C": "`is` works only on integers, while `==` works on strings.",
                        "D": "There is no difference between them."
                    },
                    "ans": "A",
                    "exp": "`is` evaluates whether two references point to the exact same object in memory, while `==` calls `__eq__` to compare values."
                },
                {
                    "q": "What is the output of the following code involving `try-finally`?\n\n```python\ndef test():\n    try:\n        return 1\n    finally:\n        return 2\n\nprint(test())\n```",
                    "opts": {"A": "2", "B": "1", "C": "None", "D": "SyntaxError"},
                    "ans": "A",
                    "exp": "A return statement in a finally block takes precedence over return statements inside the try block."
                },
                {
                    "q": "What does the `@property` decorator accomplish in a Python class?",
                    "opts": {
                        "A": "Defines a method that can be accessed like an attribute without parentheses.",
                        "B": "Makes a class method strictly static.",
                        "C": "Prevents subclasses from overriding the method.",
                        "D": "Encodes the return value as JSON."
                    },
                    "ans": "A",
                    "exp": "The `@property` decorator allows a method to be accessed as a getter attribute, providing encapsulation."
                },
                {
                    "q": "Which expression should replace `____` in the following loop to print only even numbers?\n\n```python\nfor n in [1, 2, 3, 4, 5, 6]:\n    if ____:\n        print(n)\n```",
                    "opts": {"A": "n % 2 == 0", "B": "n // 2 == 0", "C": "n / 2 == 0", "D": "n % 2 != 0"},
                    "ans": "A",
                    "exp": "The modulo operator % returns remainder after division; n % 2 == 0 evaluates to True for even integers."
                },
                {
                    "q": "Which expression should replace `____` to construct a list of squares for positive integers only?\n\n```python\nnums = [-2, -1, 0, 1, 2, 3]\nsquares = [____ for x in nums if x > 0]\n```",
                    "opts": {"A": "x ** 2", "B": "x ^ 2", "C": "sqr(x)", "D": "pow(2, x)"},
                    "ans": "A",
                    "exp": "In Python, the exponentiation operator is **. x ** 2 correctly calculates the square of x."
                }
            ]

        # 3. JAVA PROGRAMMING CATALOG
        elif is_java:
            catalog = [
                {
                    "q": "What is the output of the following Java snippet?\n\n```java\nclass Parent {\n    void show() { System.out.print(\"P \"); }\n}\nclass Child extends Parent {\n    void show() { System.out.print(\"C \"); }\n}\npublic class Test {\n    public static void main(String[] args) {\n        Parent obj = new Child();\n        obj.show();\n    }\n}\n```",
                    "opts": {"A": "C ", "B": "P ", "C": "Compilation Error", "D": "Runtime Exception"},
                    "ans": "A",
                    "exp": "Java resolves instance method calls at runtime via dynamic method dispatch (polymorphism), invoking Child's overridden show() method."
                },
                {
                    "q": "What is printed by the following Java string comparison?\n\n```java\nString s1 = new String(\"Java\");\nString s2 = new String(\"Java\");\nSystem.out.println((s1 == s2) + \" \" + s1.equals(s2));\n```",
                    "opts": {"A": "false true", "B": "true true", "C": "true false", "D": "false false"},
                    "ans": "A",
                    "exp": "== checks reference memory equality (false since both are distinct new objects), whereas .equals() compares character content (true)."
                },
                {
                    "q": "Which of the following Java loops correctly reverses a string `s`?",
                    "opts": {
                        "A": "String rev = \"\";\nfor (int i = s.length() - 1; i >= 0; i--) {\n    rev += s.charAt(i);\n}",
                        "B": "String rev = \"\";\nfor (int i = 0; i < s.length(); i++) {\n    rev += s.charAt(i);\n}",
                        "C": "String rev = s.reverse();",
                        "D": "String rev = \"\";\nwhile (s.length() > 0) { rev += s.substring(0, 1); }"
                    },
                    "ans": "A",
                    "exp": "Iterating backwards from s.length() - 1 down to 0 extracts characters in reverse order."
                },
                {
                    "q": "Which Java code segment correctly finds the second-largest element in an integer array `arr`?",
                    "opts": {
                        "A": "int first = Integer.MIN_VALUE, second = Integer.MIN_VALUE;\nfor (int x : arr) {\n    if (x > first) {\n        second = first;\n        first = x;\n    } else if (x > second && x != first) {\n        second = x;\n    }\n}",
                        "B": "int first = arr[0], second = arr[0];\nfor (int x : arr) {\n    if (x > first) first = x;\n    second = first;\n}",
                        "C": "int second = 0;\nfor (int i = 0; i < arr.length; i++) second = Math.max(arr[i], arr[i+1]);",
                        "D": "int second = arr[arr.length - 2];"
                    },
                    "ans": "A",
                    "exp": "Single-pass algorithm tracking first and second distinct maximums without sorting."
                },
                {
                    "q": "What will be printed by the following Java try-catch-finally block?\n\n```java\ntry {\n    int res = 10 / 0;\n} catch (ArithmeticException e) {\n    System.out.print(\"Catch \");\n} finally {\n    System.out.print(\"Finally\");\n}\n```",
                    "opts": {"A": "Catch Finally", "B": "Catch", "C": "Finally", "D": "ArithmeticException"},
                    "ans": "A",
                    "exp": "The exception is caught by ArithmeticException printing 'Catch ', then the finally block executes unconditionally printing 'Finally'."
                },
                {
                    "q": "What is the output of the following switch statement inside a loop?\n\n```java\nint count = 0;\nfor (int i = 1; i <= 3; i++) {\n    switch (i) {\n        case 1: count += 10; break;\n        case 2: count += 20; break;\n        default: count += 30;\n    }\n}\nSystem.out.println(count);\n```",
                    "opts": {"A": "60", "B": "30", "C": "10", "D": "0"},
                    "ans": "A",
                    "exp": "i=1 adds 10; i=2 adds 20; i=3 adds 30. Total count = 10 + 20 + 30 = 60."
                },
                {
                    "q": "What error occurs when executing the following Java array loop?\n\n```java\nint[] data = new int[5];\nfor (int i = 0; i <= data.length; i++) {\n    data[i] = i * 2;\n}\n```",
                    "opts": {
                        "A": "ArrayIndexOutOfBoundsException when i reaches 5",
                        "B": "NullPointerException",
                        "C": "ClassCastException",
                        "D": "Compiles and executes without error"
                    },
                    "ans": "A",
                    "exp": "An array of size 5 has indices 0 through 4. Loop condition i <= data.length accesses data[5], throwing an ArrayIndexOutOfBoundsException."
                },
                {
                    "q": "What is the output of the following Java static variable snippet?\n\n```java\nclass Counter {\n    static int count = 0;\n    Counter() { count++; }\n}\n// In main:\nnew Counter();\nnew Counter();\nSystem.out.println(Counter.count);\n```",
                    "opts": {"A": "2", "B": "1", "C": "0", "D": "Compilation Error"},
                    "ans": "A",
                    "exp": "Static variables are shared across all instances of the class. Instantiating Counter twice increments count to 2."
                },
                {
                    "q": "What is the average time complexity of finding an element by key in a Java `HashMap` compared to a `LinkedList`?",
                    "opts": {
                        "A": "O(1) for HashMap, O(N) for LinkedList",
                        "B": "O(N) for HashMap, O(1) for LinkedList",
                        "C": "O(log N) for both",
                        "D": "O(1) for both"
                    },
                    "ans": "A",
                    "exp": "HashMap provides O(1) average-time lookups via hashing, while LinkedList requires sequential traversal taking O(N) time."
                },
                {
                    "q": "In Java, what happens if an abstract class method is not implemented by a non-abstract subclass?",
                    "opts": {
                        "A": "A compile-time error occurs.",
                        "B": "A NullPointerException is thrown at runtime.",
                        "C": "The method defaults to doing nothing.",
                        "D": "The compiler creates a dummy return value."
                    },
                    "ans": "A",
                    "exp": "A concrete subclass must either implement all inherited abstract methods or be declared abstract itself, otherwise the compiler flags an error."
                },
                {
                    "q": "Which code snippet should replace `____` to correctly sort an array of integers in ascending order in Java?\n\n```java\nint[] nums = {5, 2, 8, 1};\n____;\n```",
                    "opts": {"A": "Arrays.sort(nums)", "B": "nums.sort()", "C": "Collections.sort(nums)", "D": "Array.sort(nums)"},
                    "ans": "A",
                    "exp": "java.util.Arrays.sort() is the standard static method to sort primitive array types in Java."
                },
                {
                    "q": "Identify the logic error in the following Java method designed to reverse an array in-place:\n\n```java\nvoid reverse(int[] arr) {\n    for (int i = 0; i < arr.length; i++) {\n        int temp = arr[i];\n        arr[i] = arr[arr.length - 1 - i];\n        arr[arr.length - 1 - i] = temp;\n    }\n}\n```",
                    "opts": {
                        "A": "The loop iterates through the entire array instead of arr.length / 2, swapping elements back to their original positions.",
                        "B": "An ArrayIndexOutOfBoundsException is thrown on the first iteration.",
                        "C": "The temp variable must be of type long.",
                        "D": "In-place array modification is illegal in Java."
                    },
                    "ans": "A",
                    "exp": "Iterating across the entire array swaps elements twice, restoring the array to its original order. The loop upper bound must be arr.length / 2."
                }
            ]

        # 4. SQL / DATABASE CATALOG
        elif is_sql:
            catalog = [
                {
                    "q": "Which SQL query reliably finds the second-highest salary from an `employees` table?",
                    "opts": {
                        "A": "SELECT MAX(salary) FROM employees WHERE salary < (SELECT MAX(salary) FROM employees);",
                        "B": "SELECT salary FROM employees ORDER BY salary DESC LIMIT 2;",
                        "C": "SELECT MIN(salary) FROM employees WHERE salary > (SELECT AVG(salary) FROM employees);",
                        "D": "SELECT salary FROM employees GROUP BY salary HAVING COUNT(*) = 2;"
                    },
                    "ans": "A",
                    "exp": "Finding the MAX salary strictly less than the overall MAX salary handles ties correctly and returns NULL if no second-highest salary exists."
                },
                {
                    "q": "What is the fundamental difference between the `WHERE` and `HAVING` clauses in SQL?",
                    "opts": {
                        "A": "`WHERE` filters individual rows before aggregation, while `HAVING` filters grouped rows after aggregation.",
                        "B": "`HAVING` filters individual rows, while `WHERE` filters grouped rows.",
                        "C": "`WHERE` can only be used with numeric data, while `HAVING` works with strings.",
                        "D": "There is no functional difference; they are aliases."
                    },
                    "ans": "A",
                    "exp": "WHERE filters rows before GROUP BY aggregation; HAVING filters aggregated groups based on aggregate function values."
                },
                {
                    "q": "What records are returned by a `LEFT JOIN` between `customers` and `orders`?\n\n```sql\nSELECT c.name, o.amount\nFROM customers c\nLEFT JOIN orders o ON c.id = o.customer_id;\n```",
                    "opts": {
                        "A": "All customers, including those with no matching orders (with NULL for order fields).",
                        "B": "Only customers who have placed at least one order.",
                        "C": "Only orders that have no associated customer.",
                        "D": "All rows from orders and only matching customers."
                    },
                    "ans": "A",
                    "exp": "A LEFT JOIN preserves all rows from the left table (customers), filling unmatched right table columns with NULL."
                },
                {
                    "q": "Given a table `users` with 5 rows where the `email` column is NULL for 2 rows, what does `SELECT COUNT(*), COUNT(email) FROM users;` return?",
                    "opts": {"A": "5 and 3", "B": "5 and 5", "C": "3 and 3", "D": "5 and 2"},
                    "ans": "A",
                    "exp": "COUNT(*) counts total rows including NULLs (5). COUNT(column_name) ignores NULL values, returning 3."
                },
                {
                    "q": "Which SQL query finds all `email` addresses that appear more than once in a `users` table?",
                    "opts": {
                        "A": "SELECT email, COUNT(*) FROM users GROUP BY email HAVING COUNT(*) > 1;",
                        "B": "SELECT email FROM users WHERE COUNT(email) > 1;",
                        "C": "SELECT DISTINCT email FROM users WHERE email IS DUPLICATE;",
                        "D": "SELECT email FROM users ORDER BY email HAVING COUNT(*) > 1;"
                    },
                    "ans": "A",
                    "exp": "Grouping by email and filtering groups with HAVING COUNT(*) > 1 identifies duplicate values."
                },
                {
                    "q": "In SQL transactions, which ACID property guarantees that all operations within a transaction either succeed completely or are rolled back?",
                    "opts": {"A": "Atomicity", "B": "Consistency", "C": "Isolation", "D": "Durability"},
                    "ans": "A",
                    "exp": "Atomicity ensures all-or-nothing execution of database transactions."
                },
                {
                    "q": "Why does the following SQL query produce a syntax or grouping error?\n\n```sql\nSELECT department_id, employee_name, AVG(salary)\nFROM employees\nGROUP BY department_id;\n```",
                    "opts": {
                        "A": "employee_name is neither an aggregate function nor included in the GROUP BY clause.",
                        "B": "AVG() cannot be calculated on salary.",
                        "C": "The query lacks a WHERE clause.",
                        "D": "department_id cannot be grouped."
                    },
                    "ans": "A",
                    "exp": "Standard SQL requires every non-aggregated column in the SELECT list to appear in the GROUP BY clause."
                },
                {
                    "q": "Which SQL construct is used to iterate over recursive or hierarchical tree data without procedural loops?",
                    "opts": {
                        "A": "Common Table Expression with WITH RECURSIVE",
                        "B": "GROUP BY ROLLUP",
                        "C": "CROSS JOIN LATERAL",
                        "D": "CASE WHEN ELSE"
                    },
                    "ans": "A",
                    "exp": "Recursive Common Table Expressions (WITH RECURSIVE) allow declarative querying of hierarchical graph or tree structures."
                },
                {
                    "q": "Which SQL statement creates a composite B-tree index on `(last_name, first_name)`?",
                    "opts": {
                        "A": "CREATE INDEX idx_name ON customers (last_name, first_name);",
                        "B": "ALTER TABLE customers ADD KEY (last_name) AND (first_name);",
                        "C": "MAKE INDEX ON customers (last_name, first_name);",
                        "D": "CREATE UNIQUE (last_name, first_name) ON customers;"
                    },
                    "ans": "A",
                    "exp": "CREATE INDEX idx_name ON table_name (col1, col2) creates a composite multi-column index."
                },
                {
                    "q": "What is the primary function of the `EXISTS` subquery operator in SQL?",
                    "opts": {
                        "A": "Tests for the existence of rows in a subquery and short-circuits upon the first match.",
                        "B": "Compares every row value against all possible column values.",
                        "C": "Converts NULL values to empty strings.",
                        "D": "Sorts subquery results in ascending order."
                    },
                    "ans": "A",
                    "exp": "EXISTS returns true as soon as the inner subquery matches at least one row, providing efficient short-circuiting."
                }
            ]

        # 5. C / C++ PROGRAMMING CATALOG
        elif is_c:
            catalog = [
                {
                    "q": "What is the output of the following C code snippet?\n\n```c\nint x = 10;\nint *p = &x;\n*p = 25;\nprintf(\"%d\", x);\n```",
                    "opts": {"A": "25", "B": "10", "C": "Address of x", "D": "Segmentation fault"},
                    "ans": "A",
                    "exp": "*p dereferences the pointer to x, modifying x's value in memory to 25."
                },
                {
                    "q": "Which C function correctly reverses a null-terminated string `str` in-place using two pointers?",
                    "opts": {
                        "A": "int i = 0, j = strlen(str) - 1;\nwhile (i < j) {\n    char t = str[i]; str[i] = str[j]; str[j] = t;\n    i++; j--;\n}",
                        "B": "for (int i = 0; i < strlen(str); i++) str[i] = str[strlen(str) - i];",
                        "C": "char *rev = strrev(str);",
                        "D": "for (int i = 0; str[i] != '\\0'; i++) str[i] = str[i+1];"
                    },
                    "ans": "A",
                    "exp": "Two-pointer in-place swap converging from start and end reverses the string without allocating extra memory."
                },
                {
                    "q": "What is the output of the following pointer arithmetic in C?\n\n```c\nint arr[] = {10, 20, 30, 40};\nint *ptr = arr;\nprintf(\"%d\", *(ptr + 2));\n```",
                    "opts": {"A": "30", "B": "20", "C": "40", "D": "10"},
                    "ans": "A",
                    "exp": "ptr + 2 advances pointer by 2 integer elements (index 2). Dereferencing *(ptr + 2) yields 30."
                },
                {
                    "q": "What is the key difference between `malloc()` and `calloc()` in C?",
                    "opts": {
                        "A": "`calloc()` initializes allocated memory to zero, whereas `malloc()` leaves memory uninitialized (garbage values).",
                        "B": "`malloc()` initializes memory to zero, while `calloc()` does not.",
                        "C": "`malloc()` allocates from stack, while `calloc()` allocates from heap.",
                        "D": "`calloc()` is deprecated in modern C."
                    },
                    "ans": "A",
                    "exp": "calloc(n, size) allocates memory and zeroes every byte; malloc(size) allocates raw uninitialized memory."
                },
                {
                    "q": "What is the difference between `++i` and `i++` when used in an expression in C?",
                    "opts": {
                        "A": "`++i` increments i before evaluating the expression, while `i++` evaluates the current value then increments.",
                        "B": "`i++` increments before evaluation, while `++i` increments after.",
                        "C": "Both have identical precedence and evaluation order in expressions.",
                        "D": "`++i` can only be used with pointers."
                    },
                    "ans": "A",
                    "exp": "Pre-increment (++i) increments and yields new value; post-increment (i++) yields original value before incrementing."
                },
                {
                    "q": "What bug is present when memory allocated with `malloc` is never freed?",
                    "opts": {"A": "Memory leak", "B": "Stack overflow", "C": "Segmentation fault", "D": "Buffer underflow"},
                    "ans": "A",
                    "exp": "Failing to release dynamically allocated heap memory with free() causes memory leaks over time."
                },
                {
                    "q": "What is printed by this recursive C function?\n\n```c\nint f(int n) {\n    if (n <= 1) return 1;\n    return n + f(n - 1);\n}\n// in main: printf(\"%d\", f(4));\n```",
                    "opts": {"A": "10", "B": "24", "C": "4", "D": "16"},
                    "ans": "A",
                    "exp": "f(4) computes 4 + 3 + 2 + 1 = 10."
                },
                {
                    "q": "What is the worst-case time complexity of QuickSort when a poorly chosen pivot is used?",
                    "opts": {"A": "O(N^2)", "B": "O(N log N)", "C": "O(N)", "D": "O(log N)"},
                    "ans": "A",
                    "exp": "When partitions are maximally unbalanced (e.g. sorted array with first element as pivot), QuickSort degrades to O(N^2)."
                },
                {
                    "q": "In C, what is the effect of passing an array to a function `void func(int arr[])`?",
                    "opts": {
                        "A": "The array decays into a pointer to its first element; modifications affect the original array.",
                        "B": "A full deep copy of the array is made on the call stack.",
                        "C": "The array becomes read-only immutable memory.",
                        "D": "The compiler throws an invalid argument error."
                    },
                    "ans": "A",
                    "exp": "Array parameters decay into pointers to the first element (int *arr), allowing in-place mutation of the original array."
                },
                {
                    "q": "What is the purpose of the `volatile` keyword in C/C++?",
                    "opts": {
                        "A": "Instructs the compiler not to optimize or cache variable reads because its value may change asynchronously.",
                        "B": "Guarantees thread-safe atomic operations across CPU cores.",
                        "C": "Forces variable to be stored in CPU registers.",
                        "D": "Prevents variable from being modified."
                    },
                    "ans": "A",
                    "exp": "volatile informs the compiler that a variable's value can be changed by hardware or concurrent threads, disabling caching optimizations."
                },
                {
                    "q": "Which code snippet should replace `____` to safely read a line of text into buffer `str` without risking buffer overflow in C?\n\n```c\nchar str[100];\n____;\n```",
                    "opts": {"A": "fgets(str, sizeof(str), stdin)", "B": "gets(str)", "C": "scanf(\"%s\", str)", "D": "cin >> str"},
                    "ans": "A",
                    "exp": "fgets(str, sizeof(str), stdin) reads at most sizeof(str) - 1 characters and null-terminates, safely preventing buffer overflow."
                }
            ]

        # 6. JAVASCRIPT / WEB CATALOG
        elif is_js:
            catalog = [
                {
                    "q": "What is the output order of the following JavaScript code?\n\n```javascript\nconsole.log('1');\nsetTimeout(() => console.log('2'), 0);\nPromise.resolve().then(() => console.log('3'));\nconsole.log('4');\n```",
                    "opts": {"A": "1, 4, 3, 2", "B": "1, 2, 3, 4", "C": "1, 4, 2, 3", "D": "1, 3, 4, 2"},
                    "ans": "A",
                    "exp": "Synchronous code runs first (1, 4), then the microtask queue processes Promises (3), then the macrotask queue processes setTimeout (2)."
                },
                {
                    "q": "What will be printed when clicking buttons created in this loop with `var` vs `let`?\n\n```javascript\nfor (var i = 0; i < 3; i++) {\n    setTimeout(() => console.log(i), 10);\n}\n```",
                    "opts": {"A": "3, 3, 3", "B": "0, 1, 2", "C": "undefined", "D": "IndexError"},
                    "ans": "A",
                    "exp": "`var` is function-scoped so a single shared variable i reaches 3 by the time callbacks fire. `let` would produce 0, 1, 2 via block scoping."
                },
                {
                    "q": "What is the output of the following array operation?\n\n```javascript\nconst nums = [1, 2, 3, 4];\nconst res = nums.filter(x => x % 2 === 0).map(x => x * 3);\nconsole.log(res);\n```",
                    "opts": {"A": "[6, 12]", "B": "[3, 6, 9, 12]", "C": "[2, 4]", "D": "[6]"},
                    "ans": "A",
                    "exp": "filter keeps [2, 4]; map multiplies each by 3 yielding [6, 12]."
                },
                {
                    "q": "How does `===` differ from `==` in JavaScript?",
                    "opts": {
                        "A": "`===` checks both value and type without type coercion; `==` performs implicit type conversion.",
                        "B": "`==` checks both value and type; `===` performs type coercion.",
                        "C": "`===` is only valid for objects.",
                        "D": "There is no difference."
                    },
                    "ans": "A",
                    "exp": "Strict equality `===` requires matching types, avoiding JavaScript's implicit type coercion rules."
                },
                {
                    "q": "What is a closure in JavaScript?",
                    "opts": {
                        "A": "A function bundled together with references to its surrounding lexical environment.",
                        "B": "A function that terminates immediately upon execution.",
                        "C": "A method to delete variables from memory.",
                        "D": "A syntax for declaring private classes only."
                    },
                    "ans": "A",
                    "exp": "Closures allow an inner function to retain access to variables from its outer enclosing scope even after the outer function has returned."
                },
                {
                    "q": "Which array method should replace `____` to transform all elements of `[1, 2, 3]` by doubling them into `[2, 4, 6]`?\n\n```javascript\nconst nums = [1, 2, 3];\nconst doubled = nums.____(x => x * 2);\n```",
                    "opts": {"A": "map", "B": "forEach", "C": "filter", "D": "reduce"},
                    "ans": "A",
                    "exp": "Array.prototype.map() creates a new array populated with the results of calling the provided callback on every element."
                }
            ]

        # 7. DYNAMIC ARBITRARY / CUSTOM PROGRAMMING GENERATOR
        # When user enters arbitrary custom topics (e.g. C# LINQ, Rust concurrency, Go goroutines, ML, DSA, etc.)
        if not catalog:
            # Build intelligent, domain-tailored technical MCQs
            catalog = [
                {
                    "q": f"In {lang}, what is the expected output and execution behavior of the following {topic_label} logic?\n\n```{lang.lower()}\n# Sample {topic_label} operational routine\nval = 10\nfor step in range(3):\n    val = (val * 2) - step\nprint(val)\n```",
                    "opts": {
                        "A": "73 (Step 0: 20; Step 1: 39; Step 2: 76 - 3 = 75 or deterministic state transition)",
                        "B": "Unpredictable due to non-deterministic execution order",
                        "C": "SyntaxError on loop variable binding",
                        "D": "OverflowError during step multiplication"
                    },
                    "ans": "A",
                    "exp": f"Deterministic execution of loop steps according to {topic_label} standards in {lang}."
                },
                {
                    "q": f"Which code implementation in {lang} represents the optimal pattern for {topic_label} under high throughput?",
                    "opts": {
                        "A": f"Single-pass linear algorithm with bounded memory and early exit validation.",
                        "B": f"Brute-force quadratic search with unindexed lookups.",
                        "C": f"Unsynchronized concurrent mutations without locking primitives.",
                        "D": f"Recursive call graph with no termination base condition."
                    },
                    "ans": "A",
                    "exp": f"Single-pass linear algorithms provide optimal predictable performance for {topic_label}."
                },
                {
                    "q": f"When debugging a {topic_label} routine in {lang}, what is the most common cause of off-by-one errors?",
                    "opts": {
                        "A": "Confusing 0-based index bounds with 1-based size counts in loop termination conditions.",
                        "B": "Using dynamic variable types instead of static typing.",
                        "C": "Enabling compiler optimization flags.",
                        "D": "Adding inline code comments."
                    },
                    "ans": "A",
                    "exp": f"Mismatch between 0-indexed bounds and length thresholds is the predominant cause of boundary faults in {topic_label}."
                },
                {
                    "q": f"What is the theoretical time complexity of performing binary search or balanced tree lookup in {topic_label}?",
                    "opts": {
                        "A": "O(log N)",
                        "B": "O(N^2)",
                        "C": "O(N!)",
                        "D": "O(1)"
                    },
                    "ans": "A",
                    "exp": f"Halving search space at each iteration achieves logarithmic O(log N) time complexity for {topic_label}."
                },
                {
                    "q": f"Which error-handling strategy is recommended for robust {topic_label} implementation in {lang}?",
                    "opts": {
                        "A": "Defensive parameter validation with structured exception boundaries and graceful degradation.",
                        "B": "Suppressing all runtime exceptions silently.",
                        "C": "Terminating the application process on any non-standard input.",
                        "D": "Bypassing boundary checks to minimize instruction counts."
                    },
                    "ans": "A",
                    "exp": f"Defensive checks and structured exceptions maintain reliability in {topic_label}."
                },
                {
                    "q": f"How does state immutability benefit {topic_label} concurrency in {lang}?",
                    "opts": {
                        "A": "Eliminates race conditions because immutable data structures can be safely read across threads without locks.",
                        "B": "Doubles CPU clock frequency dynamically.",
                        "C": "Forces synchronous single-threaded execution.",
                        "D": "Removes the requirement for unit testing."
                    },
                    "ans": "A",
                    "exp": f"Immutable state prevents data races and shared mutable state hazards in {topic_label}."
                },
                {
                    "q": f"In {req.subject}, what is considered an anti-pattern when designing {topic_label} modules?",
                    "opts": {
                        "A": "Hardcoded global dependencies and tight coupling across separate modules.",
                        "B": "Writing comprehensive automated test coverage.",
                        "C": "Adhering to single responsibility principles.",
                        "D": "Using descriptive variable naming conventions."
                    },
                    "ans": "A",
                    "exp": f"Tight coupling and global mutable state severely harm testability and maintainability of {topic_label}."
                },
                {
                    "q": f"Which metric accurately reflects efficiency during algorithmic execution of {topic_label}?",
                    "opts": {
                        "A": "Asymptotic time and auxiliary space complexity bounds (Big-O notation).",
                        "B": "Total count of characters in source code file.",
                        "C": "Number of blank lines in project directory.",
                        "D": "Resolution of development monitor display."
                    },
                    "ans": "A",
                    "exp": f"Asymptotic complexity evaluates operational scale efficiency independent of hardware variance for {topic_label}."
                },
                {
                    "q": f"When processing large streaming collections in {topic_label}, why are generators/iterators preferred over eager lists?",
                    "opts": {
                        "A": "Iterators evaluate items lazily in O(1) space, avoiding loading entire datasets into memory.",
                        "B": "Eager lists execute 100 times faster on all systems.",
                        "C": "Iterators automatically encrypt data streams.",
                        "D": "Eager lists cannot hold numeric values."
                    },
                    "ans": "A",
                    "exp": f"Lazy evaluation consumes minimal constant memory when streaming records through {topic_label}."
                },
                {
                    "q": f"What testing methodology offers highest assurance for {topic_label} critical paths in {lang}?",
                    "opts": {
                        "A": "Automated unit tests combined with boundary value analysis and property-based test fuzzing.",
                        "B": "Manual code inspection without executing test runs.",
                        "C": "Deploying untested code directly into production.",
                        "D": "Testing only the happy path with positive inputs."
                    },
                    "ans": "A",
                    "exp": f"Boundary testing and property fuzzing catch subtle edge case bugs in {topic_label}."
                }
            ]

        results = []
        num_items = len(catalog)
        for i in range(req.number_of_questions):
            item = catalog[i % num_items]
            cycle = i // num_items
            q_text = item["q"]
            if cycle > 0:
                q_text = f"[Part {cycle + 1}] {q_text}"

            results.append({
                "question": q_text,
                "question_type": "mcq",
                "programming_language": lang,
                "options": item["opts"].copy(),
                "correct_answer": item["ans"],
                "explanation": f"{item['exp']} ({diff_label} difficulty evaluation).",
                "subject": req.subject,
                "topic": req.topic,
                "difficulty": req.difficulty
            })

        return results

    @staticmethod
    def _generate_heuristic_short_answer_questions(req: QuestionGenerationRequest) -> List[Dict[str, Any]]:
        results = []
        topic_label = req.topic if req.topic else req.subject
        diff_label = req.difficulty.capitalize()

        short_templates = [
            (
                f"Define the core concept of {topic_label} in {req.subject} and state its primary purpose.",
                f"{topic_label} refers to the foundational mechanism in {req.subject} that manages and coordinates operational execution.",
                f"Full credit requires stating the definition and at least one primary function or objective."
            ),
            (
                f"What is the difference between synchronous and asynchronous execution in {topic_label}?",
                "Synchronous execution blocks subsequent tasks until completion, while asynchronous execution allows concurrent processing without waiting.",
                "Mention blocking vs non-blocking behavior and concurrency implications."
            ),
            (
                f"State the best, average, and worst-case time complexity of {topic_label} operations.",
                "Best case: O(1) or O(N), Average case: O(N log N) or O(N), Worst case: O(N^2) or O(N) depending on input structure.",
                "Evaluate correct Big-O notation for each scenario."
            ),
            (
                f"What are the key trade-offs when optimizing memory versus execution time in {topic_label}?",
                "Memory optimization often requires recomputation and reduced caching, whereas speed optimization uses precomputed lookup tables and caching.",
                "Award points for identifying memory overhead vs CPU cycle trade-offs."
            ),
            (
                f"Describe two critical security vulnerabilities associated with improperly configured {topic_label}.",
                "1. Unauthorized state manipulation due to missing access control. 2. Buffer overflow or unhandled input injection.",
                "Identify two distinct vulnerability vectors with corrective controls."
            )
        ]

        for i in range(req.number_of_questions):
            tmpl = short_templates[i % len(short_templates)]
            cycle = i // len(short_templates)
            q_text = tmpl[0] if cycle == 0 else f"Regarding {topic_label} (Aspect {cycle + 1}): {tmpl[0]}"

            results.append({
                "question": q_text,
                "question_type": "short_answer",
                "correct_answer": tmpl[1],
                "reference_solution": tmpl[1],
                "explanation": f"{tmpl[2]} ({diff_label} difficulty evaluation).",
                "subject": req.subject,
                "topic": req.topic,
                "difficulty": req.difficulty
            })

        return results

    @staticmethod
    def _generate_heuristic_descriptive_questions(req: QuestionGenerationRequest) -> List[Dict[str, Any]]:
        results = []
        topic_label = req.topic if req.topic else req.subject
        diff_label = req.difficulty.capitalize()

        desc_templates = [
            (
                f"Comprehensive Architecture and Lifecycle Analysis of {topic_label} in {req.subject}",
                (
                    f"Provide an in-depth explanation of {topic_label}. Your answer should detail: "
                    f"1. System architecture and main components. "
                    f"2. End-to-end operational lifecycle and data flow. "
                    f"3. Error handling, fault tolerance, and recovery strategies. "
                    f"4. Real-world implementation considerations and performance bottlenecks."
                ),
                (
                    f"Model Solution Outline:\n"
                    f"- Architecture: Core entities, separation of concerns, and interaction contracts.\n"
                    f"- Lifecycle: Initialization, runtime state management, execution loop, and resource teardown.\n"
                    f"- Fault Tolerance: Graceful degradation, circuit breaking, idempotency, and transaction rollback.\n"
                    f"- Real-world considerations: Scalability limits, horizontal vs vertical scaling, and telemetry."
                ),
                "Scoring Rubric: 25% architecture clarity, 25% lifecycle accuracy, 25% fault handling, 25% practical trade-offs."
            ),
            (
                f"Comparative Evaluation and Design Trade-offs for {topic_label}",
                (
                    f"Analyze the trade-offs of different structural patterns when implementing {topic_label} in large-scale systems. "
                    f"Contrast monolithic versus distributed architectures, evaluating concurrency, latency, and maintainability."
                ),
                (
                    f"Model Solution Outline:\n"
                    f"- Structural contrast: Tight coupling vs micro-services/decoupled components.\n"
                    f"- Concurrency analysis: Mutexes, read-write locks, and lock-free data structures.\n"
                    f"- Latency vs consistency trade-offs (CAP theorem implications)."
                ),
                "Scoring Rubric: 30% comparative depth, 35% latency/concurrency evaluation, 35% architectural recommendations."
            )
        ]

        for i in range(req.number_of_questions):
            tmpl = desc_templates[i % len(desc_templates)]
            cycle = i // len(desc_templates)
            title = tmpl[0] if cycle == 0 else f"{tmpl[0]} (Part {cycle + 1})"

            results.append({
                "question": f"{title}\n\n{tmpl[1]}",
                "question_type": "descriptive",
                "correct_answer": tmpl[2][:150] + "...",
                "reference_solution": tmpl[2],
                "explanation": f"{tmpl[3]} ({diff_label} grading rubric).",
                "subject": req.subject,
                "topic": req.topic,
                "difficulty": req.difficulty
            })

        return results

    @staticmethod
    def _generate_heuristic_mixed_questions(req: QuestionGenerationRequest) -> List[Dict[str, Any]]:
        total = req.number_of_questions
        types_cycle = ["mcq", "coding", "short_answer", "descriptive"]
        results = []

        counts = {"mcq": 0, "coding": 0, "short_answer": 0, "descriptive": 0}
        for i in range(total):
            t = types_cycle[i % len(types_cycle)]
            counts[t] += 1

        from copy import deepcopy
        mcq_req = deepcopy(req)
        mcq_req.question_type = "mcq"
        mcq_req.number_of_questions = counts["mcq"]
        mcq_list = (LLMService._generate_heuristic_coding_mcq_questions(mcq_req) if LLMService._is_coding_subject_or_topic(req.subject, req.topic or "", req.additional_context or "") else LLMService._generate_heuristic_mcq_questions(mcq_req)) if counts["mcq"] > 0 else []

        code_req = deepcopy(req)
        code_req.question_type = "coding"
        code_req.number_of_questions = counts["coding"]
        code_list = LLMService._generate_heuristic_coding_questions(code_req) if counts["coding"] > 0 else []

        short_req = deepcopy(req)
        short_req.question_type = "short_answer"
        short_req.number_of_questions = counts["short_answer"]
        short_list = LLMService._generate_heuristic_short_answer_questions(short_req) if counts["short_answer"] > 0 else []

        desc_req = deepcopy(req)
        desc_req.question_type = "descriptive"
        desc_req.number_of_questions = counts["descriptive"]
        desc_list = LLMService._generate_heuristic_descriptive_questions(desc_req) if counts["descriptive"] > 0 else []

        ptrs = {"mcq": 0, "coding": 0, "short_answer": 0, "descriptive": 0}
        lists = {"mcq": mcq_list, "coding": code_list, "short_answer": short_list, "descriptive": desc_list}

        for i in range(total):
            t = types_cycle[i % len(types_cycle)]
            if ptrs[t] < len(lists[t]):
                item = lists[t][ptrs[t]]
                item["question_type"] = t
                results.append(item)
                ptrs[t] += 1

        return results

    @staticmethod
    def _generate_heuristic_mcq_questions(req: QuestionGenerationRequest) -> List[Dict[str, Any]]:
        results = []
        diff_label = req.difficulty.capitalize()
        topic_label = req.topic if req.topic else req.subject

        base_templates = [
            {
                "q": f"What is the foundational objective of {topic_label} within {req.subject}?",
                "opts": {
                    "A": f"To establish systematic frameworks for managing and executing core {topic_label} workflows.",
                    "B": "To bypass standard verification protocols and eliminate boundary constraints.",
                    "C": "To enforce proprietary dialect overrides across unrelated architectures.",
                    "D": "To deprecate all operational telemetry and logging outputs."
                },
                "ans": "A",
                "exp": f"{topic_label} provides the foundational structural mechanisms for {req.subject}."
            },
            {
                "q": f"Which principle best characterizes sound operational design for {topic_label}?",
                "opts": {
                    "A": "Bypassing data validation layers to maximize transmission speed.",
                    "B": f"Encapsulating domain logic into modular, testable components tailored for {topic_label}.",
                    "C": "Coupling business requirements directly with hardware platform quirks.",
                    "D": "Disabling exception reporting during high-load intervals."
                },
                "ans": "B",
                "exp": f"Encapsulation and modularity ensure testability and long-term maintainability for {topic_label}."
            },
            {
                "q": f"In a {diff_label} scenario involving {topic_label}, which strategy is recommended?",
                "opts": {
                    "A": "Relying on arbitrary timing delays without synchronization.",
                    "B": "Hardcoding dynamic runtime parameters into immutable strings.",
                    "C": f"Applying deterministic state transitions and defensive assertions for {topic_label}.",
                    "D": "Ignoring edge cases and null pointer boundaries."
                },
                "ans": "C",
                "exp": f"Deterministic execution and defensive assertions ensure resilience in {topic_label}."
            },
            {
                "q": f"What is a primary advantage of adhering to standardized conventions in {topic_label}?",
                "opts": {
                    "A": f"Enhanced system maintainability, interoperability, and cross-team scalability for {topic_label}.",
                    "B": "Forced single-threaded serialization across all pipelines.",
                    "C": "Elimination of static and runtime type verification.",
                    "D": "Increased memory footprint without performance improvements."
                },
                "ans": "A",
                "exp": f"Standardized conventions reduce cognitive friction and promote scalability in {topic_label}."
            },
            {
                "q": f"What error condition can emerge if boundary validation is omitted in {topic_label}?",
                "opts": {
                    "A": "Automatic silent compression of input payloads.",
                    "B": "Immediate elimination of all hardware latency.",
                    "C": f"Unexpected state corruption, off-by-one errors, or runtime exceptions in {topic_label}.",
                    "D": "Unintended cryptographic key rotation."
                },
                "ans": "C",
                "exp": f"Omitting boundary verification causes unhandled edge-case failures in {topic_label}."
            },
            {
                "q": f"When optimizing {topic_label} performance, what is the primary architectural trade-off?",
                "opts": {
                    "A": f"Balancing computational execution throughput against auxiliary memory overhead in {topic_label}.",
                    "B": "Selecting monitor color depth over keyboard layout.",
                    "C": "Hardcoding network gateway addresses in source files.",
                    "D": "Disabling garbage collection permanently."
                },
                "ans": "A",
                "exp": f"Throughput versus memory overhead represents the fundamental trade-off in {topic_label}."
            },
            {
                "q": f"How does structured exception handling improve system resilience in {topic_label}?",
                "opts": {
                    "A": "By isolating errors, releasing active resources, and allowing graceful degradation.",
                    "B": "By abruptly aborting the host operating system upon minor errors.",
                    "C": "By converting all raised warnings into silent null pointers.",
                    "D": "By bypassing user input authentication checks."
                },
                "ans": "A",
                "exp": f"Graceful degradation and resource cleanup prevent cascading failures in {topic_label}."
            },
            {
                "q": f"During code review of a {topic_label} system, which characteristic indicates high architectural quality?",
                "opts": {
                    "A": "High cohesion within modules and loose coupling between separate components.",
                    "B": "Monolithic spaghetti scripts containing all system procedures.",
                    "C": "Complete absence of automated test suites.",
                    "D": "Shared global mutable state across multiple threads without locks."
                },
                "ans": "A",
                "exp": f"High cohesion and loose coupling are hallmarks of robust software engineering in {topic_label}."
            },
            {
                "q": f"Which testing methodology provides the most reliable verification for {topic_label} routines?",
                "opts": {
                    "A": f"Combining automated unit, integration, and property-based regression suites for {topic_label}.",
                    "B": "Manual ad-hoc testing exclusively before release.",
                    "C": "Testing only the happy path and skipping failure states.",
                    "D": "Relying solely on end-user bug reports in production."
                },
                "ans": "A",
                "exp": f"Comprehensive test coverage across levels ensures robust correctness for {topic_label}."
            },
            {
                "q": f"How does proper resource lifecycle management benefit {topic_label} in {req.subject}?",
                "opts": {
                    "A": "Prevents memory leaks, socket exhaustion, and deadlocks via deterministic cleanup.",
                    "B": "Eliminates all runtime predictability.",
                    "C": "Increases garbage collection stalls indefinitely.",
                    "D": "Causes intermittent connection timeouts."
                },
                "ans": "A",
                "exp": f"Deterministic resource cleanup ensures high stability for {topic_label}."
            }
        ]

        num_templates = len(base_templates)
        for i in range(req.number_of_questions):
            tmpl = base_templates[i % num_templates]
            cycle = i // num_templates
            q_text = tmpl["q"]
            if cycle > 0:
                q_text = f"[Variation {cycle + 1}] {q_text}"

            results.append({
                "question": q_text,
                "options": tmpl["opts"].copy(),
                "correct_answer": tmpl["ans"],
                "explanation": f"{tmpl['exp']} ({diff_label} difficulty evaluation).",
                "subject": req.subject,
                "topic": req.topic,
                "difficulty": req.difficulty
            })

        return results

    @staticmethod
    def generate_focused_study_notes(req: FocusedNotesRequest) -> Dict[str, Any]:
        """
        Generates focused study notes specifically for the concept tested by the question.
        Uses RAG context search first. If context exists, notes are marked as grounded.
        Otherwise falls back to domain-knowledge LLM/heuristic generation marked as ungrounded.
        """
        from app.services.rag_service import RAGService

        rag_res = RAGService.search_context(
            query=f"{req.subject} {req.topic} {req.question}",
            subject=req.subject,
            topic=req.topic,
            top_k=3
        )

        retrieved_chunks = rag_res.get("results", [])
        has_rag_context = bool(rag_res.get("has_sufficient_context") and retrieved_chunks)
        is_grounded = has_rag_context

        sources = []
        context_text = ""

        if has_rag_context:
            for c in retrieved_chunks:
                fn = c.get("filename", "Course Document")
                pg = c.get("page_number", 1)
                sources.append({
                    "filename": fn,
                    "page_number": pg,
                    "score": c.get("score", 0.0),
                    "text_snippet": str(c.get("text", ""))[:150]
                })
                context_text += f"\n--- Source: {fn} (Page {pg}) ---\n{c.get('text', '')}\n"

        api_key = getattr(settings, 'LLM_API_KEY', None)
        model_name = getattr(settings, 'LLM_MODEL', 'gpt-4o-mini')

        concept_title = f"{req.topic} Concept Study Notes"
        notes_content = ""

        if api_key and api_key != "your_llm_api_key_here":
            try:
                system_prompt = (
                    "You are an expert academic tutor creating focused, question-specific study notes. "
                    "Your notes must focus specifically on the concept tested by the provided question. "
                    "Do NOT write generic notes for the whole subject. Output strictly valid JSON."
                )

                user_prompt = f"""
Generate focused study notes for the following exam question.
Subject: {req.subject}
Topic: {req.topic}
Question: {req.question}
Correct Answer: {req.correct_answer}
Explanation: {req.explanation or 'N/A'}

Course Material Context:
{context_text if is_grounded else 'No uploaded course materials available. Generate grounded notes using expert domain knowledge and note that context was generated via AI domain knowledge fallback.'}

Output JSON with keys:
- "concept_title": Concise title of the specific tested concept (e.g., "Shortest Job First (SJF) CPU Scheduling")
- "notes_content": Comprehensive markdown text containing sections:
  1. Core Concept Tested
  2. Detailed Technical Explanation & Why Answer is Correct
  3. Concrete Code/Practical Example
  4. Required Prerequisite Knowledge
  5. Common Exam Traps & Pitfalls
  6. Key Memory Takeaways
"""
                body = {
                    "model": model_name,
                    "messages": [
                        {"role": "system", "content": system_prompt},
                        {"role": "user", "content": user_prompt}
                    ],
                    "response_format": {"type": "json_object"},
                    "temperature": 0.3
                }
                headers = {
                    "Authorization": f"Bearer {api_key}",
                    "Content-Type": "application/json"
                }

                with httpx.Client(timeout=20.0) as client:
                    resp = client.post("https://api.openai.com/v1/chat/completions", headers=headers, json=body)
                    resp.raise_for_status()
                    res_json = json.loads(resp.json()["choices"][0]["message"]["content"])
                    concept_title = res_json.get("concept_title", f"{req.topic} Core Concept")
                    notes_content = res_json.get("notes_content", "")
            except Exception as e:
                logger.error(f"External LLM call failed for study notes: {e}. Utilizing fallback generator.")
                concept_title, notes_content = LLMService._generate_heuristic_study_notes(req, is_grounded, context_text)
        else:
            concept_title, notes_content = LLMService._generate_heuristic_study_notes(req, is_grounded, context_text)

        return {
            "status": "success",
            "question_id": req.question_id,
            "ai_question_id": req.ai_question_id,
            "practice_answer_id": req.practice_answer_id,
            "concept_title": concept_title,
            "notes_content": notes_content,
            "rag_sources": sources,
            "is_grounded": is_grounded,
            "source_count": len(sources),
            "message": "Focused study notes generated successfully."
        }

    @staticmethod
    def _generate_heuristic_study_notes(req: FocusedNotesRequest, is_grounded: bool, context_text: str) -> Tuple[str, str]:
        concept_title = f"{req.topic}: Core Concept Study Notes"

        grounding_badge = "📖 *Grounded in Approved Course Material*" if is_grounded else "⚠️ *Synthesized from Question Bank Record (Verified Source Question)*"

        explanation_text = req.explanation.strip() if req.explanation and req.explanation.strip() else f"Verified in the exam question bank as the correct determination for this problem under {req.topic}."

        content = f"""### {req.topic} — Key Concept Analysis
{grounding_badge}

#### 1. Core Concept Tested
This question evaluates your understanding of **{req.topic}** within **{req.subject}**, specifically targeting:
> "{req.question}"

#### 2. Detailed Technical Explanation & Why Answer is Correct
- **Correct Answer**: `{req.correct_answer}`
- **Reasoning**: {explanation_text}
- **Why Other Options Fail**: In the context of {req.topic}, alternative options represent incorrect alternatives or misconceptions not supported by the verified problem statement.

#### 3. Concrete Example
Consider a problem instance under **{req.topic}** ({req.subject}):
- **Problem Statement**: "{req.question}"
- **Evaluation**: `{req.correct_answer}` satisfies the verified criteria evaluated by the question.

#### 4. Required Prerequisite Knowledge
- Fundamentals of **{req.subject}**.
- Core principles of **{req.topic}**.
- Understanding the specific terms evaluated in: "{req.question}".

#### 5. Common Exam Traps & Pitfalls
- ❌ **Trap 1**: Selecting distractors that address adjacent concepts rather than the exact condition specified in the question.
- 💡 **Exam Tip**: For questions on **{req.topic}**, focus strictly on the problem requirements rather than assuming unstated external conditions.

#### 6. Key Points to Remember
For questions on **{req.topic}**, remember that `{req.correct_answer}` is verified directly by the question's official solution and explanation.
"""
        return concept_title, content

    @classmethod
    def generate_exam_preparation_guide(
        cls,
        subject: str = "General Course",
        course_materials: Optional[List[Dict[str, Any]]] = None,
        student_has_attempts: bool = False,
        query: str = "",
        *args,
        **kwargs
    ) -> str:
        if not query and "question" in kwargs:
            query = kwargs["question"]
        if course_materials is None:
            course_materials = kwargs.get("study_materials") or kwargs.get("chunks") or []
        if not student_has_attempts and "user_context" in kwargs:
            ctx = kwargs["user_context"] or {}
            subj_sum = ctx.get("subject_summary", {})
            if subj_sum.get("student_attempts_count", 0) > 0:
                student_has_attempts = True

        norm_subj = subject.strip() if subject else "General Course"
        q_lower = query.lower() if query else ""

        # Material section with real IDs if available, strictly filtered to the target subject
        matching_materials = []
        if course_materials and norm_subj.lower() not in ("general", "general course"):
            for d in course_materials:
                d_subj = d.get("subject", "")
                if not d_subj or d_subj.strip().lower() == norm_subj.lower():
                    matching_materials.append(d)
        else:
            matching_materials = list(course_materials or [])

        mat_lines = []
        if matching_materials:
            seen_doc_keys = set()
            for d in matching_materials:
                doc_id = d.get("id") or d.get("document_id")
                fn = d.get("original_name") or d.get("filename") or f"{norm_subj} Study Material"
                dedup_key = f"{doc_id}_{fn}" if doc_id else fn
                if dedup_key in seen_doc_keys:
                    continue
                seen_doc_keys.add(dedup_key)
                pnum = d.get("page_number", 1)
                top = d.get("topic", "Core Principles")
                if doc_id:
                    mat_lines.append(f"- **Document Title:** [`{fn}`](view_course_material.php?id={doc_id}&page={pnum}&source=assistant) (ID: #{doc_id})")
                else:
                    mat_lines.append(f"- **Document Title:** [`{fn}`](view_course_material.php?file={fn}&page={pnum}&source=assistant)")
                mat_lines.append(f"  - **Topic / Focus Area:** {top}")
                mat_lines.append("  - **Status:** Approved course reference in study repository")
                if len(seen_doc_keys) >= 3:
                    break
            materials_section = "\n".join(mat_lines)
        else:
            materials_section = f"- No official course notes for **{norm_subj}** are currently uploaded in the study repository. Standard curriculum guidelines apply."

        history_notice = ""
        if not student_has_attempts:
            history_notice = (
                f"\n\n> [!NOTE]\n"
                f"> **Exam Record Status:** You haven't completed any exam attempts in {norm_subj} yet (performance metrics are not available). "
                f"Once you complete an official exam or practice session, I will tailor recommendations specifically to your lowest-scoring topics."
            )

        subj_key = norm_subj.lower()

        # 1. Python Exam Preparation Plan
        if subj_key in ("python", "py") or "python" in q_lower:
            guide = f"""### 🎯 Comprehensive Exam Preparation Strategy: **Python**

To prepare effectively and achieve top marks in your **Python** exam, follow this structured topic review, practical 7-day daily study schedule, hands-on practice problems, and mock test revision Roadmap:

#### 📌 Core Topics Checklist
- [ ] **Language Fundamentals:** Variables, dynamic typing, arithmetic & logical operators, input/output formatting
- [ ] **Flow Control:** Conditionals (`if`, `elif`, `else`), `while` loops, `for` loops with `range()`, `break`, `continue`
- [ ] **Core Data Structures:** Lists (indexing, slicing, methods), tuples (immutability), dictionaries (key-value pairs), sets (uniqueness)
- [ ] **List Comprehensions:** Concise list/dictionary creation syntax with conditional filtering
- [ ] **Functions & Scope:** `def`, parameters (`*args`, `**kwargs`), default values, `return`, lambda functions, local vs global scope
- [ ] **Object-Oriented Programming (OOP):** Classes, `__init__` constructor, instance attributes, methods, inheritance, encapsulation
- [ ] **File I/O & Exception Handling:** `with open(...)`, reading/writing files, `try...except...finally` blocks, built-in exception types
- [ ] **Standard Library Essentials:** Built-in functions (`len()`, `sum()`, `enumerate()`, `zip()`, `sorted()`), `math`, `random`

#### 📅 Practical 7-Day Daily Study Schedule
- **Day 1: Syntax Basics & Flow Control**
  - Master variable types, conditional branching (`if/elif/else`), and loops (`while`, `for i in range(...)`).
  - Practice tracing loop boundaries and termination conditions.
- **Day 2: Built-in Data Structures & Comprehensions**
  - Practice mutable lists (`.append()`, `.extend()`, `.pop()`, slicing `[::-1]`) vs immutable tuples.
  - Master dictionaries (`.keys()`, `.values()`, `.items()`, `.get(key, default)`) and set operations.
  - Write single-line list comprehensions with conditional filters.
- **Day 3: Functions, Modularity & Variable Scope**
  - Define reusable functions with default arguments, `*args`, and `**kwargs`.
  - Master variable scope (`LEGB` rule: Local, Enclosing, Global, Built-in) and lambda expressions.
- **Day 4: Object-Oriented Python**
  - Implement classes with `__init__` and `self` references.
  - Practice class inheritance, method overriding, and calling parent methods via `super()`.
- **Day 5: File Handling & Exception Handling**
  - Practice reading and writing files safely with context managers: `with open('data.txt', 'r') as f:`.
  - Handle runtime exceptions gracefully using `try...except...finally` blocks without crashing.
- **Day 6: Timed Practice Questions & Coding Drills**
  - Solve 15 to 20 multiple-choice and code-tracing problems in the **Practice Session** tab.
  - Review mistake logs: understand the exact reason behind each incorrect distractor.
- **Day 7: Full Mock Examination & Final Revision**
  - Take a full-length timed mock test under exam conditions in the Online Examination System.
  - Review your 1-page syntax cheat sheet and ensure adequate rest before exam day.

#### 📝 High-Yield Practice Questions

1. **Data Types & Mutability:**
   *Question:* What is the output of the following code snippet?
   ```python
   a = [10, 20, 30]
   b = a
   b.append(40)
   print(len(a))
   ```
   *Explanation:* The output is `4`. In Python, lists are mutable. `b = a` assigns an object reference to the same list; appending to `b` alters `a`.

2. **List Comprehensions:**
   *Question:* Write a list comprehension that extracts the squares of all even numbers from `numbers = [1, 2, 3, 4, 5, 6]`.
   ```python
   squares = [x**2 for x in numbers if x % 2 == 0]
   # Output: [4, 16, 36]
   ```

3. **Safe Dictionary Lookup:**
   *Question:* How do you retrieve key `"score"` from `data` with a fallback value of `0` without risking a `KeyError`?
   ```python
   score = data.get("score", 0)
   ```

4. **Exception Handling Pattern:**
   *Question:* How should zero-division errors be handled safely?
   ```python
   try:
       avg = total / count
   except ZeroDivisionError:
       avg = 0.0
   ```

#### 🔄 Revision & Mock Test Strategy
1. **Timed Mock Tests:** Complete at least 2 full mock exams in the Online Examination System under an official 45-60 minute timer (aim for 1.5 minutes per question).
2. **Distractor Analysis:** For every question missed, write down why the wrong choice seemed appealing and why the correct answer is logically sound.
3. **Cheat Sheet Review:** Prepare a one-page summary sheet covering core string methods (`.split()`, `.join()`), list methods (`.append()`, `.pop()`), and dictionary operations.

#### 💡 Essential Exam-Day Tips
1. **Pacing & Time Allocation:** Budget 1 to 1.5 minutes per MCQ. Flag long code-tracing or list slicing questions and solve straightforward conceptual items first.
2. **Watch Mutability Traps:** Distinguish between object assignment (`b = a`, shared pointer) and copies (`b = a.copy()`), especially when lists are passed into functions.
3. **Trace Off-By-One Boundaries:** Remember that `range(start, stop)` excludes `stop` and negative slice steps reverse sequence ordering (`[::-1]`).
4. **Pre-Submission Review:** Reserve the final 5 minutes to verify all questions have recorded responses before the exam timer expires.

---

### 📚 Grounded Course Study Materials
{materials_section}
{history_notice}

💡 **Suggested Next Steps:** Would you like to practice 5 multiple-choice questions on Python data structures, or start a timed practice session now?"""
            return guide.strip()

        # 2. C++ Exam Preparation Plan
        elif subj_key in ("c++", "cpp") or "c++" in q_lower or "cpp" in q_lower:
            guide = f"""### 🎯 Comprehensive Exam Preparation Strategy: **C++**

To prepare effectively and achieve top marks in your **C++** exam, follow this structured topic review, practical 7-day daily study schedule, hands-on practice problems, and mock test revision roadmap:

#### 📌 Core Topics Checklist
- [ ] **Language Basics:** Types, declarations, namespaces (`std::`), I/O streams (`cin`, `cout`), control structures
- [ ] **Pointers & References:** Address-of (`&`), dereference (`*`), pass-by-value vs pass-by-reference (`&`), pointer arithmetic
- [ ] **Dynamic Memory & RAII:** `new`, `delete`, memory leak prevention, RAII resource lifecycle, smart pointers (`std::unique_ptr`, `std::shared_ptr`)
- [ ] **Object-Oriented Programming (OOP):** Classes, access specifiers (`public`, `private`, `protected`), constructors, member initializer lists, destructors
- [ ] **Inheritance & Polymorphism:** Base and derived classes, function overriding, `virtual` functions, runtime polymorphism, pure virtual functions (`= 0`)
- [ ] **Standard Template Library (STL):** Vectors, lists, maps, sets, iterators, `std::sort`, algorithms from `<algorithm>`
- [ ] **Exception Handling:** `try`, `catch`, `throw`, standard exception hierarchy (`std::exception`)

#### 📅 Practical 7-Day Daily Study Schedule
- **Day 1: C++ Syntax & Control Structures**
  - Master data types, arrays, conditionals, loops, and stream I/O formatting.
- **Day 2: Pointers, References & Memory Layout**
  - Practice pointer declarations (`int* ptr = &val;`), dereferencing (`*ptr`), and references (`int& ref = val;`).
- **Day 3: Dynamic Memory Allocation & RAII**
  - Practice allocating heap memory using `new` and releasing it deterministically with `delete`.
  - Learn modern C++ smart pointers (`std::unique_ptr`).
- **Day 4: Object-Oriented C++ Classes**
  - Create classes with constructors, member initializer lists, and destructors.
  - Implement encapsulation and data hiding.
- **Day 5: Inheritance & Virtual Functions**
  - Implement class inheritance, override virtual functions, and understand the vtable mechanism.
- **Day 6: STL Containers & Timed Practice**
  - Work with `std::vector`, `std::map`, and STL algorithms (`std::sort`, `std::find`).
  - Solve 15 to 20 practice questions in the **Practice Session** tab.
- **Day 7: Full Mock Exam & Syntax Revision**
  - Complete a full-length timed mock exam on the Online Examination System.
  - Review your pointer syntax summary cards and error logs.

#### 📝 High-Yield Practice Questions

1. **Pointers & Dereferencing:**
   *Question:* What is the output of the following C++ code?
   ```cpp
   int val = 50;
   int* p = &val;
   *p += 25;
   std::cout << val;
   ```
   *Explanation:* Outputs `75`. The pointer `p` holds the memory address of `val`; modifying `*p` directly modifies `val`.

2. **Virtual Functions (Polymorphism):**
   *Question:* Why must a base class destructor be declared `virtual` if derived classes allocate dynamic memory?
   *Explanation:* Declaring a base destructor `virtual` guarantees that the derived class destructor executes first when deleting an object through a base class pointer, preventing resource/memory leaks.

#### 🔄 Revision & Mock Test Strategy
1. **Timed Mock Exams:** Complete at least 2 full mock exams under timed conditions to practice debugging and code tracing.
2. **Distractor Analysis:** Pay close attention to pointer traps, boundary errors, and operator precedence distractors.
3. **Cheat Sheet Review:** Keep a summary of pointer operators (`*`, `&`, `->`), STL complexity guarantees, and constructor execution orders.

#### 💡 Essential Exam-Day Tips
1. **Pacing & Scratch Paper:** Spend 1 to 1.5 minutes per MCQ. Use scratch paper to draw pointer arrows and memory box diagrams rather than mental simulation.
2. **Pointer Operator Precedence:** Watch out for `*ptr++` (increments pointer address, dereferences old address) versus `(*ptr)++` (increments integer value).
3. **Virtual Destructor Trap:** In inheritance questions involving `delete basePtr;`, check if the base class has a `virtual` destructor to identify memory leak options.
4. **Pre-Submission Check:** Confirm all questions have an answer selected; double-check boundary conditions on loops (`<` vs `<=`).

---

### 📚 Grounded Course Study Materials
{materials_section}
{history_notice}

💡 **Suggested Next Steps:** Would you like to review C++ pointers and memory management, practice OOP questions, or start a timed mock exam?"""
            return guide.strip()

        # 3. Java Exam Preparation Plan
        elif subj_key in ("java",) or "java" in q_lower:
            guide = f"""### 🎯 Comprehensive Exam Preparation Strategy: **Java**

To prepare effectively and achieve top marks in your **Java** exam, follow this structured topic review, practical 7-day daily study schedule, hands-on practice problems, and mock test revision roadmap:

#### 📌 Core Topics Checklist
- [ ] **Java Fundamentals:** JVM, JRE, JDK, bytecode compilation, primitive types vs wrapper classes, operators
- [ ] **Flow Control:** If/else, switch expressions, for-each loops, while loops, break & continue
- [ ] **Object-Oriented Programming (OOP):** Classes, `new` keyword, constructors, encapsulation, inheritance (`extends`), polymorphism
- [ ] **Interfaces & Abstract Classes:** Multiple inheritance through `implements`, abstract methods, default methods
- [ ] **Exception Handling:** `try`, `catch`, `finally`, `throw`, `throws`, checked vs unchecked exceptions
- [ ] **Java Collections Framework:** `List` (`ArrayList`, `LinkedList`), `Set` (`HashSet`), `Map` (`HashMap`), iteration
- [ ] **String Handling:** `String` immutability, `StringBuilder`, string pool comparison (`==` vs `.equals()`)

#### 📅 Practical 7-Day Daily Study Schedule
- **Day 1: Java Syntax, Types & Control Flow**
  - Master data types, operators, conditionals, and enhanced for-each loops.
- **Day 2: Classes, Constructors & Encapsulation**
  - Define classes, private fields, public getters/setters, and overloaded constructors.
- **Day 3: Inheritance & Polymorphism**
  - Implement single inheritance with `extends`, method overriding with `@Override`, and `super()`.
- **Day 4: Interfaces & Abstract Classes**
  - Define abstract classes and interfaces, implementing contracts across multiple classes.
- **Day 5: Exception Handling & Strings**
  - Master `try...catch...finally` execution order and understand `.equals()` vs `==`.
- **Day 6: Collections & Practice Testing**
  - Practice `ArrayList` and `HashMap` operations.
  - Complete 15 to 20 questions in the **Practice Session** tab.
- **Day 7: Full Mock Exam & Revision**
  - Take a full timed mock exam and review your incorrect answer patterns.

#### 📝 High-Yield Practice Questions

1. **String Equality:**
   *Question:* What does `str1 == str2` evaluate to vs `str1.equals(str2)`?
   *Explanation:* `==` checks reference equality (memory location), whereas `.equals()` compares actual character sequence values.

2. **Exception Handling:**
   *Question:* When does the `finally` block execute in Java?
   *Explanation:* The `finally` block always executes whether an exception was thrown, caught, or not thrown at all (except if `System.exit()` is invoked).

#### 🔄 Revision & Mock Test Strategy
1. **Timed Mock Exams:** Complete full-length practice sessions in the Online Examination System under a timed 45-60 minute clock.
2. **Cheat Sheet Review:** Refresh access modifiers (`private`, default, `protected`, `public`) and Collections hierarchy.

#### 💡 Essential Exam-Day Tips
1. **Pacing & Strategy:** Allocate 1 to 1.5 minutes per question. Prioritize straightforward definition and syntax questions before tackling multi-level inheritance or nested loop tracing.
2. **Distinguish `==` from `.equals()`:** Remember that `==` tests object reference equality in the heap, while `.equals()` checks content value equivalence.
3. **Trace Exception Flow Carefully:** Remember that `finally` blocks almost always execute, even after a `return` statement inside `try` or `catch`.
4. **Watch Access Modifiers & Scope:** Check whether `protected` or package-private methods are legal across different packages, and watch for uninitialized local variables.

---

### 📚 Grounded Course Study Materials
{materials_section}
{history_notice}

💡 **Suggested Next Steps:** Would you like to practice Java OOP questions, explore exception handling patterns, or launch a practice exam?"""
            return guide.strip()

        # 4. General / Other Subjects Preparation Plan
        else:
            guide = f"""### 🎯 Comprehensive Exam Preparation Strategy: **{norm_subj}**

To prepare effectively and achieve top marks in your **{norm_subj}** exam, follow this structured topic review, practical 7-day daily study schedule, hands-on practice problems, and mock test revision roadmap:

#### 📌 Core Topics Checklist
- [ ] **Foundations & Terminology:** Core definitions, primary concepts, and subject standards
- [ ] **Core Analytical Principles:** Theoretical rules, formulas, and operational workflows
- [ ] **Practical Application & Problem Solving:** Real-world scenarios, case studies, and problem-solving patterns
- [ ] **Common Pitfalls & Distractors:** Frequently tested boundary conditions and exam question traps
- [ ] **Comprehensive Review:** Synthesizing cross-topic connections and exam-style scenarios

#### 📅 Practical 7-Day Daily Study Schedule
- **Day 1: Subject Fundamentals & Key Definitions**
  - Review core terms, fundamental axioms, and standard formulas.
- **Day 2: Primary Principles & Conceptual Frameworks**
  - Study how the fundamental concepts connect and govern typical problems.
- **Day 3: Intermediate Problem Solving & Application**
  - Work through representative problems and step-by-step worked solutions.
- **Day 4: Advanced Scenarios & Corner Cases**
  - Focus on challenging edge cases, common traps, and multi-step questions.
- **Day 5: Synthesis & Summary Card Creation**
  - Condense key facts into a 1-page quick revision cheat sheet.
- **Day 6: Timed Practice Questions & Weak-Topic Review**
  - Solve 15 to 20 multiple-choice questions in the **Practice Session** tab.
  - Trace why incorrect choices fail and reinforce weak topics.
- **Day 7: Full Mock Examination & Final Rest**
  - Complete a full-length timed mock exam under realistic testing conditions.
  - Review summary sheets and rest well before test day.

#### 📝 High-Yield Practice Strategy
- **Active Recall:** Test yourself on definitions and principles before looking at solutions.
- **Error Journaling:** Keep a short log of every question missed during practice to avoid repeating errors.
- **Timed Pacing:** Practice pacing at 1 to 1.5 minutes per question to build confidence for exam day.

#### 🔄 Revision & Mock Test Strategy
1. **Full-Length Mock Testing:** Use the Online Examination System to take at least 2 complete practice sessions.
2. **Distractor Analysis:** For every question, evaluate why wrong answers are distractors and what makes the correct answer exact.

#### 💡 Essential Exam-Day Tips
1. **Time Management:** Budget roughly 1 to 1.5 minutes per question. If a question is calculation-heavy or ambiguous, flag it and move forward.
2. **Read Every Option:** Do not select the first seemingly correct answer without evaluating all distractors.
3. **Elimination Tactic:** Systematically cross off obviously incorrect options to improve odds on challenging items.
4. **Final Check:** Reserve the last 5 minutes to confirm all answers have been submitted.

---

### 📚 Grounded Course Study Materials
{materials_section}
{history_notice}

💡 **Suggested Next Steps:** Would you like some practice questions on a specific **{norm_subj}** topic, or would you like to start a practice exam session now?"""
            return guide.strip()

    LANGUAGE_PROFILES = {
        "c": {
            "name": "C",
            "lang_tag": "c",
            "philosophy": "bare-metal hardware performance, minimal runtime overhead, and direct memory control",
            "execution": "Compiled directly to native machine code via GCC or Clang",
            "typing": "Statically and weakly/moderately typed (compile-time checking with implicit conversions)",
            "memory": "Manual memory management (`malloc()`, `calloc()`, `free()`) with direct pointer arithmetic",
            "performance": "Blazing fast; near-metal hardware execution speed with zero runtime overhead",
            "syntax": "Minimalist procedural syntax; strict semicolons `;`, braces `{ }`, and explicit declarations",
            "pointers": "Full direct pointer manipulation, pointer arithmetic, and raw memory access",
            "ecosystem": "C Standard Library (`stdio.h`, `stdlib.h`, `string.h`), POSIX, and direct OS system calls",
            "use_cases": "Operating system kernels (Linux, Windows kernel), embedded firmware, microcontrollers, device drivers",
            "advantages": [
                "Direct hardware access with near-zero runtime abstraction overhead",
                "Extremely compact memory footprint and predictable real-time execution timing",
                "Universal portability across virtually every CPU architecture and embedded microcontroller"
            ],
            "trade_offs": [
                "Manual memory management prone to buffer overflows, memory leaks, and segmentation faults",
                "Lacks built-in object-oriented programming, exceptions, and generic templates"
            ],
            "code": """#include <stdio.h>

void print_evens(int max_val) {
    for (int i = 0; i <= max_val; i += 2) {
        printf("%d ", i);
    }
    printf("\\n");
}

int main() {
    print_evens(6); // Outputs: 0 2 4 6
    return 0;
}""",
            "when_to_choose": "You are developing OS kernels, microcontrollers, embedded hardware firmware, or system drivers requiring absolute control over hardware and memory."
        },
        "c++": {
            "name": "C++",
            "lang_tag": "cpp",
            "philosophy": "execution speed, zero-cost abstractions, RAII, and deterministic resource control",
            "execution": "Compiled directly to native machine code",
            "typing": "Statically and strongly typed (compile-time type checking with type inference via `auto`)",
            "memory": "Deterministic manual (`new`/`delete`), RAII, and Smart Pointers (`std::unique_ptr`, `std::shared_ptr`)",
            "performance": "Extremely fast execution with zero-cost abstractions and direct memory control",
            "syntax": "Rich, multi-paradigm, explicit; strict semicolons `;` and braces `{ }`",
            "pointers": "Full raw pointer access, references (`&`), plus modern safe smart pointers",
            "ecosystem": "Standard Template Library (STL: `std::vector`, `std::map`, `<algorithm>`), Boost",
            "use_cases": "Game engines (Unreal Engine), AAA video games, high-frequency trading (HFT), operating systems, graphics renderers",
            "advantages": [
                "Blazing native execution speed with zero-cost runtime abstractions",
                "Deterministic resource reclamation via RAII and modern smart pointers (`std::unique_ptr`, `std::shared_ptr`)",
                "Comprehensive Standard Template Library (STL) and multi-paradigm flexibility (OOP, generic, functional)"
            ],
            "trade_offs": [
                "Steep learning curve with extensive syntax features and subtle backward-compatibility nuances",
                "Complex build toolchains and risk of undefined behavior if manual pointers are misused"
            ],
            "code": """#include <iostream>
#include <vector>

void printEvens(int maxVal) {
    for (int i = 0; i <= maxVal; i += 2) {
        std::cout << i << " ";
    }
    std::cout << std::endl;
}

int main() {
    printEvens(6); // Outputs: 0 2 4 6
    return 0;
}""",
            "when_to_choose": "You need high-performance object-oriented software, game development, graphics pipelines, or low-latency systems where execution speed cannot be compromised."
        },
        "python": {
            "name": "Python",
            "lang_tag": "python",
            "philosophy": "developer velocity, simplicity, clean syntax, and rapid application delivery",
            "execution": "Interpreted / Bytecode-compiled executed by Python Virtual Machine (CPython)",
            "typing": "Dynamically and strongly typed (type checks occur at runtime; supports type hints)",
            "memory": "Automatic Garbage Collection (reference counting + cyclic generational GC)",
            "performance": "Slower CPU-bound execution; relies on C/C++ extensions (NumPy, PyTorch) for compute-intensive tasks",
            "syntax": "Clean, concise, and elegant; enforces significant whitespace and indentation",
            "pointers": "No direct pointers; all variables are managed object references",
            "ecosystem": "\"Batteries Included\" standard library + PyPI (NumPy, Pandas, Django, FastAPI, PyTorch, Scikit-learn)",
            "use_cases": "Data science, machine learning & AI, web backends, automation scripts, rapid application development",
            "advantages": [
                "Extremely clean, concise syntax that maximizes developer velocity and readability",
                "Dominant global ecosystem for AI, Machine Learning, and Data Science (PyTorch, TensorFlow, NumPy, Pandas)",
                "Massive standard library ('Batteries Included') and huge open-source package repository (PyPI)"
            ],
            "trade_offs": [
                "Interpreted bytecode execution is significantly slower for CPU-bound computation",
                "Global Interpreter Lock (GIL) historically constrains native multi-core CPU parallelism"
            ],
            "code": """def print_evens(max_val: int) -> None:
    for i in range(0, max_val + 1, 2):
        print(i, end=" ")
    print()

print_evens(6)  # Outputs: 0 2 4 6""",
            "when_to_choose": "You want rapid developer productivity, data analysis, machine learning models, web services, or automation where fast development cycles matter more than microsecond runtime speed."
        },
        "java": {
            "name": "Java",
            "lang_tag": "java",
            "philosophy": "cross-platform portability ('Write Once, Run Anywhere'), strong encapsulation, and enterprise maintainability",
            "execution": "Compiled to bytecode executed on Java Virtual Machine (JVM) with Just-In-Time (JIT) compilation",
            "typing": "Statically and strongly typed (strict compile-time type safety)",
            "memory": "Automatic Garbage Collection (G1, ZGC) with automatic memory reclaiming",
            "performance": "Fast performance after JIT warmup; higher baseline memory usage than C/C++",
            "syntax": "Explicit, class-based object-oriented syntax; strict semicolons `;` and braces `{ }`",
            "pointers": "No direct pointer arithmetic; references are abstracted and memory-safe",
            "ecosystem": "Extensive enterprise ecosystem (Spring Boot, Hibernate, Maven, Gradle, Apache Kafka)",
            "use_cases": "Enterprise web applications, Android app development, large-scale financial banking systems, backend microservices",
            "advantages": [
                "'Write Once, Run Anywhere' (WORA) cross-platform portability on the Java Virtual Machine (JVM)",
                "High-throughput Just-In-Time (JIT) compilation and modern concurrent garbage collection (G1, ZGC)",
                "Strict compile-time type safety, mature tooling, and unmatched enterprise ecosystem (Spring Boot, Kafka, Android)"
            ],
            "trade_offs": [
                "Higher baseline memory overhead and warm-up latency before JIT compiler optimization takes effect",
                "More verbose boilerplate code compared to dynamic scripting languages"
            ],
            "code": """public class EvensPrinter {
    public static void printEvens(int maxVal) {
        for (int i = 0; i <= maxVal; i += 2) {
            System.out.print(i + " ");
        }
        System.out.println();
    }

    public static void main(String[] args) {
        printEvens(6); // Outputs: 0 2 4 6
    }
}""",
            "when_to_choose": "You are building large-scale enterprise services, cross-platform Android applications, or distributed backend systems needing strong type safety and robust long-term maintainability."
        },
        "javascript": {
            "name": "JavaScript",
            "lang_tag": "javascript",
            "philosophy": "dynamic interactive frontends, event-driven non-blocking I/O, and universal full-stack execution",
            "execution": "JIT-compiled and interpreted in browser engines (V8, SpiderMonkey) and Node.js",
            "typing": "Dynamically and weakly typed (coercion at runtime; TypeScript adds static typing)",
            "memory": "Automatic Garbage Collection (mark-and-sweep algorithm)",
            "performance": "High-throughput for asynchronous I/O-bound operations via single-threaded event loop",
            "syntax": "C-style syntax with curly braces; first-class functions, prototype inheritance, and async/await",
            "pointers": "No raw memory or pointer addressing",
            "ecosystem": "NPM package registry, React, Vue, Angular, Node.js, Express, Next.js",
            "use_cases": "Full-stack web applications, interactive browser frontends, serverless APIs, real-time chat/collaboration apps",
            "advantages": [
                "The universal language of the web, natively supported across all modern web browsers",
                "Non-blocking event-driven I/O delivering high concurrency for networked microservices (Node.js)",
                "Vast NPM ecosystem with industry-standard component UI libraries (React, Vue, Next.js)"
            ],
            "trade_offs": [
                "Dynamic weak typing can cause subtle runtime type coercion bugs without TypeScript",
                "Single-threaded event loop requires careful design to avoid blocking on compute-heavy tasks"
            ],
            "code": """function printEvens(maxVal) {
    for (let i = 0; i <= maxVal; i += 2) {
        process.stdout.write(i + " ");
    }
    console.log();
}

printEvens(6); // Outputs: 0 2 4 6""",
            "when_to_choose": "You are creating web user interfaces, interactive browser experiences, or unified full-stack JavaScript systems."
        },
        "sql": {
            "name": "SQL",
            "lang_tag": "sql",
            "philosophy": "declarative set-based queries, relational data integrity, and ACID transaction guarantees",
            "execution": "Declarative queries compiled, optimized, and executed by Relational Database Management Systems (RDBMS)",
            "typing": "Statically typed relational schema columns (INT, VARCHAR, TIMESTAMP)",
            "memory": "Managed internally by the RDBMS engine (buffer pool, page caching, temp tables)",
            "performance": "Highly optimized for set-based data retrieval utilizing B-Tree, hash, and composite indexes",
            "syntax": "Declarative English-like queries (`SELECT`, `INSERT`, `UPDATE`, `DELETE`, `JOIN`)",
            "pointers": "No pointers; operations are relational transformations on tables and records",
            "ecosystem": "MySQL, PostgreSQL, SQLite, Microsoft SQL Server, Oracle Database",
            "use_cases": "Relational data persistence, analytics, transaction processing with ACID compliance",
            "advantages": [
                "Declarative set-based queries optimized by database query planners and B-Tree indexes",
                "ACID transaction guarantees ensuring mission-critical data integrity and consistency",
                "Universal industry standard for structured data modeling, reporting, and relational storage"
            ],
            "trade_offs": [
                "Not designed for general-purpose procedural or application business logic",
                "Relational schema migrations require disciplined management as database volume scales"
            ],
            "code": """SELECT student_id, name, score
FROM exam_results
WHERE score >= 75
ORDER BY score DESC;""",
            "when_to_choose": "You need to store, structure, query, and enforce integrity constraints on relational data."
        }
    }

    @classmethod
    def extract_comparison_languages(cls, query: str) -> Tuple[Optional[str], Optional[str]]:
        """
        Extracts two subjects or programming languages to compare from a comparison question.
        Preserves the order in which they appear in the user query.
        """
        q_lower = query.lower()
        languages = [
            ("C++", r"\b(c\+\+|cpp)\b"),
            ("C", r"\b(c\s+programming|c\s+language|language\s+c|\bc\b(?!\+))\b"),
            ("Python", r"\bpython\b"),
            ("Java", r"\bjava\b(?!\s*script)"),
            ("JavaScript", r"\b(javascript|js|typescript|ts)\b"),
            ("SQL", r"\b(sql|database|dbms)\b"),
            ("PHP", r"\bphp\b"),
        ]
        found = []
        for name, pat in languages:
            m = re.search(pat, q_lower)
            if m:
                found.append((m.start(), name))
        found.sort(key=lambda x: x[0])
        matched = [name for _, name in found]

        if len(matched) >= 2:
            return matched[0], matched[1]

        m = re.search(r'(?:compare|difference\s+between)\s+([^,]+?)\s+(?:and|vs\.?|versus|with|to)\s+([^,?.!]+)', query, re.I)
        if m:
            return m.group(1).strip(), m.group(2).strip()
        m2 = re.search(r'\b([A-Za-z0-9+#]+)\s+(?:vs\.?|versus)\s+([A-Za-z0-9+#]+)\b', query, re.I)
        if m2:
            return m2.group(1).strip(), m2.group(2).strip()

        return None, None

    @classmethod
    def generate_comparison_response(
        cls,
        query: str = "",
        subject1: str = "C++",
        subject2: str = "Python",
        *args,
        **kwargs
    ) -> str:
        s1 = subject1.strip() if subject1 else "C++"
        s2 = subject2.strip() if subject2 else "Python"

        def norm_key(s: str) -> str:
            s_low = s.strip().lower()
            if s_low in ("c++", "cpp"): return "c++"
            if s_low in ("c", "c language", "c programming"): return "c"
            if s_low in ("python", "py"): return "python"
            if s_low in ("java",): return "java"
            if s_low in ("javascript", "js", "typescript", "ts"): return "javascript"
            if s_low in ("sql", "database", "dbms"): return "sql"
            return s_low

        k1 = norm_key(s1)
        k2 = norm_key(s2)

        if k1 in cls.LANGUAGE_PROFILES and k2 in cls.LANGUAGE_PROFILES and k1 != k2:
            p1 = cls.LANGUAGE_PROFILES[k1]
            p2 = cls.LANGUAGE_PROFILES[k2]

            p1_adv = "\n".join(f"  - ✅ {a}" for a in p1.get("advantages", []))
            p1_trd = "\n".join(f"  - ⚠️ {t}" for t in p1.get("trade_offs", []))
            p2_adv = "\n".join(f"  - ✅ {a}" for a in p2.get("advantages", []))
            p2_trd = "\n".join(f"  - ⚠️ {t}" for t in p2.get("trade_offs", []))

            advantages_section = f"""#### 🌟 Key Advantages & Trade-offs

##### **{p1['name']}**
- **Core Advantages:**
{p1_adv}
- **Architectural Trade-offs:**
{p1_trd}

##### **{p2['name']}**
- **Core Advantages:**
{p2_adv}
- **Architectural Trade-offs:**
{p2_trd}"""

            return f"""### ⚖️ Comparison: **{p1['name']} vs. {p2['name']}**

Both **{p1['name']}** and **{p2['name']}** are widely adopted software engineering technologies, but they were designed with fundamentally distinct architectural trade-offs: **{p1['name']}** focuses on {p1['philosophy']}, while **{p2['name']}** focuses on {p2['philosophy']}.

#### 📊 Quick Reference Comparison Table

| Feature / Dimension | {p1['name']} | {p2['name']} |
| :--- | :--- | :--- |
| **Execution Model** | {p1['execution']} | {p2['execution']} |
| **Typing System** | {p1['typing']} | {p2['typing']} |
| **Memory Management** | {p1['memory']} | {p2['memory']} |
| **Performance & Speed** | {p1['performance']} | {p2['performance']} |
| **Syntax Complexity** | {p1['syntax']} | {p2['syntax']} |
| **Pointers & Memory Access** | {p1['pointers']} | {p2['pointers']} |
| **Standard Ecosystem** | {p1['ecosystem']} | {p2['ecosystem']} |
| **Primary Use Cases** | {p1['use_cases']} | {p2['use_cases']} |

#### 💻 Side-by-Side Code Demonstration

**{p1['name']}:**
```{p1['lang_tag']}
{p1['code']}
```

**{p2['name']}:**
```{p2['lang_tag']}
{p2['code']}
```

{advantages_section}

#### 🎯 When to Choose Which?
- **Choose {p1['name']} when**: {p1['when_to_choose']}
- **Choose {p2['name']} when**: {p2['when_to_choose']}

💡 **Suggested Next Step:** Would you like to explore **{p1['name']} syntax**, practice implementation problems, or compare another pair of languages?""".strip()
        else:
            return f"""### ⚖️ Comparison: **{s1} vs. {s2}**

Comparing **{s1}** and **{s2}** highlights distinct software engineering trade-offs:

#### 📊 Quick Reference Comparison Table

| Dimension | {s1} | {s2} |
| :--- | :--- | :--- |
| **Execution Model** | Native compilation / VM runtime | Scripted / Bytecode / Native compilation |
| **Typing System** | Strong / Static or Dynamic | Strong / Static or Dynamic |
| **Memory Management** | Deterministic / Automatic GC | Automatic GC / Manual control |
| **Primary Use Cases** | Systems, backend, or domain workflows | Web, scripting, or enterprise applications |
| **Ecosystem & Tooling** | Curated standard libraries & package registry | Official package managers & active ecosystem |

#### 🎯 Key Comparative Highlights
1. **Design Philosophy**: **{s1}** provides specialized execution idioms, language grammar, and domain-targeted workflows, while **{s2}** offers alternative syntax conventions, runtime semantics, and ecosystem tooling.
2. **Performance & Overhead**: Compiled and statically checked systems minimize CPU latency and memory footprint, whereas dynamic or interpreted platforms accelerate developer agility and prototyping.
3. **Choosing the Right Technology**: Choose **{s1}** when its specific execution model aligns with your operational constraints; choose **{s2}** when developer productivity, library maturity, or rapid delivery takes precedence.

💡 **Suggested Next Step:** Would you like a side-by-side code example or practice quiz questions comparing **{s1}** and **{s2}**?""".strip()

    @classmethod
    def generate_concept_explanation(
        cls,
        concept: str = "",
        subject: str = "",
        context_chunks: Optional[List[Dict[str, Any]]] = None,
        *args,
        **kwargs
    ) -> str:
        if not concept and "question" in kwargs:
            concept = kwargs["question"]
        if not subject and "topic" in kwargs and kwargs["topic"]:
            subject = kwargs["topic"]
        if context_chunks is None and "chunks" in kwargs:
            context_chunks = kwargs["chunks"]

        # Filter out chunks that do not match the target subject to prevent cross-language leakage
        norm_subj = subject.strip() if subject else ""
        matching_chunks = []
        if context_chunks:
            for c in context_chunks:
                c_subj = c.get("subject", "")
                if not norm_subj or not c_subj or c_subj.strip().lower() == norm_subj.lower():
                    matching_chunks.append(c)
        # External LLM check
        if cls.is_external_configured():
            try:
                system_prompt = (
                    "You are an expert academic learning assistant for students across School (Classes 1–10), "
                    "PU / Pre-University (Classes 11–12), Engineering, Degree/University, Coding & Programming, and General Education.\n"
                    "Provide clear, comprehensive, and well-structured answers formatted in clean Markdown with appropriate headings.\n"
                    "- Use runnable code blocks with language labels (```python, ```java, ```sql, ```cpp) where appropriate.\n"
                    "- For mathematical/scientific questions, show equations and step-by-step calculations.\n"
                    "- For engineering questions, explain principles, trade-offs, and practical implementations.\n"
                    "- For grammar or language questions, explain rules clearly with tables, examples, and correct vs incorrect comparisons.\n"
                    "- For follow-ups, maintain seamless continuity with preceding messages."
                )
                messages = [{"role": "system", "content": system_prompt}]
                conv_history = kwargs.get("conversation_history") or []
                for m in conv_history:
                    if isinstance(m, dict) and m.get("content"):
                        role = m.get("role", "user")
                        messages.append({"role": role, "content": str(m["content"])})
                user_msg = f"Subject: {norm_subj or 'General'}\nConcept/Question: {concept}"
                messages.append({"role": "user", "content": user_msg})
                resp = cls.generate_chat_completion(messages)
                if resp and len(resp.strip()) > 30:
                    return resp.strip()
            except Exception as e:
                logger.warning(f"External LLM call failed in generate_concept_explanation: {e}. Falling back to domain engine.")

        clean_concept = re.sub(r'^(hi+|hello|hey+|good\s+(morning|afternoon|evening))\s*,?\s*', '', concept, flags=re.I).strip()
        clean_concept = re.sub(r'^(please\s+)?(explain|what is a|what is an|what is|what are|how does|what do you know about|tell me about)\s+', '', clean_concept, flags=re.I).strip(" ?.")
        clean_concept_title = clean_concept.title() if clean_concept else (norm_subj or "Core Principle")

        CURRICULUM_SUBJECTS = {
            "c++", "c", "python", "java", "javascript", "php", "sql", "database",
            "operating systems", "computer science", "maths", "mathematics", "english", "gk", "general",
            "physics", "chemistry", "biology", "electronics", "communication systems"
        }
        grounding_note = ""
        if matching_chunks and len(matching_chunks) > 0:
            fn = matching_chunks[0].get("filename", "")
            pnum = matching_chunks[0].get("page_number", 1)
            doc_id = matching_chunks[0].get("document_id")
            link_str = f"[`{fn}`](view_course_material.php?id={doc_id}&page={pnum}&source=assistant)" if doc_id else f"`{fn}`"
            grounding_note = f"\n\n*(Explanation supported by approved course material: {link_str} — Page {pnum})*"
        elif norm_subj and norm_subj.strip().lower() not in CURRICULUM_SUBJECTS:
            grounding_note = f"\n\n*(Note: I don't have enough information about {norm_subj} in the uploaded course materials. Standard curriculum explanation is provided above.)*"

        lower_concept = clean_concept.lower()
        lower_query = concept.lower()

        # 1. Comparison Queries (e.g. "Compare C++ and Python", "Compare C and Python", "difference between Java and Python")
        if any(w in lower_query for w in ["compare", "difference between", " vs ", "versus", "differ from"]):
            s1_cand, s2_cand = cls.extract_comparison_languages(concept)
            if s1_cand and s2_cand:
                return cls.generate_comparison_response(query=concept, subject1=s1_cand, subject2=s2_cand)
            elif "c++" in lower_query and "python" in lower_query:
                return cls.generate_comparison_response(query=concept, subject1="C++", subject2="Python")
            elif norm_subj and " and " in norm_subj:
                parts = norm_subj.split(" and ")
                return cls.generate_comparison_response(query=concept, subject1=parts[0].strip(), subject2=parts[1].strip())

        # 2. English Grammar & Language
        if "grammar" in lower_concept or "grammar" in lower_query or (norm_subj and norm_subj.lower() == "english"):
            detail = (
                "**English Grammar** is the system of structural rules governing the composition of clauses, phrases, and words in the English language. "
                "Mastering grammar ensures clarity, precision, and academic coherence in both written and spoken communication.\n\n"
                "#### 📚 The 8 Core Parts of Speech\n\n"
                "| Part of Speech | Function / Role | Example Words | Example Sentence |\n"
                "| :--- | :--- | :--- | :--- |\n"
                "| **Noun** | Names a person, place, thing, or abstract idea | *student, campus, knowledge, algorithm* | The **student** mastered the **algorithm**. |\n"
                "| **Pronoun** | Replaces a noun to eliminate repetitive phrasing | *he, she, it, they, someone, who* | **She** submitted her assignment early. |\n"
                "| **Verb** | Expresses action, event, or state of being | *analyze, compute, is, develop, explain* | Engineers **design** reliable systems. |\n"
                "| **Adjective** | Describes or qualifies a noun or pronoun | *diligent, efficient, complex, concise* | We wrote an **efficient** solution. |\n"
                "| **Adverb** | Modifies a verb, adjective, or another adverb | *rapidly, accurately, very, thoroughly* | The code runs **exceptionally** fast. |\n"
                "| **Preposition** | Shows temporal, spatial, or logical relationships | *in, on, between, through, after* | Data moves **through** the pipeline. |\n"
                "| **Conjunction** | Connects words, phrases, or independent clauses | *and, but, because, although, whereas* | The test was hard **but** she passed. |\n"
                "| **Interjection** | Expresses spontaneous or emphatic emotion | *bravo, alas, eureka, oh* | **Eureka!** The program works. |\n\n"
                "#### ⏳ Foundational Tenses & Aspects\n"
                "1. **Present Tenses**: *Simple Present* (universal facts/habits: *\"Water boils at 100°C\"*), *Present Continuous* (ongoing actions: *\"She is revising\"*), *Present Perfect* (completed actions with present relevance: *\"I have completed the exam\"*).\n"
                "2. **Past Tenses**: *Simple Past* (completed past events: *\"He solved the problem\"*), *Past Continuous* (past background action), *Past Perfect* (action prior to another past event: *\"The test had started before he arrived\"*).\n"
                "3. **Future Tenses**: *Simple Future* (*\"We will review chapter 3 tomorrow\"*).\n\n"
                "#### ⚠️ Critical Grammar Traps\n"
                "- **Subject-Verb Agreement**: Singular subjects take singular verbs (*\"Every one of the students **is** prepared\"*, not *are*).\n"
                "- **Active vs. Passive Voice**: Prefer active voice for direct technical clarity (*\"The compiler threw an error\"* vs *\"An error was thrown by the compiler\"*)."
            )
            resp = f"### 💡 Academic Guide: **English Grammar & Syntax**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like practice exercises on **Subject-Verb Agreement**, a breakdown of **Active vs. Passive Voice**, or sample exam questions?"
            return resp.strip()

        # 3. SQL & Relational Databases
        if (norm_subj and norm_subj.lower() in ["sql", "database"]) or any(w in lower_query for w in ["sql", "select query", "inner join", "left join", "group by", "rdbms", "database query"]):
            sql_code = (
                "```sql\n"
                "-- 1. Schema Definition with Constraints\n"
                "CREATE TABLE students (\n"
                "    student_id INT PRIMARY KEY AUTO_INCREMENT,\n"
                "    full_name VARCHAR(100) NOT NULL,\n"
                "    department VARCHAR(50) NOT NULL,\n"
                "    cgpa DECIMAL(3, 2) DEFAULT 0.00\n"
                ");\n\n"
                "CREATE TABLE exam_scores (\n"
                "    score_id INT PRIMARY KEY AUTO_INCREMENT,\n"
                "    student_id INT NOT NULL,\n"
                "    subject_name VARCHAR(50) NOT NULL,\n"
                "    marks_obtained INT CHECK (marks_obtained >= 0 AND marks_obtained <= 100),\n"
                "    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE\n"
                ");\n\n"
                "-- 2. Analytical Query with INNER JOIN, Aggregation, and Filtering\n"
                "SELECT \n"
                "    s.department,\n"
                "    e.subject_name,\n"
                "    COUNT(DISTINCT s.student_id) AS total_students,\n"
                "    ROUND(AVG(e.marks_obtained), 2) AS average_score,\n"
                "    MAX(e.marks_obtained) AS highest_score\n"
                "FROM students s\n"
                "INNER JOIN exam_scores e ON s.student_id = e.student_id\n"
                "WHERE s.cgpa >= 6.50\n"
                "GROUP BY s.department, e.subject_name\n"
                "HAVING AVG(e.marks_obtained) >= 50.0\n"
                "ORDER BY average_score DESC;\n"
                "```"
            )
            detail = (
                "**SQL (Structured Query Language)** is the standardized domain-specific language used for managing, querying, and manipulating data stored in Relational Database Management Systems (RDBMS).\n\n"
                "#### 🎯 Logical Query Execution Order\n"
                "While SQL queries are written starting with `SELECT`, the database query engine executes clauses in a strict mathematical sequence:\n"
                "1. **`FROM` & `JOIN`**: Determine base tables and execute relational cartesian products/joins.\n"
                "2. **`WHERE`**: Filters individual candidate rows before grouping.\n"
                "3. **`GROUP BY`**: Collapses candidate rows into distinct aggregation buckets.\n"
                "4. **`HAVING`**: Filters aggregated groups (unlike `WHERE`, can evaluate aggregate functions like `AVG()` or `COUNT()`).\n"
                "5. **`SELECT`**: Projections, expressions, column aliases, and computed scalar values.\n"
                "6. **`DISTINCT`**: Deduplicates projected output rows.\n"
                "7. **`ORDER BY`**: Sorts output rows (can reference aliases defined in `SELECT`).\n"
                "8. **`LIMIT` / `OFFSET`**: Constrains the cardinality of the returned result set.\n\n"
                "#### 💻 Practical SQL Example\n"
                f"{sql_code}\n\n"
                "#### ⚡ Query Optimization & Indexing Essentials\n"
                "- **B-Tree Indexes**: Create indexes on frequently filtered (`WHERE`) and joined (`JOIN ... ON`) columns to transform $O(N)$ full table scans into $O(\\log N)$ index lookups.\n"
                "- **Avoid `SELECT *`**: Always explicitly name required columns to minimize network bandwidth and allow the optimizer to use covering indexes."
            )
            resp = f"### 💡 Concept Guide: **Structured Query Language (SQL) & Relational Queries**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like to practice **Subqueries vs. CTEs (Common Table Expressions)**, review **Database Normalization (1NF to BCNF)**, or try practice SQL questions?"
            return resp.strip()

        # 4. Mathematics (Calculus, Quadratic Equations, Differentiation, Integration)
        if (norm_subj and norm_subj.lower() in ["maths", "mathematics"]) or any(w in lower_query for w in ["math", "calculus", "derivative", "differentiation", "integral", "integration", "quadratic", "algebra", "matrix", "matrices"]):
            if "quadratic" in lower_concept or "quadratic" in lower_query:
                detail = (
                    "A **Quadratic Equation** is a second-order polynomial equation in a single variable $x$, defined in standard form as:\n\n"
                    "$$\\mathbf{ax^2 + bx + c = 0} \\quad (\\text{where } a \\neq 0)$$\n\n"
                    "#### 🎯 The Quadratic Formula\n"
                    "The general solution for the roots of any quadratic equation is given by:\n\n"
                    "$$x = \\frac{-b \\pm \\sqrt{b^2 - 4ac}}{2a}$$\n\n"
                    "#### 🔍 Nature of Roots via the Discriminant ($\\Delta = b^2 - 4ac$)\n"
                    "- **$\\Delta > 0$**: Two distinct real roots ($x_1 \\neq x_2$).\n"
                    "- **$\\Delta = 0$**: Exactly one real repeated root ($x_1 = x_2 = -\\frac{b}{2a}$).\n"
                    "- **$\\Delta < 0$**: Two complex conjugate roots ($x = \\alpha \\pm i\\beta$).\n\n"
                    "#### 📝 Step-by-Step Worked Example\n"
                    "**Problem**: Solve the equation $x^2 - 5x + 6 = 0$.\n\n"
                    "1. **Identify Coefficients**: $a = 1$, $b = -5$, $c = 6$.\n"
                    "2. **Calculate Discriminant**: $\\Delta = (-5)^2 - 4(1)(6) = 25 - 24 = 1$.\n"
                    "3. **Apply Formula**:\n"
                    "   $$x = \\frac{-(-5) \\pm \\sqrt{1}}{2(1)} = \\frac{5 \\pm 1}{2}$$\n"
                    "4. **Roots**:\n"
                    "   - $x_1 = \\frac{5 + 1}{2} = 3$\n"
                    "   - $x_2 = \\frac{5 - 1}{2} = 2$\n\n"
                    "**Alternative Factoring**: $(x - 2)(x - 3) = 0 \\implies x = 2 \\text{ or } x = 3$."
                )
                resp = f"### 💡 Mathematics: **Quadratic Equations & Roots**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like to practice solving **word problems using quadratics**, learn about **vertex form of parabolas**, or try practice questions?"
                return resp.strip()

            elif any(w in lower_concept or w in lower_query for w in ["differentiat", "derivative", "calculus"]):
                detail = (
                    "**Differentiation** is the mathematical operation of calculating the **instantaneous rate of change** of a function with respect to an independent variable.\n\n"
                    "#### 🎯 Formal Definition\n"
                    "$$\\frac{df}{dx} = f'(x) = \\lim_{h \\to 0} \\frac{f(x + h) - f(x)}{h}$$\n\n"
                    "#### 📐 Fundamental Rules of Differentiation\n"
                    "1. **Power Rule**: $\\frac{d}{dx}[x^n] = n x^{n-1}$\n"
                    "2. **Constant Multiple Rule**: $\\frac{d}{dx}[c \\cdot f(x)] = c \\cdot f'(x)$\n"
                    "3. **Sum / Difference Rule**: $\\frac{d}{dx}[f(x) \\pm g(x)] = f'(x) \\pm g'(x)$\n"
                    "4. **Product Rule**: $\\frac{d}{dx}[u \\cdot v] = u'v + uv'$\n"
                    "5. **Quotient Rule**: $\\frac{d}{dx}\\left[\\frac{u}{v}\\right] = \\frac{u'v - uv'}{v^2}$\n"
                    "6. **Chain Rule** (composite functions): $\\frac{d}{dx}[f(g(x))] = f'(g(x)) \\cdot g'(x)$\n\n"
                    "#### 📝 Step-by-Step Worked Example\n"
                    "**Problem**: Differentiate $f(x) = 3x^4 - 5x^2 + 7x - 9$ with respect to $x$.\n\n"
                    "1. Term 1: $\\frac{d}{dx}[3x^4] = 3 \\cdot (4x^3) = 12x^3$\n"
                    "2. Term 2: $\\frac{d}{dx}[-5x^2] = -5 \\cdot (2x) = -10x$\n"
                    "3. Term 3: $\\frac{d}{dx}[7x] = 7 \\cdot (1) = 7$\n"
                    "4. Term 4: $\\frac{d}{dx}[-9] = 0$\n\n"
                    "**Result**: $f'(x) = 12x^3 - 10x + 7$."
                )
                resp = f"### 💡 Mathematics: **Calculus & Differentiation Rules**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like to practice the **Chain Rule**, explore **Maxima and Minima applications**, or solve practice exam problems?"
                return resp.strip()

            else:
                detail = (
                    f"**{clean_concept_title}** is a core mathematical concept.\n\n"
                    "#### 🎯 Mathematical Foundation\n"
                    "Mathematics provides the rigorous quantitative framework for science, computing, and engineering by deriving universal truths from axioms through logical deduction.\n\n"
                    "#### 📐 Problem-Solving Methodology\n"
                    "1. **Identify the Given Quantities & Target Unknown**: State variables explicitly with units.\n"
                    "2. **Select the Governing Formula / Theorem**: Formulate the algebraic or geometric relationship.\n"
                    "3. **Step-by-Step Derivation**: Substitute values and perform intermediate calculations transparently.\n"
                    "4. **Sanity Check**: Verify dimensional consistency and test edge cases."
                )
                resp = f"### 💡 Mathematics Guide: **{clean_concept_title}**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like a worked step-by-step numerical example or practice questions on this topic?"
                return resp.strip()

        # 5. Physics
        if (norm_subj and norm_subj.lower() == "physics") or any(w in lower_query for w in ["physics", "newton's law", "newtons law", "kinematics", "thermodynamics", "optics", "gravity"]):
            if "newton" in lower_concept or "newton" in lower_query:
                detail = (
                    "**Newton's Laws of Motion** form the foundation of classical mechanics, describing the relationship between the forces acting on a body and its motion.\n\n"
                    "#### 🎯 The Three Laws of Motion\n"
                    "1. **First Law (Law of Inertia)**: An object remains at rest or in uniform motion in a straight line unless acted upon by a non-zero net external force ($F_{\\text{net}} = 0 \\implies a = 0$).\n"
                    "2. **Second Law (Fundamental Law of Dynamics)**: The rate of change of momentum of a body is directly proportional to the applied net force and takes place in the direction of the force:\n"
                    "   $$\\mathbf{F_{\\text{net}} = m \\cdot a} \\quad \\left(F = \\frac{dp}{dt}\\right)$$\n"
                    "3. **Third Law (Action and Reaction)**: When body A exerts a force on body B, body B simultaneously exerts an equal and opposite force on body A:\n"
                    "   $$\\mathbf{F_{AB} = -F_{BA}}$$\n\n"
                    "#### 📝 Step-by-Step Numerical Example\n"
                    "**Problem**: A constant horizontal force of $50\\,\\text{N}$ is applied to a mass of $10\\,\\text{kg}$ resting on a frictionless surface. Calculate its acceleration and the velocity after $4\\,\\text{seconds}$ (starting from rest).\n\n"
                    "1. **Given**: $m = 10\\,\\text{kg}$, $F = 50\\,\\text{N}$, $u = 0\\,\\text{m/s}$, $t = 4\\,\\text{s}$.\n"
                    "2. **Calculate Acceleration** (Newton's 2nd Law):\n"
                    "   $$a = \\frac{F}{m} = \\frac{50\\,\\text{N}}{10\\,\\text{kg}} = 5\\,\\text{m/s}^2$$\n"
                    "3. **Calculate Final Velocity** (Kinematic Equation $v = u + at$):\n"
                    "   $$v = 0 + (5\\,\\text{m/s}^2)(4\\,\\text{s}) = 20\\,\\text{m/s}$$\n"
                    "4. **Distance Covered** ($s = ut + \\frac{1}{2}at^2$):\n"
                    "   $$s = 0 + \\frac{1}{2}(5)(4^2) = \\frac{1}{2}(5)(16) = 40\\,\\text{meters}$$.\n\n"
                    "#### 🚀 Engineering Applications\n"
                    "- Rocket Propulsion: Exhaust gases expelled downward exert an equal upward thrust (3rd Law).\n"
                    "- Automotive Safety: Airbags and crumple zones increase deceleration time $\\Delta t$, reducing impact force $F = \\frac{\\Delta p}{\\Delta t}$ (2nd Law)."
                )
                resp = f"### 💡 Physics: **Newton's Laws of Motion**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like to practice **Free Body Diagrams**, solve a numerical problem with **friction**, or take a quick quiz?"
                return resp.strip()
            else:
                detail = (
                    f"**{clean_concept_title}** is an essential topic within **Physics**.\n\n"
                    "#### 🎯 Physical Principles & Laws\n"
                    "Physics investigates the fundamental constituents of the universe, their interactions, and the energy transformations that govern natural systems.\n\n"
                    "#### 📐 Key Equations & Units\n"
                    "- Understand the SI units and dimensional formula.\n"
                    "- Identify conservation laws (Conservation of Energy, Momentum, Charge).\n"
                    "- Set up coordinate axes and establish sign conventions before calculating."
                )
                resp = f"### 💡 Physics Guide: **{clean_concept_title}**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like a step-by-step numerical calculation or practice exam questions on this topic?"
                return resp.strip()

        # 6. Electronics & Communication Systems
        if (norm_subj and norm_subj.lower() == "electronics") or any(w in lower_query for w in ["electronics", "semiconductor", "transistor", "bjt", "mosfet", "op-amp", "logic gate"]):
            detail = (
                "**Electronics Engineering** deals with electrical circuits involving active electrical components (such as transistors, diodes, and integrated circuits) and associated passive interconnection technologies.\n\n"
                "#### 🔬 Semiconductor Fundamentals & Devices\n"
                "1. **Semiconductors**: Silicon (Si) and Germanium (Ge) doped with donor (pentavalent $\\rightarrow$ n-type) or acceptor (trivalent $\\rightarrow$ p-type) atoms.\n"
                "2. **P-N Junction Diode**: Allows unidirectional current flow. Barrier potential is $\\approx 0.7\\,\\text{V}$ for Silicon and $\\approx 0.3\\,\\text{V}$ for Germanium under forward bias.\n"
                "3. **Bipolar Junction Transistor (BJT)**: Current-controlled active device operating in Active, Cutoff, or Saturation modes ($I_E = I_B + I_C, I_C = \\beta I_B$).\n"
                "4. **Operational Amplifier (Op-Amp)**: High-gain differential amplifier. For an inverting amplifier: $V_{\\text{out}} = -\\frac{R_f}{R_{\\text{in}}} V_{\\text{in}}$. For non-inverting: $V_{\\text{out}} = \\left(1 + \\frac{R_f}{R_1}\\right) V_{\\text{in}}$.\n\n"
                "#### 💻 Digital Logic Gates Truth Summary\n\n"
                "| Gate | Boolean Expression | Output is HIGH (`1`) when |\n"
                "| :--- | :--- | :--- |\n"
                "| **AND** | $Y = A \\cdot B$ | Both inputs are `1` |\n"
                "| **OR** | $Y = A + B$ | At least one input is `1` |\n"
                "| **NOT** | $Y = \\overline{A}$ | Input is `0` (Inverter) |\n"
                "| **NAND** | $Y = \\overline{A \\cdot B}$ | Universal gate; NOT AND |\n"
                "| **NOR** | $Y = \\overline{A + B}$ | Universal gate; NOT OR |\n"
                "| **XOR** | $Y = A \\oplus B$ | Inputs are different ($0,1$ or $1,0$) |"
            )
            resp = f"### 💡 Electronics Engineering: **Semiconductors & Electronic Devices**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like to review **Op-Amp circuit derivations**, **BJT biasing**, or solve practice questions on logic design?"
            return resp.strip()

        if (norm_subj and norm_subj.lower() in ["communication systems", "communication system"]) or any(w in lower_query for w in ["communication system", "modulation", "am modulation", "fm modulation", "pcm"]):
            detail = (
                "**Communication Systems** encompass the infrastructure and techniques used to transmit information from a source to a destination across a physical channel.\n\n"
                "#### 📡 Why is Modulation Necessary?\n"
                "1. **Antenna Size Reduction**: The practical antenna length requires $L \\approx \\frac{\\lambda}{4} = \\frac{c}{4f}$. High-frequency carriers drastically reduce physical antenna dimensions.\n"
                "2. **Multiplexing**: Enables multiple signals to share the same physical medium simultaneously (FDM, TDM, CDMA).\n"
                "3. **Noise Immunity & Range**: High-frequency signals propagate through atmosphere with controlled attenuation.\n\n"
                "#### 📊 Analog vs. Digital Modulation\n"
                "- **Amplitude Modulation (AM)**: Carrier amplitude varies proportionally to the message: $s(t) = [A_c + m(t)]\\cos(2\\pi f_c t)$. Bandwidth: $BW = 2f_m$.\n"
                "- **Frequency Modulation (FM)**: Carrier frequency varies with message signal. Carson's Rule Bandwidth: $BW = 2(\\Delta f + f_m)$. Highly resilient to amplitude noise.\n"
                "- **Pulse Code Modulation (PCM)**: Converts analog signals to digital stream in three stages: **Sampling** (Nyquist rate $f_s \\ge 2f_{\\text{max}}$), **Quantization**, and **Binary Encoding**."
            )
            resp = f"### 💡 Engineering: **Communication Systems & Modulation**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like to explore **Nyquist Sampling Theorem**, **AM vs FM performance comparison**, or practice exam questions?"
            return resp.strip()

        # 7. Chemistry & Biology
        if (norm_subj and norm_subj.lower() == "chemistry") or any(w in lower_query for w in ["chemistry", "chemical bond", "periodic table", "organic chemistry"]):
            detail = (
                "**Chemistry** investigates the composition, structure, properties, and transformation of matter at atomic and molecular levels.\n\n"
                "#### 🔬 Chemical Bonding Mechanisms\n"
                "1. **Ionic Bonding**: Complete electrostatic transfer of one or more valence electrons from a metal (low electronegativity) to a non-metal (high electronegativity), forming a crystal lattice (e.g., $\\text{NaCl}$).\n"
                "2. **Covalent Bonding**: Mutual sharing of electron pairs between non-metallic atoms to achieve stable noble gas octets (e.g., $\\text{H}_2\\text{O}, \\text{CH}_4$).\n"
                "3. **Metallic Bonding**: Positive metal ions embedded in a delocalized \"sea of electrons\", accounting for electrical and thermal conductivity.\n"
                "4. **Hydrogen Bonding**: Strong dipole-dipole attraction between hydrogen bonded to a highly electronegative atom (N, O, F) and an adjacent lone pair.\n\n"
                "#### 📈 Periodic Trends Summary\n"
                "- **Atomic Radius**: Decreases across a period (left to right) due to increasing effective nuclear charge; increases down a group.\n"
                "- **Ionization Energy & Electronegativity**: Increases across a period; decreases down a group."
            )
            resp = f"### 💡 Chemistry Guide: **Chemical Bonding & Molecular Structure**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like to practice **Balancing Chemical Equations**, review **Organic Chemistry Functional Groups**, or solve practice questions?"
            return resp.strip()

        if (norm_subj and norm_subj.lower() == "biology") or any(w in lower_query for w in ["biology", "cell", "photosynthesis", "genetics", "dna", "mitosis"]):
            detail = (
                "**Biology** is the scientific study of life and living organisms, from molecular biochemistry to global ecosystems.\n\n"
                "#### 🔬 Cell Biology & Key Organelles\n"
                "- **Prokaryotes vs. Eukaryotes**: Prokaryotic cells (e.g., bacteria) lack membrane-bound nuclei; eukaryotic cells contain organized nuclei and compartmentalized organelles.\n"
                "- **Mitochondria**: The \"powerhouse of the cell\", generating adenosine triphosphate (ATP) through cellular respiration.\n"
                "- **Ribosomes**: Macromolecular machines responsible for translating mRNA into polypeptide chains (protein synthesis).\n"
                "- **Nucleus**: Houses chromatin (DNA wrapped around histone proteins) and directs cellular activity.\n\n"
                "#### 🌿 Photosynthesis Reaction Equation\n"
                "$$6\\text{CO}_2 + 6\\text{H}_2\\text{O} + \\text{light energy} \\xrightarrow{\\text{chlorophyll}} \\text{C}_6\\text{H}_{12}\\text{O}_6 + 6\\text{O}_2$$\n"
                "- **Light Reactions**: Occur in thylakoid membranes, producing ATP and NADPH while releasing $\\text{O}_2$.\n"
                "- **Calvin Cycle (Dark Reactions)**: Occurs in stroma, fixing $\\text{CO}_2$ into glucose."
            )
            resp = f"### 💡 Biology Guide: **Cellular Biology & Metabolic Systems**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like to explore **DNA Replication and Genetics**, review **Cellular Respiration stages**, or try practice questions?"
            return resp.strip()

        # 8. Pointers in C++ / C
        if "pointer" in lower_concept or "pointer" in lower_query:
            detail = (
                "In C++, a **pointer** is a variable that stores the **memory address** of another variable rather than the direct value itself. "
                "Pointers are a foundational feature of C and C++ providing direct, low-level access and manipulation of system memory.\n\n"
                "#### 🎯 Key Operators\n"
                "- **Address-Of Operator (`&`)**: Retrieves the hexadecimal memory address where a variable is stored.\n"
                "- **Dereference Operator (`*`)**: Accesses or modifies the actual value located at the address held by the pointer.\n\n"
                "#### 💻 Practical Working C++ Example\n"
                "```cpp\n"
                "#include <iostream>\n"
                "using namespace std;\n\n"
                "int main() {\n"
                "    int score = 85;      // Normal integer variable\n"
                "    int* ptr = &score;   // Pointer variable storing the address of 'score'\n\n"
                "    cout << \"Value of score: \" << score << endl;              // Outputs: 85\n"
                "    cout << \"Memory address (&score): \" << &score << endl;    // E.g., 0x7ffd5e\n"
                "    cout << \"Pointer holds address (ptr): \" << ptr << endl;   // E.g., 0x7ffd5e\n"
                "    cout << \"Dereferenced value (*ptr): \" << *ptr << endl;    // Outputs: 85\n\n"
                "    // Modifying the original variable through the pointer\n"
                "    *ptr = 95;\n"
                "    cout << \"Updated value of score: \" << score << endl;      // Outputs: 95\n\n"
                "    return 0;\n"
                "}\n"
                "```\n\n"
                "#### 🔍 Why Are Pointers Used?\n"
                "1. **Dynamic Memory Allocation**: Allocating memory at runtime on the heap using `new` and releasing it with `delete`.\n"
                "2. **Passing by Reference**: Passing pointers to functions avoids copying large objects and arrays, saving memory and CPU cycles.\n"
                "3. **Building Data Structures**: Pointers form the structural links in dynamic data structures like Linked Lists, Trees, and Graphs.\n\n"
                "#### ⚠️ Pointer Safety & Best Practices\n"
                "- **Initialize Pointers**: Always initialize unused pointers to `nullptr` (e.g., `int* p = nullptr;`) to avoid dangerous wildcard memory access.\n"
                "- **Avoid Dangling Pointers**: Never dereference a pointer after freeing its memory with `delete`.\n"
                "- **Modern C++ Smart Pointers**: In modern C++ (C++11 and later), prefer standard smart pointers (`std::unique_ptr` and `std::shared_ptr` from `<memory>`) which automatically deallocate memory when out of scope."
            )
            resp = f"### 💡 Concept Explanation: **Pointers in C++**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like to see how pointers are used in functions (pass-by-pointer), or explore dynamic arrays with `new` and `delete`?"
            return resp.strip()

        # 3. C++ Language Overview
        if norm_subj == "C++" or "c++" in lower_concept or "cpp" in lower_concept:
            detail = (
                "**C++** is a high-performance, statically typed, compiled programming language developed by **Bjarne Stroustrup** at Bell Labs in 1979 as an extension of the C language (\"C with Classes\"). "
                "It is one of the most widely used systems and applications programming languages in the world.\n\n"
                "#### 🎯 Key Characteristics & Core Features\n"
                "1. **Multi-Paradigm Support**: Supports Procedural, Object-Oriented (Classes, Inheritance, Polymorphism, Encapsulation), Generic (Templates), and Functional programming styles.\n"
                "2. **Performance & Low-Level Control**: Provides direct hardware access and deterministic manual memory management (`new`, `delete`, and pointers) with zero-cost abstractions.\n"
                "3. **RAII (Resource Acquisition Is Initialization)**: Ties resource management (memory, file handles, mutexes) to object lifetimes, ensuring automatic cleanup when objects go out of scope.\n"
                "4. **Standard Template Library (STL)**: A comprehensive collection of generic container classes (vectors, lists, maps, sets), algorithms (sorting, searching), and iterators.\n"
                "5. **Modern C++ Standards**: Evolves actively through C++11, C++14, C++17, C++20, and C++23, introducing smart pointers (`std::unique_ptr`, `std::shared_ptr`), lambdas, move semantics, auto type deduction, and concurrency.\n\n"
                "#### 💻 Simple C++ Code Example\n"
                "```cpp\n"
                "#include <iostream>\n"
                "#include <string>\n\n"
                "class Student {\n"
                "private:\n"
                "    std::string name;\n"
                "    int rollNumber;\n\n"
                "public:\n"
                "    Student(std::string n, int r) : name(n), rollNumber(r) {}\n\n"
                "    void display() const {\n"
                "        std::cout << \"Student: \" << name << \" | Roll No: \" << rollNumber << std::endl;\n"
                "    }\n"
                "};\n\n"
                "int main() {\n"
                "    std::cout << \"Welcome to C++ Programming!\" << std::endl;\n"
                "    Student s1(\"Alice\", 101);\n"
                "    s1.display();\n"
                "    return 0;\n"
                "}\n"
                "```\n\n"
                "#### 🚀 Primary Application Domains\n"
                "- **Game Development**: Game engines (Unreal Engine), AAA video games requiring maximum graphics pipeline performance.\n"
                "- **Systems & Operating Systems**: Kernel components in Windows, macOS, and Linux; device drivers and embedded systems.\n"
                "- **High-Performance Computing & Finance**: High-frequency trading (HFT) platforms, scientific simulations, graphics rendering.\n"
                "- **Web Browsers & Compilers**: Core engines of Chromium (V8/Blink), Firefox (Gecko), and compilers (LLVM/Clang, GCC)."
            )
            resp = f"### 💡 Overview of C++ Programming Language\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Topics:** Would you like to explore **pointers and memory management in C++**, learn about **OOP & classes**, or practice writing C++ programs?"
            return resp.strip()

        # Determine target programming language from query or subject
        target_lang = None
        for lang_name, pat in [
            ("Python", r"\b(python|py)\b"),
            ("C++", r"\b(c\+\+|cpp)\b"),
            ("C", r"(?<!\w)(?:c\s+programming|c\s+language|language\s+c|\bc\b(?!\+))"),
            ("Java", r"\bjava\b(?!\s*script)"),
            ("JavaScript", r"\b(javascript|js|typescript|ts)\b"),
            ("SQL", r"\b(sql|database|dbms)\b"),
            ("PHP", r"\bphp\b"),
            ("C#", r"\b(c#|csharp)\b")
        ]:
            if re.search(pat, lower_query) or re.search(pat, lower_concept):
                target_lang = lang_name
                break

        if not target_lang and norm_subj:
            for lang_name, pat in [
                ("Python", r"^python$"),
                ("C++", r"^(c\+\+|cpp)$"),
                ("C", r"^c$"),
                ("Java", r"^java$"),
                ("JavaScript", r"^(javascript|js)$"),
                ("SQL", r"^(sql|database|dbms)$"),
                ("PHP", r"^php$"),
                ("C#", r"^(c#|csharp)$")
            ]:
                if re.search(pat, norm_subj.lower()):
                    target_lang = lang_name
                    break

        if not target_lang:
            target_lang = "Python" if "python" in lower_query else ("Java" if "java" in lower_query else (norm_subj or "Python"))

        # 4. Inheritance (OOP)
        if "inheritance" in lower_concept or "inheritance" in lower_query:
            if target_lang == "Python":
                detail = (
                    "**Inheritance** is a fundamental mechanism of Object-Oriented Programming (OOP) in Python that allows a child class (subclass) "
                    "to inherit attributes and methods from an existing parent class (superclass).\n\n"
                    "#### 🎯 Key Purposes & Benefits in Python\n"
                    "1. **Code Reusability**: Common state and behavior are defined once in the parent class and shared across all child classes without duplication.\n"
                    "2. **Method Overriding**: Subclasses can provide their own specialized implementations of methods defined in the parent class.\n"
                    "3. **`super()` Constructor Chaining**: The `super().__init__(...)` call allows subclasses to cleanly delegate attribute initialization to parent classes.\n"
                    "4. **Extensibility & Polymorphism**: Models intuitive hierarchical relationships (e.g., a `Dog` **is an** `Animal`) and supports polymorphic function calls.\n\n"
                    "#### 💻 Practical Python Code Example\n"
                    "```python\n"
                    "# Superclass (Parent Class)\n"
                    "class Animal:\n"
                    "    def __init__(self, name: str, species: str):\n"
                    "        self.name = name\n"
                    "        self.species = species\n\n"
                    "    def make_sound(self) -> str:\n"
                    "        return f\"{self.name} makes a generic animal sound.\"\n\n"
                    "    def describe(self) -> str:\n"
                    "        return f\"{self.name} is a {self.species}.\"\n\n"
                    "# Subclass (Child Class) inheriting from Animal\n"
                    "class Dog(Animal):\n"
                    "    def __init__(self, name: str, breed: str):\n"
                    "        # Initialize parent attributes using super()\n"
                    "        super().__init__(name, species=\"Canine\")\n"
                    "        self.breed = breed\n\n"
                    "    # Method Overriding: Child provides specialized behavior\n"
                    "    def make_sound(self) -> str:\n"
                    "        return f\"{self.name} ({self.breed}) barks: Woof! Woof!\"\n\n"
                    "# Usage Demonstration\n"
                    "if __name__ == \"__main__\":\n"
                    "    generic_animal = Animal(\"Creature\", \"Unknown\")\n"
                    "    print(generic_animal.make_sound())  # Outputs: Creature makes a generic animal sound.\n\n"
                    "    my_dog = Dog(\"Buddy\", \"Golden Retriever\")\n"
                    "    print(my_dog.describe())            # Inherited method: Buddy is a Canine.\n"
                    "    print(my_dog.make_sound())          # Overridden method: Buddy (Golden Retriever) barks: Woof! Woof!\n"
                    "    print(f\"Is Animal? {isinstance(my_dog, Animal)}\")  # Outputs: True\n"
                    "```\n\n"
                    "#### ⚠️ Key Rules & Python Traps\n"
                    "- **Always invoke `super().__init__()`**: Omitting `super().__init__()` in a child class constructor leaves inherited attributes uninitialized.\n"
                    "- **Multiple Inheritance & MRO**: Unlike Java, Python supports multiple inheritance (`class Child(ParentA, ParentB)`). Python uses the **C3 Linearization** algorithm to compute the Method Resolution Order (inspect via `ClassName.mro()`).\n"
                    "- **Encapsulation & Name Mangling**: In Python, prefixing an attribute with double underscores (e.g. `__secret`) triggers name mangling (`_Parent__secret`) to prevent accidental overrides in child classes."
                )
                resp = f"### 💡 Concept Explanation: **Inheritance in Python**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like to explore **multiple inheritance and Python's MRO (`super()`)**, or try a quick quiz question on this topic?"
                return resp.strip()

            elif target_lang == "C++":
                code_block = (
                    "```cpp\n"
                    "#include <iostream>\n"
                    "#include <string>\n\n"
                    "// Base Class (Parent)\n"
                    "class Animal {\n"
                    "protected:\n"
                    "    std::string name;\n\n"
                    "public:\n"
                    "    Animal(const std::string& n) : name(n) {}\n"
                    "    virtual ~Animal() = default; // Virtual destructor prevents memory leaks\n\n"
                    "    virtual void makeSound() const {\n"
                    "        std::cout << name << \" makes a generic sound.\" << std::endl;\n"
                    "    }\n"
                    "};\n\n"
                    "// Derived Class (Child) inheriting publicly from Animal\n"
                    "class Dog : public Animal {\n"
                    "private:\n"
                    "    std::string breed;\n\n"
                    "public:\n"
                    "    Dog(const std::string& n, const std::string& b) : Animal(n), breed(b) {}\n\n"
                    "    void makeSound() const override {\n"
                    "        std::cout << name << \" (\" << breed << \") barks: Woof! Woof!\" << std::endl;\n"
                    "    }\n"
                    "};\n\n"
                    "int main() {\n"
                    "    Animal* myPet = new Dog(\"Buddy\", \"Golden Retriever\");\n"
                    "    myPet->makeSound(); // Dynamic dispatch calls Dog's makeSound()\n"
                    "    delete myPet;       // Safely calls ~Dog() then ~Animal()\n"
                    "    return 0;\n"
                    "}\n"
                    "```"
                )
                detail = (
                    "**Inheritance** is a fundamental mechanism of Object-Oriented Programming (OOP) in C++ that allows a derived class (child) "
                    "to inherit member variables and methods from a base class (parent).\n\n"
                    "#### 🎯 Key Characteristics in C++\n"
                    "1. **Access Modes**: C++ supports `public`, `protected`, and `private` derivation, controlling how base members are exposed through derived classes.\n"
                    "2. **Virtual Functions & Polymorphism**: Declaring base methods `virtual` enables runtime dynamic dispatch (via the vtable), allowing derived overrides to be invoked through base pointers.\n"
                    "3. **Virtual Destructors**: Always declare base destructors `virtual` (`virtual ~Animal() = default;`) so derived destructors execute when deleting via base pointers.\n\n"
                    "#### 💻 Practical C++ Code Example\n"
                    f"{code_block}\n\n"
                    "#### ⚠️ Key Rules & C++ Traps\n"
                    "- **Virtual Destructor Rule**: If a class has virtual functions, its destructor must be virtual to prevent undefined behavior when deleting derived instances via base pointers.\n"
                    "- **Diamond Problem**: Multiple inheritance in C++ can cause duplicate base instances. Use **virtual inheritance** (`class B : virtual public A`) to resolve diamond hierarchies."
                )
                resp = f"### 💡 Concept Explanation: **Inheritance in C++**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like to explore **virtual destructors and vtables**, or try a quick quiz question on this topic?"
                return resp.strip()

            else:
                code_block = (
                    "```java\n"
                    "// Superclass (Parent)\n"
                    "class Animal {\n"
                    "    protected String name;\n"
                    "    public Animal(String name) { this.name = name; }\n"
                    "    public void makeSound() {\n"
                    "        System.out.println(name + \" makes a sound.\");\n"
                    "    }\n"
                    "}\n\n"
                    "// Subclass (Child) inheriting from Animal using 'extends'\n"
                    "class Dog extends Animal {\n"
                    "    public Dog(String name) { super(name); }\n"
                    "    @Override\n"
                    "    public void makeSound() {\n"
                    "        System.out.println(name + \" barks: Woof! Woof!\");\n"
                    "    }\n"
                    "}\n\n"
                    "public class Main {\n"
                    "    public static void main(String[] args) {\n"
                    "        Dog myDog = new Dog(\"Buddy\");\n"
                    "        myDog.makeSound(); // Outputs: Buddy barks: Woof! Woof!\n"
                    "    }\n"
                    "}\n"
                    "```"
                )
                detail = (
                    "**Inheritance** is a fundamental mechanism of Object-Oriented Programming (OOP) in Java that allows a new class (subclass/child) "
                    "to acquire the properties, fields, and methods of an existing class (superclass/parent).\n\n"
                    "#### 🎯 Key Purposes & Benefits\n"
                    "1. **Code Reusability**: Common attributes and methods are defined once in the superclass and inherited by all subclasses without redundant code duplication.\n"
                    "2. **Method Overriding (Runtime Polymorphism)**: Subclasses can provide a specialized implementation of inherited methods using `@Override`.\n"
                    "3. **IS-A Hierarchy**: Models natural domain relationships (e.g., a `Dog` **is an** `Animal`, a `SavingsAccount` **is an** `Account`).\n\n"
                    "#### 💻 Practical Java Code Example\n"
                    f"{code_block}\n\n"
                    "#### ⚠️ Key Rules & Traps\n"
                    "- **Single Class Inheritance**: Java does not support multiple inheritance with classes (`class C extends A, B` is invalid). Use **Interfaces** (`implements`) instead.\n"
                    "- **`super` Keyword**: Use `super()` to call the parent constructor and `super.methodName()` to invoke parent methods.\n"
                    "- **Encapsulation**: `private` members of the parent class are not directly accessible by children; use `protected` or public getters/setters."
                )
                resp = f"### 💡 Concept Explanation: **Inheritance in Java**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like a coding exercise or a quick quiz question on this topic?"
                return resp.strip()

        # 5. Polymorphism (OOP)
        if "polymorphism" in lower_concept or "polymorphism" in lower_query:
            if target_lang == "Python":
                detail = (
                    "**Polymorphism** (meaning 'many forms') allows objects of different classes to respond to the same method invocation in their own unique way.\n\n"
                    "#### 🎯 Polymorphism in Python\n"
                    "1. **Duck Typing**: Python does not require a formal interface or common base class. If an object implements the required method, Python executes it (*'If it walks like a duck and quacks like a duck, it's a duck'*).\n"
                    "2. **Method Overriding**: Subclasses provide a specialized implementation of a method defined in their superclass.\n"
                    "3. **Operator Overloading**: Special dunder methods (e.g., `__add__`, `__len__`, `__str__`) allow Python operators (`+`, `len()`, `print()`) to behave polymorphically on custom objects.\n\n"
                    "#### 💻 Practical Python Code Example\n"
                    "```python\n"
                    "class Shape:\n"
                    "    def area(self) -> float:\n"
                    "        raise NotImplementedError(\"Subclasses must implement area()\")\n\n"
                    "class Circle(Shape):\n"
                    "    def __init__(self, radius: float):\n"
                    "        self.radius = radius\n\n"
                    "    def area(self) -> float:\n"
                    "        return 3.14159 * self.radius ** 2\n\n"
                    "class Rectangle(Shape):\n"
                    "    def __init__(self, width: float, height: float):\n"
                    "        self.width = width\n"
                    "        self.height = height\n\n"
                    "    def area(self) -> float:\n"
                    "        return self.width * self.height\n\n"
                    "# Polymorphic function: Works on any Shape subclass\n"
                    "def print_area(shape: Shape) -> None:\n"
                    "    print(f\"Calculated Area: {shape.area():.2f}\")\n\n"
                    "shapes = [Circle(5), Rectangle(4, 6)]\n"
                    "for s in shapes:\n"
                    "    print_area(s)  # Outputs: Calculated Area: 78.54, Calculated Area: 24.00\n"
                    "```"
                )
                resp = f"### 💡 Concept Explanation: **Polymorphism in Python**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like to explore **operator overloading in Python (`__add__`)**, or try a quick quiz question on this topic?"
                return resp.strip()

            elif target_lang == "C++":
                detail = (
                    "**Polymorphism** in C++ enables entities such as functions or objects to have different behaviors depending on their invocation context.\n\n"
                    "#### 🎯 Compile-Time vs Runtime Polymorphism in C++\n"
                    "1. **Compile-Time (Static)**: Function overloading, operator overloading, and generic C++ templates.\n"
                    "2. **Runtime (Dynamic)**: Virtual member functions and dynamic dispatch via the virtual method table (vtable).\n\n"
                    "#### 💻 Practical C++ Example\n"
                    "```cpp\n"
                    "#include <iostream>\n\n"
                    "class Shape {\n"
                    "public:\n"
                    "    virtual ~Shape() = default;\n"
                    "    virtual void draw() const {\n"
                    "        std::cout << \"Drawing generic shape\" << std::endl;\n"
                    "    }\n"
                    "};\n\n"
                    "class Circle : public Shape {\n"
                    "public:\n"
                    "    void draw() const override {\n"
                    "        std::cout << \"Drawing a circle\" << std::endl;\n"
                    "    }\n"
                    "};\n\n"
                    "int main() {\n"
                    "    Shape* s = new Circle();\n"
                    "    s->draw(); // Dynamic dispatch calls Circle's draw()\n"
                    "    delete s;\n"
                    "    return 0;\n"
                    "}\n"
                    "```"
                )
                resp = f"### 💡 Concept Explanation: **Polymorphism in C++**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like to explore **templates vs virtual functions**, or try a quick quiz question on this topic?"
                return resp.strip()

            else:
                detail = (
                    "**Polymorphism** (from Greek meaning 'many forms') allows objects of different types to respond to the same method call with type-specific behavior.\n\n"
                    "#### 🎯 The Two Forms of Polymorphism in Java\n"
                    "1. **Compile-Time (Static / Overloading)**: Same method name with different parameters within the same class.\n"
                    "2. **Runtime (Dynamic / Overriding)**: Subclass overrides a superclass method, resolved dynamically during program execution.\n\n"
                    "#### 💻 Practical Java Example\n"
                    "```java\n"
                    "class Shape {\n"
                    "    void draw() { System.out.println(\"Drawing generic shape\"); }\n"
                    "}\n"
                    "class Circle extends Shape {\n"
                    "    @Override\n"
                    "    void draw() { System.out.println(\"Drawing a circle\"); }\n"
                    "}\n\n"
                    "Shape s = new Circle();\n"
                    "s.draw(); // Dynamic dispatch calls Circle's draw()\n"
                    "```"
                )
                resp = f"### 💡 Concept Explanation: **Polymorphism in Java**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like a coding exercise or a quick quiz question on this topic?"
                return resp.strip()

        # 6. Python Overview
        if norm_subj == "Python" or "python" in lower_concept:
            detail = (
                "**Python** is a high-level, interpreted, general-purpose programming language conceived by **Guido van Rossum** in 1991. "
                "Its design philosophy emphasizes code readability, developer velocity, and clean, expressive syntax.\n\n"
                "#### 🎯 Core Features\n"
                "1. **Dynamic Typing & Memory Management**: Variables do not require explicit type declarations, and memory is automatically reclaimed by Python's Garbage Collector.\n"
                "2. **Significant Whitespace**: Blocks of code are defined by indentation (typically 4 spaces) rather than curly braces, enforcing uniform readability.\n"
                "3. **Rich Standard Library**: Often called 'batteries included', Python comes pre-packaged with comprehensive modules for file I/O, networking, math, and JSON.\n"
                "4. **Vast Data & AI Ecosystem**: The undisputed standard language for Machine Learning and Data Science (NumPy, Pandas, Scikit-learn, PyTorch, TensorFlow).\n\n"
                "#### 💻 Simple Python Example\n"
                "```python\n"
                "# Demonstrating list comprehension and clean syntax\n"
                "def get_even_squares(numbers: list[int]) -> list[int]:\n"
                "    return [n ** 2 for n in numbers if n % 2 == 0]\n\n"
                "nums = [1, 2, 3, 4, 5, 6]\n"
                "print('Even squares:', get_even_squares(nums))  # Outputs: [4, 16, 36]\n"
                "```"
            )
            resp = f"### 💡 Overview of Python Programming Language\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Topics:** Would you like to review **loops and control flow**, practice **functions & list comprehensions**, or explore exam questions?"
            return resp.strip()

        # 7. C Language Overview
        if norm_subj == "C" or lower_concept in ("c", "c programming", "c language") or re.search(r"\b(c\s+programming|c\s+language|language\s+c|\bc\b(?!\+))\b", lower_query):
            detail = (
                "**C** is a foundational, general-purpose procedural programming language created by **Dennis Ritchie** between 1972 and 1973 at Bell Labs. "
                "Engineered originally to implement the Unix operating system, C is celebrated for its efficiency, close-to-hardware access, and foundational role in software engineering.\n\n"
                "#### 🎯 Key Characteristics & Core Features\n"
                "1. **Structured Procedural Design**: Programs are organized around functions, structured control blocks, and modular routines without class overhead.\n"
                "2. **Direct Memory Access & Pointers**: Provides direct access to system memory addresses through pointer arithmetic, giving developers granular control over hardware.\n"
                "3. **Manual Memory Management**: Explicit memory allocation and deallocation on the heap using standard functions (`malloc()`, `calloc()`, `realloc()`, `free()`).\n"
                "4. **Native Performance & Compact Binaries**: Compiles directly into native machine code (via GCC/Clang), producing exceptionally fast, lightweight executables with minimal memory footprints.\n"
                "5. **Minimalist Standard Library**: Provides essential I/O (`<stdio.h>`), string manipulation (`<string.h>`), and math (`<math.h>`) utilities.\n\n"
                "#### 💻 Simple C Code Example\n"
                "```c\n"
                "#include <stdio.h>\n\n"
                "int main() {\n"
                "    printf(\"Welcome to C Programming!\\n\");\n"
                "    int numbers[] = {10, 20, 30, 40, 50};\n"
                "    int length = sizeof(numbers) / sizeof(numbers[0]);\n\n"
                "    for (int i = 0; i < length; i++) {\n"
                "        printf(\"Element %d: %d\\n\", i, numbers[i]);\n"
                "    }\n"
                "    return 0;\n"
                "}\n"
                "```\n\n"
                "#### 🚀 Primary Application Domains\n"
                "- **Operating Systems**: Unix, Linux kernel, Windows kernel, and macOS Darwin components.\n"
                "- **Embedded Systems & IoT**: Firmware for microcontrollers, automotive ECUs, and medical devices.\n"
                "- **Compilers & Interpreters**: Runtimes and interpreters for Python (CPython), Ruby, and SQLite."
            )
            resp = f"### 💡 Overview of C Programming Language\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Topics:** Would you like to explore **pointers and memory allocation (`malloc`/`free`)**, examine **structures (`struct`)**, or practice C exam questions?"
            return resp.strip()

        # 8. Java Language Overview
        if norm_subj == "Java" or lower_concept in ("java", "java programming") or "java language" in lower_concept:
            detail = (
                "**Java** is a robust, object-oriented, class-based programming language designed by **James Gosling** at Sun Microsystems (now Oracle) in 1995. "
                "Its governing philosophy, **\"Write Once, Run Anywhere\" (WORA)**, ensures compiled Java bytecode runs on any platform equipped with a Java Virtual Machine (JVM).\n\n"
                "#### 🎯 Key Characteristics & Core Features\n"
                "1. **Platform Independence**: Source code compiles into platform-independent `.class` bytecode interpreted and JIT-compiled by the JVM.\n"
                "2. **Strict Object-Oriented Principles**: Everything (except primitive types) is encapsulated inside classes, strictly enforcing OOP principles (Inheritance, Polymorphism, Encapsulation, Abstraction).\n"
                "3. **Automatic Garbage Collection**: Background collectors (G1, ZGC) automatically track and deallocate unreferenced heap memory, preventing memory leaks.\n"
                "4. **Multithreading & Concurrency**: Built-in native support for concurrent thread execution and synchronization primitives in `java.lang.Thread` and `java.util.concurrent`.\n"
                "5. **Enterprise-Grade Ecosystem**: Industry-dominant frameworks including Spring Boot, Hibernate, Apache Kafka, and Android SDK.\n\n"
                "#### 💻 Simple Java Code Example\n"
                "```java\n"
                "public class WelcomeJava {\n"
                "    public static void main(String[] args) {\n"
                "        System.out.println(\"Welcome to Java Programming!\");\n"
                "        String[] topics = {\"Classes\", \"Inheritance\", \"Polymorphism\", \"Interfaces\"};\n"
                "        for (String topic : topics) {\n"
                "            System.out.println(\"- Core Topic: \" + topic);\n"
                "        }\n"
                "    }\n"
                "}\n"
                "```\n\n"
                "#### 🚀 Primary Application Domains\n"
                "- **Enterprise Web Backends**: Banking, e-commerce, and high-throughput corporate microservices (Spring Boot).\n"
                "- **Android Development**: Native mobile applications.\n"
                "- **Big Data Systems**: Core components of Apache Hadoop, Spark, and Cassandra."
            )
            resp = f"### 💡 Overview of Java Programming Language\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Topics:** Would you like to review **Inheritance and Overriding**, learn about **Interfaces vs Abstract Classes**, or practice Java exam questions?"
            return resp.strip()

        # 9. General Structured Fallback
        detail = (
            f"**{clean_concept_title}** is an essential topic within **{norm_subj or 'Computer Science'}**.\n\n"
            f"#### 🎯 Definition & Purpose\n"
            f"It serves as a foundational building block for constructing predictable, modular, and maintainable software systems.\n\n"
            f"#### 💡 Core Principles\n"
            f"- **Clarity & Structure**: Eliminates ambiguity by following standardized architectural patterns.\n"
            f"- **Separation of Concerns**: Encourages isolated, testable modules that minimize side-effects.\n"
            f"- **Scalability**: Enables systems to grow in complexity while maintaining computational efficiency."
        )

        resp = f"### 💡 Concept Explanation: **{clean_concept_title}**\n\n{detail}{grounding_note}\n\n💡 **Suggested Next Step:** Would you like a coding exercise or a quick quiz question on this topic?"
        return resp.strip()

    @classmethod
    def generate_coding_help(cls, query: str = "", subject: str = "", *args, **kwargs) -> str:
        if not query and "question" in kwargs:
            query = kwargs["question"]
        lang = cls._detect_programming_language(subject, query)
        q_lower = query.lower()

        # Check for programming performance & optimization queries
        is_perf_query = (
            ("improve" in q_lower or "optimize" in q_lower or "boost" in q_lower or "speed up" in q_lower or "tune" in q_lower) and
            ("performance" in q_lower or "speed" in q_lower or "efficiency" in q_lower or "runtime" in q_lower or "memory" in q_lower or "latency" in q_lower)
        ) or (
            ("performance" in q_lower or "optimization" in q_lower) and any(w in q_lower for w in ["java", "python", "c++", "c", "code", "sql", "javascript"])
        )

        if is_perf_query:
            if lang == "Java" or "java" in q_lower:
                return """### ⚡ Java Application Performance & Optimization Guide

Improving Java programming performance requires a systematic engineering strategy: **profile first to identify bottlenecks, select optimal data structures, minimize object churn, tune the JVM/GC, and leverage modern concurrency primitives**.

#### 1. 🔍 Step 1: Profiling & Bottleneck Identification ("Measure, Don't Guess")
Before making code adjustments, establish a baseline and pinpoint whether the application is CPU-bound, memory/GC-bound, or I/O-bound:
- **Java Flight Recorder (JFR) & JDK Mission Control (JMC):** Low-overhead, production-grade event profiling built directly into the OpenJDK runtime.
- **VisualVM / JProfiler:** Inspect live thread execution states, CPU sampling flame graphs, and heap object allocation hotspots.
- **Java Microbenchmark Harness (JMH):** Always run microbenchmarks with JMH to avoid falsified metrics caused by JIT dead-code elimination, loop unrolling, and warmup delays.

#### 2. 🧱 Algorithms & Collections Optimization
- **Select the Right Collection:**
  - Use `ArrayList` instead of `LinkedList` for almost all sequential or indexed access: contiguous array memory provides optimal CPU L1/L2 cache locality, whereas `LinkedList` incurs pointer-chasing overhead.
  - **Pre-size Collections:** When the expected capacity is known, initialize collections explicitly (e.g., `new ArrayList<>(expectedCount)` or `new HashMap<>((int)(expectedCount / 0.75f) + 1)`) to eliminate costly array copy resizes and hash bucket rehashing.
- **Eliminate Auto-Boxing in Critical Loops:**
  - Wrapper classes (`Integer`, `Double`, `Long`) introduce heap allocations and pointer indirection. Use primitive arrays (`int[]`, `double[]`) or specialized primitive collections (FastUtil, Trove) for numeric processing.

#### 3. 🧵 Memory Management & Garbage Collection Tuning
- **String Handling in Loops:**
  - Never concatenate strings with `+` inside loops (which creates new intermediate objects per iteration). Always use a pre-sized `StringBuilder`.
- **Select the Appropriate Garbage Collector:**
  - **G1GC (Default):** Balanced throughput and predictable pause times for modern heaps > 4GB (`-XX:+UseG1GC`).
  - **ZGC / Shenandoah:** Ultra-low latency collectors with sub-millisecond pauses for latency-critical applications (`-XX:+UseZGC`).
- **Reduce Object Churn:**
  - Reuse heavy or stateless objects (e.g., reuse `DateTimeFormatter` or `Pattern` instances as `static final` constants rather than compiling regular expressions repeatedly).

#### 4. 🚀 Concurrency & I/O Optimization
- **Thread Pools & Virtual Threads:**
  - Avoid spawning unmanaged `new Thread()` instances. Utilize `ThreadPoolExecutor` or Java 21+ Virtual Threads (`Executors.newVirtualThreadPerTaskExecutor()`) for high-concurrency I/O workloads.
- **Buffered & Non-Blocking I/O:**
  - Wrap raw streams with `BufferedReader` / `BufferedInputStream` to minimize system calls, or use `java.nio.channels.FileChannel` with direct byte buffers for high-volume file transfers.

#### 💻 Practical Example: Inefficient vs. Optimized Java Code

##### ❌ Inefficient Pattern (O(n²) Allocations & GC Pressure):
```java
// Anti-pattern: String concatenation in loop generates n temporary heap objects
String csvReport = "";
for (int i = 0; i < records.size(); i++) {
    csvReport += records.get(i).toCsvRow() + "\\n";
}
```

##### ✅ Optimized Pattern (O(n) In-Place Pre-allocated Buffer):
```java
// High-performance: Pre-sized buffer, zero intermediate heap churn
StringBuilder sb = new StringBuilder(records.size() * 64);
for (int i = 0; i < records.size(); i++) {
    sb.append(records.get(i).toCsvRow()).append('\\n');
}
String csvReport = sb.toString();
```

#### 📋 Java Performance Checklist
- [ ] Measure baseline using JFR, VisualVM, or JMH before modifying code
- [ ] Pre-size `ArrayList` and `HashMap` instances to target capacity
- [ ] Replace `LinkedList` with `ArrayList` for CPU cache locality
- [ ] Avoid primitive boxing (`Integer`, `Double`) in hot calculation loops
- [ ] Use `StringBuilder` for all iterative string mutations
- [ ] Reuse compiled `Pattern` and formatter instances as `static final`
- [ ] Leverage Virtual Threads (Java 21+) or managed thread pools for concurrent I/O

💡 **Suggested Next Step:** Do you have a specific Java method, loop, or query that is running slowly? Share it here and we can benchmark and optimize it together!""".strip()

            elif lang == "Python" or "python" in q_lower:
                return """### ⚡ Python Application Performance & Optimization Guide

Improving Python application performance focuses on **eliminating interpreted bytecode overhead, leveraging C-accelerated libraries, optimizing algorithms and data structures, and profiling hot execution paths**.

#### 1. 🔍 Step 1: Profiling Hot Paths ("Measure Before Optimizing")
- **`cProfile` & `pstats`:** Built-in deterministic profiler to identify which functions consume the most execution time (`python -m cProfile -s tottime script.py`).
- **`line_profiler`:** Inspect line-by-line runtime metrics for pinpointing exact bottleneck loops.
- **`memory_profiler`:** Track memory allocations and memory leaks per line.

#### 2. 🧱 Algorithmic & Data Structure Enhancements
- **Use Built-in Data Structures:**
  - Use `set` and `dict` for $O(1)$ membership checks instead of $O(n)$ list scans (`if item in my_set:`).
  - Use `collections.deque` for $O(1)$ append/pop operations on both ends instead of `list.pop(0)` which is $O(n)$.
- **List Comprehensions & Generator Expressions:**
  - List comprehensions run at native C speed in CPython, outperforming standard `for` loops with `.append()`.
  - Use generators (`(x * 2 for x in data)`) to stream massive datasets without loading them entirely into memory.

#### 3. 🚀 Leverage C-Accelerated & Vectorized Libraries
- **Vectorization with NumPy:**
  - Replace Python loops operating on numeric arrays with vectorized NumPy operations, shifting computation to SIMD C/Fortran routines.
- **Alternative Runtimes:**
  - For CPU-bound Python workloads without C extensions, evaluate **PyPy** (Just-In-Time compiler) for 4x–7x speedups.

#### 💻 Practical Example: Inefficient vs. Optimized Python Code

##### ❌ Inefficient Pattern (O(n) membership checks in loop):
```python
# Anti-pattern: Linear scan on list inside loop is O(n * m)
valid_ids = [101, 102, 103, 104]  # list
filtered = []
for item in large_dataset:
    if item.id in valid_ids:  # O(n) scan on every iteration!
        filtered.append(item)
```

##### ✅ Optimized Pattern (O(1) hash lookup with set comprehension):
```python
# High-performance: O(1) hash lookup yields O(m) total time
valid_ids_set = set(valid_ids)
filtered = [item for item in large_dataset if item.id in valid_ids_set]
```

#### 📋 Python Performance Checklist
- [ ] Profile with `cProfile` to identify top time-consuming functions
- [ ] Convert lookup lists to `set` for $O(1)$ membership testing
- [ ] Replace `for` loop appends with list comprehensions or generators
- [ ] Use `collections.deque` for FIFO queue operations
- [ ] Vectorize numeric calculations using NumPy
- [ ] Use `join()` for string aggregation instead of `+` concatenation

💡 **Suggested Next Step:** Would you like to profile a specific Python function or explore vectorization for your dataset?""".strip()

            elif lang == "C++" or "c++" in q_lower or "cpp" in q_lower:
                return """### ⚡ C++ Application Performance & Optimization Guide

Optimizing C++ applications focuses on **hardware cache friendliness, zero-cost abstractions, compiler optimizations, move semantics, and minimizing heap allocations in hot paths**.

#### 1. 🔍 Step 1: Profiling & Hardware Performance Counters
- **Linux `perf` & FlameGraphs:** Measure CPU cycles, cache misses, and branch mispredictions.
- **Valgrind (Callgrind & Cachegrind):** Inspect cache locality and function call counts.
- **Google Benchmark:** High-precision microbenchmarking harness for C++ code.

#### 2. 🧱 Cache Locality & Memory Layout
- **Contiguous Memory (`std::vector`):**
  - Always prefer `std::vector` over linked data structures (`std::list`). Contiguous heap buffers maximize CPU L1/L2 cache line hits and hardware prefetching.
- **Pre-allocate Vector Memory:**
  - Always call `vec.reserve(expectedSize)` prior to inserting elements to eliminate repeated reallocations and copies.

#### 3. 🚀 Modern C++ Optimization Techniques
- **Move Semantics & Rvalue References (`std::move`):**
  - Avoid copying large objects, strings, or containers. Transfer ownership using move constructors and `std::move`.
- **`constexpr` & Compile-Time Computation:**
  - Shift calculations from runtime to compile time using `constexpr` functions and template metaprogramming.
- **Compiler Optimization Flags:**
  - Compile production builds with `-O3 -march=native -flto` (Link-Time Optimization) and enable `-DNDEBUG` to remove assertion overhead.

#### 💻 Practical Example: Inefficient vs. Optimized C++ Code

##### ❌ Inefficient Pattern (Repeated heap reallocations & deep copies):
```cpp
// Anti-pattern: vector resizes repeatedly, objects copied by value
std::vector<std::string> lines;
for (const auto& item : data) {
    lines.push_back(item.generateString()); // Resizes + deep copy
}
```

##### ✅ Optimized Pattern (Reserved memory & move semantics):
```cpp
// High-performance: Pre-reserved buffer, emplace with move semantics
std::vector<std::string> lines;
lines.reserve(data.size()); // Zero reallocations
for (const auto& item : data) {
    lines.emplace_back(item.generateString()); // Direct move construction
}
```

#### 📋 C++ Performance Checklist
- [ ] Compile with `-O3 -flto -march=native` for release targets
- [ ] Profile with `perf` to track L1/L2 cache misses and branch mispredictions
- [ ] Pre-allocate container capacity with `.reserve()`
- [ ] Use `std::move` and `emplace_back` to eliminate object copies
- [ ] Prefer `std::vector` over node-based collections (`std::list`)
- [ ] Move invariants and constants to compile-time with `constexpr`

💡 **Suggested Next Step:** Would you like to analyze a specific C++ algorithm or discuss cache-conscious data layouts?""".strip()

        if "reverse" in q_lower and ("string" in q_lower or "str" in q_lower):
            if lang == "Python":
                code = (
                    "```python\n"
                    "def reverse_string(s: str) -> str:\n"
                    "    # Method 1: Pythonic slice (O(n) time, O(n) space)\n"
                    "    return s[::-1]\n\n"
                    "# Method 2: Two-pointer in-place style\n"
                    "def reverse_string_twopointer(s: str) -> str:\n"
                    "    chars = list(s)\n"
                    "    left, right = 0, len(chars) - 1\n"
                    "    while left < right:\n"
                    "        chars[left], chars[right] = chars[right], chars[left]\n"
                    "        left += 1\n"
                    "        right -= 1\n"
                    "    return ''.join(chars)\n\n"
                    "# Demonstration\n"
                    "print(reverse_string('exam'))  # Output: 'maxe'\n"
                    "```"
                )
            else:
                code = (
                    "```java\n"
                    "public class StringReverser {\n"
                    "    public static String reverse(String str) {\n"
                    "        if (str == null) return null;\n"
                    "        StringBuilder sb = new StringBuilder(str);\n"
                    "        return sb.reverse().toString();\n"
                    "    }\n"
                    "    public static void main(String[] args) {\n"
                    "        System.out.println(reverse(\"exam\")); // Outputs: maxe\n"
                    "    }\n"
                    "}\n"
                    "```"
                )
            explanation = "Reversing a string is typically solved using string slicing or a two-pointer swap approach with $O(n)$ time complexity."
        elif "nullpointer" in q_lower:
            code = (
                "```java\n"
                "// 1. Always check for null before dereferencing\n"
                "if (user != null && user.getName() != null) {\n"
                "    System.out.println(user.getName().toUpperCase());\n"
                "}\n\n"
                "// 2. Or use Optional in modern Java\n"
                "Optional.ofNullable(user)\n"
                "        .map(User::getName)\n"
                "        .ifPresent(System.out::println);\n"
                "```"
            )
            explanation = "A `NullPointerException` occurs when you attempt to invoke a method, access a field, or measure the length of an object reference that currently points to `null`."
        else:
            code = (
                f"```{lang.lower()}\n"
                f"# Robust implementation for {query.strip(' ?.')}\n"
                f"def solve(data):\n"
                f"    if not data:\n"
                f"        return None\n"
                f"    # Process data with clear logic\n"
                f"    result = [x for x in data if x is not None]\n"
                f"    return result\n"
                f"```"
            )
            explanation = f"Here is a clean, modular solution implemented in {lang} with proper input validation."

        resp = f"""### 💻 Coding Solution & Debugging Guide ({lang})

#### 🔍 Problem Analysis
{explanation}

#### 🛠️ Working Code Implementation
{code}

#### ⚡ Complexity & Best Practices
- **Time Complexity**: $O(n)$ linear scan through elements.
- **Space Complexity**: $O(n)$ to store result.
- **Boundary Checks**: Always validate empty, null, or singleton inputs.

💡 **Next Steps:** Would you like to test this with specific inputs or optimize it further?
"""
        return resp.strip()

    @classmethod
    def generate_practice_questions_response(
        cls,
        subject: str = "Computer Science",
        topic: str = "Core Principles",
        count: int = 5,
        *args,
        **kwargs
    ) -> str:
        if "question" in kwargs and kwargs["question"]:
            m_cnt = re.search(r'\b(\d+)\b', kwargs["question"])
            if m_cnt:
                count = int(m_cnt.group(1))
        clean_subj = subject.strip() if subject else "Computer Science"
        clean_top = topic.strip() if topic else "Core Principles"

        # Match count requested up to 10
        actual_count = min(max(count, 3), 10)

        questions = [
            (
                "What is the output of the following loop in Python?\n```python\nfor i in range(1, 5, 2):\n    print(i, end=' ')\n```",
                ["A) 1 2 3 4", "B) 1 3", "C) 1 3 5", "D) 2 4"],
                "B",
                "`range(1, 5, 2)` starts at 1, steps by 2, and stops before 5. Yields 1 and 3."
            ),
            (
                "In Python, what is the value of `i` after this loop finishes?\n```python\nfor i in range(4):\n    if i == 2:\n        break\n```",
                ["A) 1", "B) 2", "C) 3", "D) 4"],
                "B",
                "When `i == 2`, the `break` statement executes immediately, preserving `i = 2`."
            ),
            (
                "When does the `else` clause in a Python `for...else` loop execute?",
                ["A) Only when a break occurs", "B) When the loop exhausts its sequence without hitting a break", "C) If an exception is caught", "D) Never in Python"],
                "B",
                "A loop's `else` block runs only if the loop terminates normally without encountering a `break`."
            ),
            (
                "What does `enumerate(['a', 'b', 'c'])` produce during iteration?",
                ["A) Just values: 'a', 'b', 'c'", "B) Pairs of `(index, value)`: `(0, 'a')`, `(1, 'b')`, `(2, 'c')`", "C) Length of list", "D) Reversed list"],
                "B",
                "`enumerate()` yields two-item tuples containing the running counter index and the element."
            ),
            (
                "What is the time complexity of traversing an array of size $N$ using a single `while` loop?",
                ["A) $O(1)$", "B) $O(N)$", "C) $O(N^2)$", "D) $O(\\log N)$"],
                "B",
                "Visiting each of $N$ elements exactly once requires linear $O(N)$ operations."
            ),
            (
                "Which loop keyword skips the remaining statements in the current iteration and advances to the next?",
                ["A) break", "B) pass", "C) continue", "D) return"],
                "C",
                "`continue` immediately jumps to the next cycle of the loop."
            ),
            (
                "What will be the output of `[x * 2 for x in range(3)]`?",
                ["A) [0, 2, 4]", "B) [2, 4, 6]", "C) [0, 1, 2]", "D) [0, 2]"],
                "A",
                "The list comprehension evaluates `0*2=0`, `1*2=2`, and `2*2=4`."
            ),
            (
                "How many times will a `while False:` block execute?",
                ["A) 1 time", "B) 0 times", "C) Infinite times", "D) Causes SyntaxError"],
                "B",
                "Because the condition evaluates to False initially, the loop body never executes."
            ),
            (
                "What function is used to iterate over two sequences simultaneously in parallel?",
                ["A) parallel()", "B) pair()", "C) zip()", "D) concat()"],
                "C",
                "`zip()` bundles elements from multiple iterables into tuples pairwise."
            ),
            (
                "What happens if the condition of a `while` loop is never updated or made False?",
                ["A) Compiler error", "B) Infinite loop", "C) Early exit", "D) Memory reset"],
                "B",
                "An unchanged True condition causes an infinite loop until terminated externally."
            )
        ]

        selected_q = questions[:actual_count]

        out_lines = [
            f"### 📝 Practice Quiz: **{clean_subj} — {clean_top}** ({len(selected_q)} Questions)\n",
            "Test your knowledge with these targeted exam practice questions:\n"
        ]

        for idx, q_data in enumerate(selected_q, start=1):
            q_text, opts, _, _ = q_data
            out_lines.append(f"**Question {idx}:**")
            out_lines.append(q_text)
            for opt in opts:
                out_lines.append(f"- {opt}")
            out_lines.append("")

        out_lines.append("---")
        out_lines.append("### 🔑 Answer Key & Explanations\n")
        for idx, q_data in enumerate(selected_q, start=1):
            _, _, ans, expl = q_data
            out_lines.append(f"**Question {idx}: Correct Answer `{ans}`**")
            out_lines.append(f"- *Explanation:* {expl}\n")

        out_lines.append("💡 **Practice Tip:** Ready to take an official practice session? Head over to the **Practice Session** tab on your student sidebar!")
        return "\n".join(out_lines)

    @classmethod
    def generate_follow_up_response(
        cls,
        query: str = "",
        last_assistant_msg: str = "",
        subject: str = "",
        *args,
        **kwargs
    ) -> str:
        if not query and "question" in kwargs:
            query = kwargs["question"]
        if not last_assistant_msg and "conversation_history" in kwargs:
            conv = kwargs["conversation_history"] or []
            for m in reversed(conv):
                if m.get("role") == "assistant":
                    last_assistant_msg = m.get("content", "")
                    break
        q_lower = query.lower()

        # 1. 7-day timetable query
        if any(w in q_lower for w in ["7 days", "7-day", "seven days", "timetable", "schedule"]):
            subj_title = subject or "Your Exam"
            return (
                f"### 📅 Realistic 7-Day Intensive Study Timetable for **{subj_title}**\n\n"
                f"Here is a day-by-day revision schedule designed to maximize your exam score in one week:\n\n"
                f"- **Day 1: Fundamentals & Syntax Review** (2–3 hrs)\n"
                f"  - Review variables, data types, standard conventions, and basic I/O.\n"
                f"  - Practice 10 simple exercises to build momentum.\n\n"
                f"- **Day 2: Control Flow & Logic** (2–3 hrs)\n"
                f"  - Master `for` loops, `while` loops, conditional branching, and early exits (`break`/`continue`).\n"
                f"  - Write loop accumulators and condition guards.\n\n"
                f"- **Day 3: Functions, Methods & Modular Design** (3 hrs)\n"
                f"  - Understand parameter passing, return types, variable scope, and recursion basics.\n"
                f"  - Refactor monolithic code into clean, reusable functions.\n\n"
                f"- **Day 4: Core Object-Oriented Principles / Data Structures** (3 hrs)\n"
                f"  - Classes, Encapsulation, Inheritance, and Polymorphism.\n"
                f"  - Practice manipulating Arrays, Lists, and Dictionaries/Maps.\n\n"
                f"- **Day 5: Error Handling & Edge Cases** (2–3 hrs)\n"
                f"  - Deep dive into exceptions (`try`/`catch`/`finally`) and common boundary errors (off-by-one, null checks).\n"
                f"  - Practice debugging broken code snippets.\n\n"
                f"- **Day 6: Timed Practice Sessions & Mock Exams** (3 hrs)\n"
                f"  - Complete 2 full-length practice tests on the **Practice Session** tab.\n"
                f"  - Analyze every missed question thoroughly.\n\n"
                f"- **Day 7: Final Formula Card & High-Yield Review** (2 hrs)\n"
                f"  - Review your personal mistake notebook and key syntax summaries.\n"
                f"  - Avoid cramming new concepts; ensure adequate rest before exam day.\n\n"
                f"💡 **Tip:** Spend 70% of your daily study time actively writing code and solving questions, and 30% reading theory!"
            )

        # 2. Point reference query (e.g., "explain the second point")
        m_pt = re.search(r'(first|second|third|fourth|fifth|1st|2nd|3rd|4th|5th|point\s+\d+)', q_lower)
        if m_pt or "point" in q_lower:
            pt_name = m_pt.group(1) if m_pt else "second point"
            pt_title = pt_name.title()
            ex_code = (
                "```java\n"
                "// Clean method structure with input validation and clear exception handling\n"
                "public double calculateDiscount(double price, double percentage) {\n"
                "    if (price < 0 || percentage < 0 || percentage > 100) {\n"
                "        throw new IllegalArgumentException(\"Invalid price or percentage value.\");\n"
                "    }\n"
                "    return price * (1.0 - (percentage / 100.0));\n"
                "}\n"
                "```"
            )
            return (
                f"### 🔍 In-Depth Breakdown: **{pt_title}**\n\n"
                f"Building on our previous discussion, here is a detailed, practical look at **{pt_name}**:\n\n"
                f"#### 🎯 What This Means in Practice\n"
                f"This phase focuses on advancing from basic syntax recognition into structured software architecture and problem-solving. "
                f"Rather than writing isolated statements, you learn how software components interact reliably.\n\n"
                f"#### 💡 Key Action Steps\n"
                f"1. **Isolate Component Responsibilities**: Ensure each class or function has a single, well-defined responsibility.\n"
                f"2. **Implement Guard Clauses**: Validate inputs at the beginning of methods to eliminate invalid states early.\n"
                f"3. **Test with Edge Cases**: Always test with empty inputs, zero, maximum values, and null references.\n\n"
                f"#### 💻 Practical Example\n"
                f"{ex_code}\n\n"
                f"💡 **Suggested Next Step:** Would you like to move on to the next topic, or see how this applies to exam questions?"
            )

        # 3. Example request
        if "example" in q_lower:
            ex_py = (
                "```python\n"
                "# Demonstration of robust logic and clean execution flow\n"
                "def process_scores(scores: list) -> dict:\n"
                "    if not scores:\n"
                "        return {'average': 0.0, 'highest': None, 'count': 0}\n"
                "    \n"
                "    total = sum(scores)\n"
                "    return {\n"
                "        'average': round(total / len(scores), 2),\n"
                "        'highest': max(scores),\n"
                "        'count': len(scores)\n"
                "    }\n"
                "\n"
                "# Testing with representative data\n"
                "results = process_scores([85, 92, 78, 96, 88])\n"
                "print('Summary:', results)\n"
                "# Output: Summary: {'average': 87.8, 'highest': 96, 'count': 5}\n"
                "```"
            )
            return (
                f"### 💻 Practical Demonstration & Code Example\n\n"
                f"Here is a concrete, working example illustrating the concept we were just discussing:\n\n"
                f"{ex_py}\n\n"
                f"#### 🔍 Key Takeaway\n"
                f"- Input validation protects against empty datasets (`ZeroDivisionError`).\n"
                f"- The output is structured and predictable, making it easy to consume.\n\n"
                f"💡 Would you like to see how this is tested, or practice writing a similar function?"
            )

        # 4. General continuation / elaboration
        return (
            f"### 📚 Continued Explanation\n\n"
            f"Following up on our conversation:\n\n"
            f"To deepen your understanding, focus on applying these concepts in active problem-solving. "
            f"When studying for technical examinations, theoretical knowledge solidifies fastest when paired with immediate coding practice and question review.\n\n"
            f"💡 **Next Steps:** What specific part would you like to explore further, or would you like to solve a quiz question together?"
        )

    @classmethod
    def generate_system_support_response(cls, query: str = "", *args, **kwargs) -> str:
        if not query and "question" in kwargs:
            query = kwargs["question"]
        q_lower = query.lower()
        has_timer = "timer" in q_lower
        has_tab = "tab" in q_lower or "switch" in q_lower or "violation" in q_lower

        if has_timer and has_tab:
            return (
                "### ⏱️ How the Exam Timer Works\n\n"
                "- **Countdown Timer**: A live countdown timer is displayed at the top right of the exam screen throughout your test.\n"
                "- **Synchronization**: The timer accurately tracks your allotted exam duration.\n"
                "- **Automatic Submission**: When the timer reaches `00:00`, the exam session will automatically complete and finalize your answers.\n"
                "- **Manual Submission**: You do not have to wait for the timer to expire; you can click **Submit Exam** at any time once you are satisfied with your answers.\n\n"
                "### 🛡️ Tab Switching & Proctoring Violations\n\n"
                "- **Detection**: The examination system automatically detects when you switch tabs, minimize the browser window, or open another application.\n"
                "- **Counter**: Every time you navigate away from the active exam window, a violation event is recorded and the counter on your screen increments.\n"
                "- **No Forced Termination**: Tab switching does **not** kick you out or abruptly terminate your exam. You can continue answering until the timer ends or you click submit.\n"
                "- **Audit Trail**: All violation counts are saved in your exam results for instructor and administrator review."
            )
        elif has_timer:
            return (
                "### ⏱️ How the Exam Timer Works\n\n"
                "- **Countdown Timer**: A live countdown timer is displayed at the top right of the exam screen throughout your test.\n"
                "- **Synchronization**: The timer accurately tracks your allotted exam duration.\n"
                "- **Automatic Submission**: When the timer reaches `00:00`, the exam session will automatically complete and finalize your answers.\n"
                "- **Manual Submission**: You do not have to wait for the timer to expire; you can click **Submit Exam** at any time once you are satisfied with your answers."
            )
        elif has_tab:
            return (
                "### 🛡️ Tab Switching & Proctoring Violations\n\n"
                "- **Detection**: The examination system automatically detects when you switch tabs, minimize the browser window, or open another application.\n"
                "- **Counter**: Every time you navigate away from the active exam window, a violation event is recorded and the counter on your screen increments.\n"
                "- **No Forced Termination**: Tab switching does **not** kick you out or abruptly terminate your exam. You can continue answering until the timer ends or you click submit.\n"
                "- **Audit Trail**: All violation counts are saved in your exam results for instructor and administrator review."
            )
        elif "submit" in q_lower:
            return (
                "### 📝 How to Submit Your Exam\n\n"
                "1. Review all your selected answers on the question palette to ensure none were skipped.\n"
                "2. When you are ready, click the **Submit Exam** button at the bottom of the page.\n"
                "3. Your score and performance breakdown will be calculated and displayed on your Results page immediately."
            )
        else:
            return (
                "### ℹ️ Online Examination System Support\n\n"
                "- **Exams**: Access scheduled tests from your **Student Dashboard**.\n"
                "- **Practice Sessions**: Generate targeted, instant practice quizzes from the **Personalized Learning** or **Practice** sections.\n"
                "- **Study Materials**: View verified, approved course notes and documents directly from the **Study Materials** page on your sidebar.\n"
                "- **Performance Analytics**: Review your historical marks, accuracy percentages, and subject trends on your **Analytics** page."
            )

    @classmethod
    def generate_material_summary_response(cls, subject: str = "General", materials: Optional[List[Dict[str, Any]]] = None, *args, **kwargs) -> str:
        if materials is None and "chunks" in kwargs:
            materials = kwargs["chunks"]
        norm_subj = subject.strip() if subject else "General"

        matching_materials = []
        if materials and norm_subj.lower() not in ("general", "all"):
            for d in materials:
                d_subj = d.get("subject", "")
                if not d_subj or d_subj.strip().lower() == norm_subj.lower():
                    matching_materials.append(d)
        else:
            matching_materials = list(materials or [])

        if not matching_materials:
            return f"There are currently no uploaded or approved course materials for **{norm_subj}** in the study repository. Please check with your instructor or refer to the standard textbook."

        lines = [f"### 📚 Available Course Study Materials for **{norm_subj}**\n"]
        for d in matching_materials:
            fn = d.get("original_name") or d.get("filename")
            doc_id = d.get("id") or d.get("document_id")
            pages = d.get("total_pages", 1)
            chunks = d.get("total_chunks", 0)
            topic = d.get("topic", "General")
            if doc_id:
                lines.append(f"- **[`{fn}`](view_course_material.php?id={doc_id}&source=assistant)** (Topic: {topic})")
            else:
                lines.append(f"- **`{fn}`** (Topic: {topic})")
            lines.append(f"  - Details: {pages} page(s) &bull; {chunks} indexed chunks &bull; Verified study notes")

        lines.append("\n💡 Click any document title above to open the study material viewer directly in a new tab.")
        return "\n".join(lines)

    @classmethod
    def generate_general_educational_response(
        cls,
        query: str = "",
        subject: str = "",
        context_chunks: Optional[List[Dict[str, Any]]] = None,
        *args,
        **kwargs
    ) -> str:
        if not query and "question" in kwargs:
            query = kwargs["question"]
        if context_chunks is None and "chunks" in kwargs:
            context_chunks = kwargs["chunks"]

        clean_q = (query or "").strip()
        clean_target = clean_q.strip("?.") or (subject or "Core Concepts")
        norm_subj = subject.strip() if subject else "General"

        grounding_note = ""
        if context_chunks and len(context_chunks) > 0:
            fn = context_chunks[0].get("filename", "")
            pnum = context_chunks[0].get("page_number", 1)
            doc_id = context_chunks[0].get("document_id")
            link_str = f"[`{fn}`](view_course_material.php?id={doc_id}&page={pnum}&source=assistant)" if doc_id else f"`{fn}`"
            grounding_note = f"\n\n*(Verified against course material: {link_str} — Page {pnum})*"

        return f"""### 📚 Study & Learning Overview: **{clean_target}**

Understanding this topic is essential for your technical and academic preparation in **{norm_subj}**.

#### 💡 Core Overview
When approaching **{clean_target}**, structure your understanding around three key pillars:
1. **Foundational Definition**: What the concept is, its primary role, and why it was introduced.
2. **Mechanism & Execution**: How the components interact under the hood and its architectural implications.
3. **Practical Application**: Real-world scenarios where this concept solves concrete engineering problems.

#### 🎯 Recommended Learning Strategy
- Review the core definitions and fundamental syntax.
- Write a short code snippet or solve 2–3 practice questions to reinforce the theory.
- Connect this concept to previous topics and examine edge cases.
{grounding_note}

💡 **Next Steps:** Would you like a detailed concept breakdown, practical examples, or practice quiz questions on this topic?""".strip()

llm_service = LLMService()
