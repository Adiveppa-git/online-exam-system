# Adaptive Recommendation Engine — Evaluation & Rule Benchmark

> [!NOTE]
> **Evaluation Framing**: **Development Benchmark / Rule Validation**
> Evaluates the deterministic recommendation priority scoring, data sufficiency guards, topic classifications, and difficulty selection rules against fictional student profiles representing key edge cases.

---

## 1. Bounded Priority Scoring & Component Weights

Priority scores are normalized and bounded strictly between $0.0$ and $1.0$:

$$\text{weakness\_score} = 1.0 - \text{topic\_score}$$

$$\text{trend\_score} = \begin{cases} 1.0 & \text{if declining} \\ 0.5 & \text{if stable / insufficient} \\ 0.0 & \text{if improving} \end{cases}$$

$$\text{recency\_score} = 0.5 \quad (\text{normalized baseline})$$

$$\text{priority\_score} = 0.5 \times \text{weakness\_score} + 0.3 \times \text{trend\_score} + 0.2 \times \text{recency\_score}$$

---

## 2. Benchmark Student Evaluation Results

| Benchmark Profile | Description / Behavior | Expected Recommendation | Benchmark Result | Status |
| :--- | :--- | :--- | :---: | :---: |
| **Student A** | Weak Scheduling (35%), Strong Memory (85%) | Prioritize Scheduling first | **PASSED** | **VERIFIED** |
| **Student B** | Insufficient attempts ($< 5$) | Status: `insufficient_data` | **PASSED** | **VERIFIED** |
| **Student C** | Improving accuracy trend | Trend: `improving`, lower priority | **PASSED** | **VERIFIED** |
| **Student D** | High accuracy ($\ge 80\%$) | Classification: `STRONG`, Diff: `hard` | **PASSED** | **VERIFIED** |
| **Student E** | Boundary test: Exactly 5 attempts | Status: `reliable` / Classified | **PASSED** | **VERIFIED** |
| **Student F** | Boundary test: Accuracy = 50% | Classification: `DEVELOPING` | **PASSED** | **VERIFIED** |
| **Student G** | Boundary test: Accuracy = 80% | Classification: `STRONG` | **PASSED** | **VERIFIED** |
| **Student H** | Declining trend with low score | Trend: `declining`, High Priority ($\ge 0.5$) | **PASSED** | **VERIFIED** |

---

## 3. Explainability & LLM Safety Safeguards

- **Machine-Readable Reason Tags**: Every recommendation includes reason tags (`accuracy_below_threshold`, `recent_performance_declining`, `developing_topic`, `strong_topic_challenge`, `insufficient_attempts`).
- **LLM Non-Overriding Guard**: The LLM serves solely as a natural-language explanation layer. It **cannot** alter deterministic topic classifications, priority scores, or difficulty progression.
