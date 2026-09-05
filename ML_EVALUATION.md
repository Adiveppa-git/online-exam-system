# ML Difficulty Prediction — Evaluation & Target Leakage Audit

> [!WARNING]
> **Scientific Guard & Evaluation Framing**:
> The 99.17% Random Forest accuracy achieved on the synthetic benchmark dataset is **explicitly labeled as pipeline validation performance**, NOT real-world production model accuracy.

---

## 1. Target Leakage & Label Construction Analysis

During Phase F implementation, the empirical difficulty target label $Y \in \{\text{easy}, \text{medium}, \text{hard}\}$ was constructed directly from question-level correct rates:

$$Y = \begin{cases} \text{easy} & \text{if } \text{correct\_rate} \ge 0.75 \\ \text{medium} & \text{if } 0.45 \le \text{correct\_rate} < 0.75 \\ \text{hard} & \text{if } \text{correct\_rate} < 0.45 \end{cases}$$

Because `correct_rate` was also included as an input feature in vector $X = [f_1, f_2, \dots, \text{correct\_rate}, \dots, f_8]$, tree-based classifiers (such as Random Forest) easily find decision splits at `0.75` and `0.45`. The model essentially memorized the rule used to generate the label.

$$\hat{Y} = \arg\max P(Y \mid X) \approx \text{Rule}(X_{\text{correct\_rate}})$$

---

## 2. Synthetic Benchmark Model Performance

| Classifier Model | Accuracy | Precision (Weighted) | Recall (Weighted) | F1-Score | Data Mode Tag |
| :--- | :---: | :---: | :---: | :---: | :--- |
| **Baseline (Majority Class)** | 33.33% | 11.11% | 33.33% | 16.67% | `synthetic_benchmark` |
| **Logistic Regression** | 96.67% | 96.75% | 96.67% | 96.68% | `synthetic_benchmark` |
| **Random Forest Classifier** | **99.17%** | **99.19%** | **99.17%** | **99.17%** | `synthetic_benchmark` |

---

## 3. Cold Start & Production Safeguards

- **Cold-Start Guard**: When a question has $< 5$ real student attempts in the database, the system returns `status: "insufficient_real_data"` and falls back to the existing assigned difficulty.
- **Data Mode Labeling**: API responses explicitly include `data_mode: "synthetic_benchmark"` or `"real_student_data"`.
- **Human Oversight**: Machine learning difficulty predictions **never** automatically overwrite `questions.difficulty` in the MySQL database. An administrator must manually confirm updates in the admin portal (`admin/ai_difficulty_analytics.php`).

---

## 4. Production Re-Training & Validation Strategy

When live production interaction volume reaches $\ge 30$ attempts per question across $\ge 50$ distinct students:
1. **GroupKFold by Question ID**: Prevents data leakage by ensuring unseen questions are evaluated during validation.
2. **Item Response Theory (IRT - 2PL / 3PL Models)**: Transition from hand-written thresholds to true latent difficulty estimation ($b_j$) and discrimination ($a_j$).
