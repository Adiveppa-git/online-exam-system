import json
import logging
import uuid
import httpx
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

                topic_label = req.topic if req.topic else req.subject
                exp_default = f"Correct answer is option {item.get('correct_answer')} based on {topic_label} principles."
                validated = GeneratedQuestionItem(
                    question=q_text,
                    options=item.get("options", {}),
                    correct_answer=item.get("correct_answer", "A"),
                    explanation=item.get("explanation", exp_default),
                    subject=req.subject,
                    topic=req.topic or "",
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

        topic_str = f"Topic: {req.topic}" if req.topic else f"Topic: General {req.subject} concepts"

        prompt = f"""
Generate {req.number_of_questions} multiple choice questions (MCQ) for an examination.
Subject: {req.subject}
{topic_str}
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
        topic_label = req.topic if req.topic else req.subject
        
        base_templates = [
            {
                "q": f"What is a fundamental core concept of {topic_label} in {req.subject}?" if req.topic else f"What is a fundamental core concept of {req.subject}?",
                "opts": {
                    "A": f"Primary principle of {topic_label}",
                    "B": f"Secondary non-standard implementation",
                    "C": f"Unrelated legacy protocol",
                    "D": f"Deprecated configuration pattern"
                },
                "ans": "A",
                "exp": f"The primary principle of {topic_label} forms the foundation of {req.subject}."
            },
            {
                "q": f"Which of the following best describes the function of {topic_label}?",
                "opts": {
                    "A": "Provides temporary storage allocation",
                    "B": f"Encapsulates and processes core {topic_label} operations efficiently",
                    "C": "Restricts network bandwidth usage",
                    "D": "Ignores syntax error handling"
                },
                "ans": "B",
                "exp": f"{topic_label} is specifically designed to encapsulate core operational logic."
            },
            {
                "q": f"In a {diff_label} scenario involving {topic_label}, which strategy is recommended?",
                "opts": {
                    "A": "Bypass validation routines",
                    "B": "Hardcode dynamic variable bindings",
                    "C": f"Apply modular patterns tailored for {topic_label}",
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

    @staticmethod
    def generate_focused_study_notes(req: FocusedNotesRequest) -> Dict[str, Any]:
        """
        Generates focused study notes specifically for the concept tested by the question.
        Uses RAG context search first. If context exists, notes are marked as grounded.
        Otherwise falls back to domain-knowledge LLM/heuristic generation marked as ungrounded.
        """
        from app.services.rag_service import RAGService

        # 1. Retrieve RAG context
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

        grounding_badge = "📖 *Grounded in Approved Course Material*" if is_grounded else "⚠️ *Generated via AI Domain Knowledge Fallback (No specific course material chunk matched)*"

        content = f"""### {req.topic} — Key Concept Analysis
{grounding_badge}

#### 1. Core Concept Tested
This question evaluates your understanding of **{req.topic}** within **{req.subject}**, specifically targeting:
> "{req.question}"

#### 2. Detailed Technical Explanation & Why Answer is Correct
- **Correct Answer**: `{req.correct_answer}`
- **Reasoning**: {req.explanation or f"The fundamental principle of {req.topic} establishes that choice '{req.correct_answer}' satisfies the optimal execution criteria."}
- **Why Other Options Fail**: Alternative choices represent suboptimal strategies, non-standard protocols, or common misconceptions regarding {req.topic}.

#### 3. Concrete Example
Consider a scenario involving **{req.topic}**:
- **Input Parameters**: Workload or process queue defined in `{req.subject}`.
- **Evaluation**: Choice `{req.correct_answer}` minimizes overhead and maximizes performance under the constraints specified by {req.topic}.

#### 4. Required Prerequisite Knowledge
- Fundamentals of **{req.subject}**.
- Operational principles of **{req.topic}**.
- Key metrics used to evaluate efficiency and correctness.

#### 5. Common Exam Traps & Pitfalls
- ❌ **Trap 1**: Confusing preemptive vs non-preemptive algorithms or strategies.
- ❌ **Trap 2**: Misreading initial condition parameters (e.g. burst times known vs unknown).
- 💡 **Exam Tip**: Always check whether prior information or static bounds are available before selecting an optimal strategy.

#### 6. Key Points to Remember
For questions on **{req.topic}**, remember that choice `{req.correct_answer}` directly satisfies the core requirement described in the problem statement.
"""
        return concept_title, content

llm_service = LLMService()
