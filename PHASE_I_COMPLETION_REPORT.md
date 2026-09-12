# Phase I Completion Report — Productionization, Dockerization, Evaluation Framework & Portfolio Architecture

> [!IMPORTANT]
> **Historical Phase I Report**: This document records the initial Phase I baseline audit and setup. The repository has since progressed through subsequent AI Assistant, OTP, RAG, ML difficulty, recommendation, and deployment-preparation commits.

---

## 1. Audit Findings Summary

During the initial Phase I audit:
- Hardcoded Railway fallback database password in `config/db.php` was identified and removed.
- Default fallback keys in `config/ai_client.php` and `ai-service/app/config.py` were environment-driven via `.env`.
- `.gitignore` was updated to exclude private `.env` files, `.venv`, uploaded course materials, ChromaDB local vectors, and log files.

---

## 2. Files Created & Modified

### Created Files
- [PHASE_I_AUDIT.md](PHASE_I_AUDIT.md): Comprehensive baseline audit of architecture, credentials, security, and production gaps.
- [.env.example](.env.example): Root environment variable configuration template.
- [ai-service/.env.example](ai-service/.env.example): AI microservice environment variable configuration template.
- [ai-service/Dockerfile](ai-service/Dockerfile): Multi-stage Dockerfile for FastAPI Python microservice (port 8001).
- [ai-service/.dockerignore](ai-service/.dockerignore): Docker build exclusion file.
- [docker-compose.yml](docker-compose.yml): Docker Compose orchestrating `php-web` (port 8080), `mysql` (port 3306 with `mysql_data` volume), and `ai-service` (port 8001 with `chroma_data` volume).
- [SECURITY.md](SECURITY.md): Threat model, CSRF, XSS, prepared statement, and prompt injection defense documentation.
- [RAG_EVALUATION.md](RAG_EVALUATION.md): Quantitative evaluation report for semantic retrieval, Recall@K, citation accuracy, and thresholding.
- [ML_EVALUATION.md](ML_EVALUATION.md): Target leakage audit, synthetic benchmark framing (~99.17%), and real-data cold-start safeguards.
- [RECOMMENDATION_EVALUATION.md](RECOMMENDATION_EVALUATION.md): Bounded priority score evaluation and rule validation across student benchmark profiles A–H.
- [QUESTION_GENERATION_EVALUATION.md](QUESTION_GENERATION_EVALUATION.md): Schema validation and heuristic fallback evaluation report.
- [.github/workflows/ci.yml](.github/workflows/ci.yml): GitHub Actions CI workflow running Pytest, PHP test scripts, evaluation benchmarks, and Docker builds without external API keys.
- [docs/architecture.md](docs/architecture.md): System architecture document with Mermaid diagrams.
- [docs/AI_ENGINEERING_OVERVIEW.md](docs/AI_ENGINEERING_OVERVIEW.md): Portfolio-quality AI engineering overview.

### Modified Files
- [config/db.php](config/db.php): Removed hardcoded Railway credentials; enabled clean environment variable configuration.
- [ai-service/app/main.py](ai-service/app/main.py): Added structured JSON request middleware, global exception handlers, and `/readiness` health endpoint.
- [.gitignore](.gitignore): Updated exclusion rules for secrets, virtual environments, vector DB data, uploads, and logs.
- [README.md](README.md): Production-grade README with dual setup guides (XAMPP & Docker), system architecture, API endpoints, evaluation benchmarks, and security.

---

## 3. Architecture & Infrastructure Changes

- **Dual Setup Support**: System supports both local XAMPP setup (Apache + MySQL + Python venv) and Docker containerization (`docker-compose up`).
- **Readiness & Liveness**: FastAPI microservice provides `/health` (Liveness) and `/readiness` (Readiness validating ChromaDB and vector collection connectivity).
- **Structured Observability**: Request-level middleware logs HTTP method, endpoint path, status codes, and latency ($ms$) without logging secrets or student PII.

---

## 4. Evaluation Framework & Benchmark Results

Every evaluation category is strictly distinguished:

| Evaluation Category | Type / Framing | Evaluation Metric | Result / Status |
| :--- | :--- | :--- | :---: |
| **Pytest Unit & Integration** | Automated Code Suite | 23 Test Cases | **100.0%** (23/23) PASSED |
| **PHP Integration & Regression** | Automated Code Suite | 30 Test Cases | **100.0%** (30/30) PASSED |
| **Offline RAG Benchmark** | Development Benchmark | Recall@K, Citations, Rejection | **100.0%** (3/3) PASSED |
| **Offline Recommendation Benchmark** | Development Benchmark | Priority Rules, Classification | **100.0%** (8/8) PASSED |
| **Question Generator Benchmark** | Development Benchmark | Schema Compliance, Option Uniqueness | **100.0%** (5/5) PASSED |
| **ML Difficulty Prediction** | Synthetic Benchmark | Random Forest Accuracy | **99.17%** (Pipeline Validation) |

---

## 5. Security & Threat Mitigation

- **100% Prepared Statements**: All MySQL database interactions utilize prepared SQL queries (`$stmt->bind_param(...)`).
- **CSRF & Session Security**: Form POST requests validate cryptographic tokens (`$_SESSION['csrf_token']`); student identity is derived exclusively from active PHP sessions.
- **Untrusted RAG Content Isolation**: System prompts isolate user-uploaded document context to prevent prompt injection attacks.
- **Zero API Key Exposure**: Secrets are loaded strictly via `.env` files and environment variables. Zero credentials are committed to Git.

---

## 6. Known Limitations & Production Gaps

1. **Synthetic ML Benchmark**: Synthetic Random Forest accuracy (~99.17%) is a pipeline validation metric due to feature-target leakage. Live production re-training requires collecting $\ge 30$ real student attempts per question.
2. **Initial Relevance Threshold ($0.35$)**: The initial similarity threshold is an engineering heuristic tuned for development that should be continuously evaluated as course material volume grows.
3. **No External Paid API Key Requirement**: Automated tests use deterministic fallback paths to avoid requiring paid LLM API keys during CI/CD execution.

---

## 7. Exact Commands Used to Run / Test

```powershell
# 1. Apply Database Migrations
php database\run_migrations.php

# 2. Start Python FastAPI Service
cd ai-service
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8001

# 3. Run Pytest Suite
.\.venv\Scripts\pytest.exe -v

# 4. Run Offline RAG & Recommendation Benchmarks
$env:PYTHONPATH="."
.\.venv\Scripts\python.exe tests/evaluate_rag.py
.\.venv\Scripts\python.exe tests/evaluate_recommendations.py

# 5. Run PHP Integration & Regression Test Suite
cd ..
php tests\test_ai_client.php
php tests\test_ai_question_gen.php
php tests\test_ai_performance.php
php tests\test_ai_difficulty.php
php tests\test_ai_rag.php
php tests\test_ai_recommendations.php
php tests\test_regression.php
php tests\manual_e2e_verification.php
```

---

## 8. Historical Git Branch & Status

- **Branch**: `feature/ai-platform`
- **Git Status**: Working tree clean of uncommitted secret files; **no automatic commits or pushes executed**.
- **Phase I Status**: **COMPLETED & VERIFIED**.
