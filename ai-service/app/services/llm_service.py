import json
import logging
import uuid
import httpx
from typing import List, Dict, Any
from app.config import settings
from app.schemas.question import QuestionGenerationRequest, GeneratedQuestionItem

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
                if not q_text or q_text in seen_questions:
                    continue
                seen_questions.add(q_text)

                validated = GeneratedQuestionItem(
                    question=q_text,
                    options=item.get("options", {}),
                    correct_answer=item.get("correct_answer", "A"),
                    explanation=item.get("explanation", f"Correct answer is option {item.get('correct_answer')} based on {req.topic} principles."),
                    subject=req.subject,
                    topic=req.topic,
                    difficulty=req.difficulty
                )
                validated_items.append(validated)
            except Exception as val_err:
                logger.warning(f"Skipping invalid generated question item {idx}: {val_err}")

        return {
            "request_id": request_id,
            "status": "success",
            "model_used": model_name if api_key else "heuristic-engine-v1",
            "questions": validated_items
        }

    @staticmethod
    def _query_external_llm(req: QuestionGenerationRequest, api_key: str, model_name: str) -> List[Dict[str, Any]]:
        url = "https://api.openai.com/v1/chat/completions"
        headers = {
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json"
        }

        prompt = f"""
Generate {req.number_of_questions} multiple choice questions (MCQ) for an examination.
Subject: {req.subject}
Topic: {req.topic}
Difficulty: {req.difficulty}
Additional Context: {req.additional_context or 'None'}

Return ONLY a valid JSON object with a key 'questions' containing an array of objects.
Each object must have:
- 'question': string
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

        with httpx.Client(timeout=15.0) as client:
            resp = client.post(url, headers=headers, json=body)
            resp.raise_for_status()
            content = resp.json()["choices"][0]["message"]["content"]
            parsed = json.loads(content)
            return parsed.get("questions", [])

    @staticmethod
    def _generate_heuristic_questions(req: QuestionGenerationRequest) -> List[Dict[str, Any]]:
        results = []
        diff_label = req.difficulty.capitalize()
        
        base_templates = [
            {
                "q": f"What is a fundamental core concept of {req.topic} in {req.subject}?",
                "opts": {
                    "A": f"Primary principle of {req.topic}",
                    "B": f"Secondary non-standard implementation",
                    "C": f"Unrelated legacy protocol",
                    "D": f"Deprecated configuration pattern"
                },
                "ans": "A",
                "exp": f"The primary principle of {req.topic} forms the foundation of {req.subject}."
            },
            {
                "q": f"Which of the following best describes the function of {req.topic}?",
                "opts": {
                    "A": "Provides temporary storage allocation",
                    "B": f"Encapsulates and processes core {req.topic} operations efficiently",
                    "C": "Restricts network bandwidth usage",
                    "D": "Ignores syntax error handling"
                },
                "ans": "B",
                "exp": f"{req.topic} is specifically designed to encapsulate core operational logic."
            },
            {
                "q": f"In a {diff_label} scenario involving {req.topic}, which strategy is recommended?",
                "opts": {
                    "A": "Bypass validation routines",
                    "B": "Hardcode dynamic variable bindings",
                    "C": f"Apply modular patterns tailored for {req.topic}",
                    "D": "Disable exception reporting"
                },
                "ans": "C",
                "exp": f"Applying modular patterns provides optimal reliability for {req.topic}."
            },
            {
                "q": f"What is a key advantage of utilizing {req.topic} properly in {req.subject}?",
                "opts": {
                    "A": f"Improved performance, maintainability, and scalability for {req.topic}",
                    "B": "Increased compilation overhead",
                    "C": "Forced single-threaded execution",
                    "D": "Elimination of type checking"
                },
                "ans": "A",
                "exp": f"Proper utilization of {req.topic} enhances system maintainability and scalability."
            },
            {
                "q": f"Which common issue can occur if {req.topic} is implemented incorrectly?",
                "opts": {
                    "A": "Automatic data compression",
                    "B": "Faster response times",
                    "C": f"Unexpected side-effects or logic failures in {req.topic}",
                    "D": "Enhanced security encryption"
                },
                "ans": "C",
                "exp": f"Incorrect implementation of {req.topic} can cause unexpected logic failures."
            }
        ]

        for i in range(req.number_of_questions):
            tmpl = base_templates[i % len(base_templates)]
            q_num_suffix = f" (Variant {i+1})" if i >= len(base_templates) else ""
            
            results.append({
                "question": f"{tmpl['q']}{q_num_suffix}",
                "options": tmpl["opts"].copy(),
                "correct_answer": tmpl["ans"],
                "explanation": f"{tmpl['exp']} ({diff_label} difficulty evaluation).",
                "subject": req.subject,
                "topic": req.topic,
                "difficulty": req.difficulty
            })

        return results

llm_service = LLMService()
