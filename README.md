# AI-Powered Intelligent Examination & Personalized Learning Platform

> A production-oriented Full-Stack Online Examination and AI Engineering Platform featuring automated question generation, deterministic performance analytics, machine-learning question difficulty prediction, Retrieval-Augmented Generation (RAG) study assistance, and adaptive personalized recommendations.

---

## ?? Key Features & AI Subsystems

- **PHP 8.1 / MySQL Core Exam Engine**: Session-authenticated student exam player, tab-switch proctoring, violation reporting, and admin management.
- **AI Question Generator (Phase D)**: Generates structured multiple-choice questions (MCQ) with admin staging and human-in-the-loop review.
- **Student Performance Analytics (Phase E)**: Deterministic topic-level accuracy, strong/weak topic classification, and performance trend tracking.
- **ML Question Difficulty Prediction (Phase F)**: Scikit-learn models (Random Forest / Logistic Regression) predicting empirical difficulty with cold-start safeguards.
- **Course Material RAG Study Assistant (Phase G)**: Semantic retrieval over PDF/TXT materials using `sentence-transformers` and `ChromaDB` with verifiable page citations.
- **Personalized Adaptive Learning Engine (Phase H)**: Normalized priority scoring ($[0.0, 1.0]$), difficulty progression, adaptive study plans, and isolated practice sessions.

---

## ??? System Architecture

```
Student / Admin Browser
       ¦
       ?
PHP Application (Apache / XAMPP) --? MySQL Database (MariaDB 10.6)
       ¦
       ? (cURL REST API / Port 8001)
Python FastAPI Service (ai-service)
       +-- Question Generator (LLM / Heuristic Engine)
       +-- ML Difficulty Predictor (Random Forest / Joblib)
       +-- RAG Service (pypdf, sentence-transformers, ChromaDB)
       +-- Adaptive Recommendation Engine (Priority Scoring)
```

---

## ?? Getting Started

### Option A: Local Development (XAMPP + Python Virtualenv)

1. **Clone & Setup Database**:
   - Start Apache & MySQL in XAMPP.
   - Run database migrations:
     ```bash
     php database/run_migrations.php
     ```
2. **Start Python AI Service**:
   ```bash
   cd ai-service
   python -m venv .venv
   .\.venv\Scripts\activate
   pip install -r requirements.txt
   uvicorn app.main:app --host 127.0.0.1 --port 8001
   ```
3. **Access Application**:
   - Open browser: `http://localhost/exam-online/online-exam-system/`

---

### Option B: Docker Container Orchestration

Run the complete multi-container stack with a single command:

```bash
docker-compose up --build -d
```

- **PHP Web Application**: `http://localhost:8080`
- **FastAPI AI Service**: `http://localhost:8001`
- **MariaDB Database**: `localhost:3306`

---

## ?? Automated Testing & Evaluation

Run the automated Pytest and PHP integration test suites:

```bash
# Python Pytest Suite (23 tests)
cd ai-service
pytest -v

# Offline Evaluation Benchmarks
python tests/evaluate_rag.py
python tests/evaluate_recommendations.py

# PHP Integration & Regression Suite (30 tests)
php tests/test_ai_client.php
php tests/test_ai_question_gen.php
php tests/test_ai_performance.php
php tests/test_ai_difficulty.php
php tests/test_ai_rag.php
php tests/test_ai_recommendations.php
php tests/test_regression.php
php tests/manual_e2e_verification.php
```

---

## ?? Evaluation & Benchmark Summary

| Evaluation Suite | Type / Scope | Accuracy / Metric | Status |
| :--- | :--- | :---: | :---: |
| **Pytest Service Tests** | Service unit & integration | **100.0%** (23/23) | **PASSED** |
| **PHP Integration Tests** | End-to-end integration | **100.0%** (30/30) | **PASSED** |
| **Offline RAG Benchmark** | Recall@K, Citations, Rejection | **100.0%** (3/3) | **DEVELOPMENT BENCHMARK** |
| **Offline Recommendation Benchmark** | Priority rules, classification | **100.0%** (8/8) | **DEVELOPMENT BENCHMARK** |
| **ML Difficulty Prediction** | Synthetic pipeline validation | **99.17%** (Random Forest) | **SYNTHETIC BENCHMARK** |

---

## ?? Security & Privacy

- **Input Validation & Sanitization**: Prepared SQL statements across all MySQL queries; CSRF token validation on POST forms.
- **Untrusted RAG Content Isolation**: Document text is isolated in RAG prompts to prevent prompt injection attacks.
- **Zero API Secret Exposure**: API keys and database credentials are managed exclusively via `.env` files.

---

## ?? Documentation

- [Architecture Specification](docs/architecture.md)
- [AI Engineering Overview](docs/AI_ENGINEERING_OVERVIEW.md)
- [Security & Threat Model](SECURITY.md)
- [RAG Evaluation Framework](RAG_EVALUATION.md)
- [ML Model Evaluation](ML_EVALUATION.md)
- [Recommendation Evaluation](RECOMMENDATION_EVALUATION.md)
- [Question Generator Evaluation](QUESTION_GENERATION_EVALUATION.md)
- [Phase I Audit Report](PHASE_I_AUDIT.md)
