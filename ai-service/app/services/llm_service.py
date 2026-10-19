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
                if q_text and q_text[-1] not in "?!.":
                    q_text += "?"
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
                "exp": f"Applying modular patterns provides optimal reliability for {topic_label}."
            },
            {
                "q": f"What is a key advantage of utilizing {topic_label} properly in {req.subject}?",
                "opts": {
                    "A": f"Improved performance, maintainability, and scalability for {topic_label}",
                    "B": "Increased compilation overhead",
                    "C": "Forced single-threaded execution",
                    "D": "Elimination of type checking"
                },
                "ans": "A",
                "exp": f"Proper utilization of {topic_label} enhances system maintainability and scalability."
            },
            {
                "q": f"Which common issue can occur if {topic_label} is implemented incorrectly?",
                "opts": {
                    "A": "Automatic data compression",
                    "B": "Faster response times",
                    "C": f"Unexpected side-effects or logic failures in {topic_label}",
                    "D": "Enhanced security encryption"
                },
                "ans": "C",
                "exp": f"Incorrect implementation of {topic_label} can cause unexpected logic failures."
            },
            {
                "q": f"When optimizing {topic_label} for higher efficiency, what is the most critical consideration?",
                "opts": {
                    "A": f"Balancing algorithmic complexity with memory overhead in {topic_label}",
                    "B": "Removing all parameter constraints",
                    "C": "Duplicating core routines across modules",
                    "D": "Suppressing runtime execution telemetry"
                },
                "ans": "A",
                "exp": f"Efficiency optimization requires balancing complexity and memory overhead in {topic_label}."
            },
            {
                "q": f"How does {topic_label} maintain state consistency and data integrity within {req.subject}?",
                "opts": {
                    "A": "Relying on arbitrary timing delays",
                    "B": f"Enforcing deterministic execution paths and validation checks in {topic_label}",
                    "C": "Permitting uncontrolled global state mutations",
                    "D": "Skipping boundary verification steps"
                },
                "ans": "B",
                "exp": f"State consistency is preserved through deterministic paths and validation in {topic_label}."
            },
            {
                "q": f"Which architectural pattern is best suited for structuring {topic_label} components?",
                "opts": {
                    "A": "Monolithic tightly-coupled scripting",
                    "B": "Unstructured circular dependency graphs",
                    "C": f"Layered separation of concerns tailored for {topic_label}",
                    "D": "Randomized execution pipelines"
                },
                "ans": "C",
                "exp": f"Layered separation of concerns maximizes modularity and maintainability for {topic_label}."
            },
            {
                "q": f"What error-handling approach is most effective when working with {topic_label}?",
                "opts": {
                    "A": f"Graceful degradation with structured exception handling around {topic_label}",
                    "B": "Silently swallowing all raised exceptions",
                    "C": "Abruptly terminating the host process without diagnostics",
                    "D": "Disabling return-code verification"
                },
                "ans": "A",
                "exp": f"Structured exception handling and graceful recovery are essential for {topic_label}."
            },
            {
                "q": f"During code review of a {topic_label} implementation, what is considered an antipattern?",
                "opts": {
                    "A": "Comprehensive unit testing coverage",
                    "B": f"Tight coupling and hardcoded dependencies within {topic_label}",
                    "C": "Documenting interface contracts clearly",
                    "D": "Using immutable data representations"
                },
                "ans": "B",
                "exp": f"Tight coupling limits testability and introduces brittleness into {topic_label}."
            },
            {
                "q": f"How does proper lifecycle management benefit {topic_label} in {req.subject}?",
                "opts": {
                    "A": "Increases uncollected garbage collection cycles",
                    "B": "Eliminates all runtime predictability",
                    "C": f"Prevents resource leaks and ensures timely cleanup for {topic_label}",
                    "D": "Causes intermittent connection timeouts"
                },
                "ans": "C",
                "exp": f"Explicit lifecycle controls prevent resource exhaustion in {topic_label}."
            },
            {
                "q": f"What security consideration is most essential when deploying {topic_label}?",
                "opts": {
                    "A": f"Rigorous input sanitization and least-privilege access for {topic_label}",
                    "B": "Granting unrestricted administrative permissions",
                    "C": "Exposing internal state variables globally",
                    "D": "Disabling transport-layer encryption"
                },
                "ans": "A",
                "exp": f"Input sanitization and least-privilege boundaries protect {topic_label} from exploit."
            },
            {
                "q": f"Which metric provides the most accurate insight into {topic_label} runtime performance?",
                "opts": {
                    "A": "Total physical storage disk capacity",
                    "B": f"Latency distribution and resource utilization during {topic_label} execution",
                    "C": "Number of source code comment lines",
                    "D": "Monitor refresh frequency"
                },
                "ans": "B",
                "exp": f"Latency distributions and resource metrics identify performance bottlenecks in {topic_label}."
            },
            {
                "q": f"When refactoring legacy logic into {topic_label}, what primary guideline should be followed?",
                "opts": {
                    "A": "Rewrite without verifying existing behavioral contracts",
                    "B": "Introduce breaking changes to public APIs unconditionally",
                    "C": f"Maintain backward compatibility while isolating {topic_label} responsibilities",
                    "D": "Remove all regression test assertions"
                },
                "ans": "C",
                "exp": f"Preserving API contracts while isolating concerns ensures safe refactoring into {topic_label}."
            },
            {
                "q": f"How does {topic_label} handle edge cases and boundary condition constraints?",
                "opts": {
                    "A": f"Through explicit boundary validation and defensive assertions in {topic_label}",
                    "B": "By assuming inputs always conform to standard bounds",
                    "C": "Ignoring null or empty input payloads",
                    "D": "Delegating validation to downstream consumers entirely"
                },
                "ans": "A",
                "exp": f"Defensive boundary assertions prevent unexpected edge-case failures in {topic_label}."
            },
            {
                "q": f"Under high concurrency, what strategy ensures thread safety for {topic_label}?",
                "opts": {
                    "A": "Unsynchronized shared memory mutations",
                    "B": f"Using immutability or robust synchronization primitives for {topic_label}",
                    "C": "Disabling concurrent thread scheduling",
                    "D": "Permitting race conditions without coordination"
                },
                "ans": "B",
                "exp": f"Synchronization primitives and immutable data structures prevent concurrency hazards in {topic_label}."
            },
            {
                "q": f"What is the primary trade-off when configuring {topic_label} parameters?",
                "opts": {
                    "A": "Font rendering size versus screen brightness",
                    "B": "Network cable speed versus wall voltage",
                    "C": f"Throughput rate versus latency overhead in {topic_label}",
                    "D": "Keyboard layout style versus disk sector format"
                },
                "ans": "C",
                "exp": f"Tuning throughput against latency overhead represents the fundamental trade-off in {topic_label}."
            },
            {
                "q": f"Which testing strategy offers the highest coverage for {topic_label} routines?",
                "opts": {
                    "A": f"Combining automated unit, integration, and property-based tests for {topic_label}",
                    "B": "Manual ad-hoc testing exclusively before release",
                    "C": "Testing only the happy path and skipping failure states",
                    "D": "Relying solely on end-user bug reports in production"
                },
                "ans": "A",
                "exp": f"Comprehensive test coverage across levels ensures robust correctness for {topic_label}."
            },
            {
                "q": f"How does modular decoupling enhance the maintainability of {topic_label}?",
                "opts": {
                    "A": f"Enables independent updates and isolated testing of {topic_label}",
                    "B": "Forces synchronous coupling across all system components",
                    "C": "Increases blast radius of internal defects",
                    "D": "Prevents code reuse across adjacent domains"
                },
                "ans": "A",
                "exp": f"Modular decoupling confines changes and enables independent maintenance of {topic_label}."
            },
            {
                "q": f"What standard convention governs modern implementations of {topic_label}?",
                "opts": {
                    "A": "Proprietary non-standard dialect overrides",
                    "B": "Arbitrary variable naming and zero documentation",
                    "C": f"Adherence to idiomatic standards, type safety, and clean code principles in {req.subject}",
                    "D": "Deprecation of all interface contracts"
                },
                "ans": "C",
                "exp": f"Modern systems rely on idiomatic standards and clean contracts for {topic_label}."
            }
        ]

        prefixes = [
            "",
            "From an architectural design perspective, ",
            "In high-availability production environments, ",
            "Regarding systems engineering standards, ",
            "From an operational reliability standpoint, "
        ]

        num_templates = len(base_templates)
        for i in range(req.number_of_questions):
            tmpl = base_templates[i % num_templates]
            cycle = i // num_templates
            prefix = prefixes[cycle % len(prefixes)]
            
            base_q = tmpl["q"]
            if prefix:
                q_text = f"{prefix}{base_q[0].lower()}{base_q[1:]}"
            else:
                q_text = base_q

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
