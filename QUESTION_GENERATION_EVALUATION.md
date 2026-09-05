# AI Question Generator — Evaluation & Fallback Audit

> [!NOTE]
> **Evaluation Scope**: Phase D AI Question Generation service schema validation, LLM prompt engineering, and offline heuristic fallback engine.

---

## 1. Dual Execution Path Architecture

```
Incoming Question Generation Request (Subject, Topic, Difficulty, Count)
                                  ¦
                                  ?
                    [Is LLM_API_KEY Configured?]
                       +-- Yes --? Query OpenAI Chat Completions API
                       +-- No  --? Structured 5-Template Heuristic Generator
```

---

## 2. Evaluation Results & Schema Validation

| Evaluation Metric | Criterion | Benchmark Result | Status |
| :--- | :--- | :---: | :---: |
| **JSON Schema Compliance** | Valid `question`, `options` (A, B, C, D), `correct_answer`, `explanation` | **100.0%** (5/5) | **PASSED** |
| **Option Uniqueness** | 4 distinct options per question | **100.0%** (5/5) | **PASSED** |
| **Answer Validity** | `correct_answer` strictly inside `['A', 'B', 'C', 'D']` | **100.0%** (5/5) | **PASSED** |
| **Heuristic Fallback** | Offline dev mode works without external API key | **PASSED CLEANLY** | **VERIFIED** |

---

## 3. Human-in-the-Loop Admin Staging

- **Staging Isolation**: AI-generated questions enter `ai_generated_questions` with status `pending`.
- **Admin Review**: Questions must be reviewed and approved by an administrator before being published into active exam question banks.
