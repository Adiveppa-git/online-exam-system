# Phase I Audit — System Architecture & Productionization Audit Report

> [!NOTE]
> **Audit Purpose**: Comprehensive baseline evaluation of the Full-Stack Online Examination & AI Platform (Phases B–H) to identify hardcoded credentials, service boundaries, security controls, test coverage, and production deployment gaps prior to containerization and CI/CD integration.

---

## 1. Current System Architecture

The platform follows a decoupled dual-service architecture:
- **Monolithic PHP Application**: Handles student & admin web UI, session management, MySQL relational data persistence, exam authoring, and student test delivery.
- **Python FastAPI Service (`ai-service`)**: Microservice owning LLM completions, performance analytics, machine-learning question difficulty prediction, RAG document processing, vector search (ChromaDB), and personalized adaptive learning recommendations.

```
+-------------------------------------------------------------------+
|                        Student / Admin Browser                    |
+-------------------------------------------------------------------+
                                  |
                                  v
+-------------------------------------------------------------------+
|                  PHP Application (Apache / XAMPP)                 |
|  - Auth, Sessions, CSRF, Admin & Student Workflows                |
|  - MySQL Relational Persistence (users, exams, questions, etc.)   |
+-------------------------------------------------------------------+
                                  |
               cURL REST Calls (HTTP JSON / Port 8001)
                                  v
+-------------------------------------------------------------------+
|                   Python FastAPI Service (ai-service)             |
|  - Question Gen (LLM / Heuristic Engine)                          |
|  - Performance Analytics (Deterministic Topic Accuracy & Trend)   |
|  - ML Question Difficulty (Random Forest / Logistic Regression)   |
|  - RAG Service (pypdf, TextChunker, sentence-transformers)        |
|  - Vector Store (ChromaDB Persistent Store)                       |
|  - Adaptive Learning & Recommendation Engine (Priority Scoring)   |
+-------------------------------------------------------------------+
```

---

## 2. Existing Services & Databases

### Services
1. **PHP Web Backend**: Serves admin dashboards, student portals, cURL client (`config/ai_client.php`), and core exam execution logic.
2. **FastAPI AI Service**: Runs locally on `http://127.0.0.1:8001` with Pydantic schema validation.

### Databases & Persistence
1. **MySQL / MariaDB**:
   - `users`, `exams`, `questions`, `results`, `student_answers`, `violations` (Core exam system)
   - `ai_generation_requests`, `ai_generated_questions` (Phase D staging)
   - `ai_documents`, `ai_document_chunks` (Phase G RAG metadata)
   - `ai_practice_sessions`, `ai_practice_answers` (Phase H isolated practice sessions)
2. **ChromaDB**:
   - Persistent vector database stored at `ai-service/data/chroma_db` managing `course_materials` embeddings (`sentence-transformers/all-MiniLM-L6-v2`, 384 dimensions).

---

## 3. Existing AI, ML & RAG Components

- **AI Question Generator (Phase D)**: OpenAI-compatible chat completion provider with structured JSON schema validation and a structured 5-template heuristic fallback engine.
- **Student Performance Analytics (Phase E)**: Deterministic topic classification, accuracy calculation, trend calculation, and student session isolation queries.
- **ML Question Difficulty Prediction (Phase F)**: Random Forest and Logistic Regression trained on 8 item-level features. Cold-start guard returns `insufficient_real_data` if attempts $< 5$. Synthetic benchmark accuracy (~99.17%) is explicitly labeled as pipeline validation only due to feature-target leakage.
- **RAG Study Assistant (Phase G)**: Document loader (`pypdf`, TXT, MD), paragraph/section-aware chunker (`chunk_size=500`, `overlap=50`), L2-normalized cosine similarity search ($\ge 0.35$ threshold), prompt injection security barrier, and exact page citations.
- **Adaptive Recommendation Engine (Phase H)**: Deterministic priority scoring ($0.5 \times \text{weakness} + 0.3 \times \text{trend} + 0.2 \times \text{recency}$), difficulty progression (`easy` / `medium` / `hard`), study plan framing, targeted practice generation, and isolated session tracking.

---

## 4. Hardcoded Credentials & Production Gaps Audit

During the audit, the following findings were identified:

| File Location | Finding / Production Gap | Severity | Recommended Fix |
| :--- | :--- | :--- | :--- |
| `config/db.php` | Hardcoded fallback Railway cloud DB password (`hopper.proxy.rlwy.net`). | **HIGH** | Remove hardcoded fallback password. Require environment variables (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `DB_PORT`). |
| `config/ai_client.php` | Default API key `dev_secret_key_change_in_production`. | **MEDIUM** | Drive API key from environment variable (`AI_SERVICE_KEY`). |
| `ai-service/app/config.py` | Default `INTERNAL_API_KEY` hardcoded string. | **MEDIUM** | Drive via `.env` file and environment configuration. |
| Root & `ai-service/` | Missing `.env.example` templates for onboarding. | **MEDIUM** | Create standardized `.env.example` files for both PHP and Python projects. |
| `.gitignore` | `uploads/` directory containing uploaded private PDF/TXT materials not ignored. | **MEDIUM** | Update `.gitignore` to exclude `uploads/course_materials/` files. |
| `ai-service/` | Missing containerization configuration. | **MEDIUM** | Add `Dockerfile` and `docker-compose.yml` for multi-container orchestration. |
| `ai-service/app/main.py` | Liveness `/health` exists, but separate `/readiness` check missing. | **LOW** | Add `/readiness` route validating ChromaDB and model loading. |
| `.github/` | Missing CI/CD workflow configuration. | **LOW** | Add `.github/workflows/ci.yml` running Pytest and PHP test suites. |

---

## 5. Security Controls & Test Coverage Baseline

- **CSRF & Session Protection**: Form POST requests in admin and student interfaces validate session CSRF tokens (`$_SESSION['csrf_token']`).
- **SQL Injection Prevention**: All MySQL interactions utilize prepared statements (`$stmt->bind_param(...)`).
- **Prompt Injection Defense**: RAG system prompts explicitly segregate untrusted document text from system instructions.
- **Automated Test Coverage**: 64 total passing tests across Pytest, PHP CLI scripts, offline RAG benchmarks, offline recommendation benchmarks, and full system regression suites.
