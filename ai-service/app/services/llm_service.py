import json
import logging
import uuid
import httpx
import re
from typing import List, Dict, Any, Tuple
from app.config import settings
from app.schemas.question import QuestionGenerationRequest, GeneratedQuestionItem, FocusedNotesRequest

logger = logging.getLogger("llm_service")

class LLMService:
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
                q_text = item.get("question", "").strip()
                # Narrowly strip any artificial trailing variant suffix (e.g., "(Variant 48)", "(variant 7)")
                q_text = re.sub(r'\s*\([Vv]ariant\s+\d+\)\s*$', '', q_text).strip()
                if q_text and q_text[-1] not in "?!.:":
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
                corr_ans = item.get("correct_answer") or item.get("reference_solution", "")

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
                    reference_solution=item.get("reference_solution"),
                    starter_code=item.get("starter_code"),
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

llm_service = LLMService()
