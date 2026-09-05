# AI Engineering & System Overview

> [!IMPORTANT]
> **Portfolio Technical Summary**:
> This document details the AI engineering principles, model lifecycle, evaluation methodology, failure modes, and architectural safeguards implemented in the platform.

---

## 1. System Philosophy & Scientific Distinction

The platform distinguishes strictly between four core AI/ML techniques:
1. **Deterministic Analytics Engine**: Computes exact mathematical metrics (accuracy, performance trend, bounded priority scores). LLMs are **never** permitted to decide numerical metrics or classifications.
2. **Machine Learning Models**: Random Forest and Logistic Regression estimating empirical item difficulty from interaction features.
3. **Retrieval-Augmented Generation (RAG)**: `sentence-transformers` embedding search in `ChromaDB` providing course material context with exact page citations.
4. **Large Language Models (LLM)**: Serves exclusively as a structured generator (MCQ generation) and natural-language explanation layer.

---

## 2. Component Engineering Breakdown

```
+------------------------------------------------------------------------+
¦                        AI ENGINEERING SUBSYSTEMS                       ¦
+------------------------------------------------------------------------¦
¦ 1. Question Generator (Phase D)                                        ¦
¦    - LLM JSON schema validation + 5-template heuristic fallback        ¦
¦ 2. Performance Analytics (Phase E)                                     ¦
¦    - Deterministic topic accuracy & trend analysis                     ¦
¦ 3. ML Difficulty Prediction (Phase F)                                  ¦
¦    - Synthetic benchmark re-labeled; cold start guards (< 5 attempts)  ¦
¦ 4. RAG Study Assistant (Phase G)                                       ¦
¦    - pypdf, TextChunker (500ch/50ov), ChromaDB, page citations         ¦
¦ 5. Adaptive Recommendation Engine (Phase H)                            ¦
¦    - Bounded priority score [0.0 - 1.0], isolated practice sessions   ¦
+------------------------------------------------------------------------+
```

---

## 3. Evaluation & Benchmark Methodology

- **Synthetic Benchmark (ML Difficulty)**: Random Forest model evaluated on 600 synthetic samples. Accuracies (~99.17%) are explicitly documented as synthetic pipeline validation only due to feature-target leakage.
- **Development Benchmark (RAG)**: Evaluated on test queries measuring Recall@K (100%), citation accuracy (100%), and out-of-domain rejection (100%).
- **Development Benchmark (Recommendations)**: Evaluated against fictional student profiles A–H measuring deterministic recommendation correctness (100%).

---

## 4. Production Readiness & Known Limitations

- **XAMPP & Docker Deployment**: Dual setup allowing local development via XAMPP or containerized orchestration via `docker-compose`.
- **Cold Start Guards**: Systems gracefully return `insufficient_data` when student attempts $< 5$, preventing false assertions.
- **Zero API Key Requirement**: All automated test suites operate via deterministic fallback paths, eliminating external API costs during CI/CD.
