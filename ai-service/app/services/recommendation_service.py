import logging
from typing import List, Dict, Any, Optional
from app.config import settings
from app.services.llm_service import llm_service

logger = logging.getLogger(__name__)

class RecommendationService:
    @classmethod
    def build_student_profile(cls, student_id: int, history: List[Dict[str, Any]]) -> Dict[str, Any]:
        """
        Computes deterministic student learning profile, data sufficiency, topic classifications,
        trend analysis, and bounded recommendation priority scores.
        """
        if not history:
            return {
                "student_id": student_id,
                "status": "insufficient_data",
                "message": "Complete an exam or practice session to receive personalized recommendations.",
                "overall_accuracy": 0.0,
                "total_questions": 0,
                "total_exams": 0,
                "strong_topics": [],
                "developing_topics": [],
                "weak_topics": [],
                "topic_metrics": [],
                "recommendations": []
            }

        total_questions = len(history)
        correct_questions = sum(1 for item in history if item.get("is_correct") or item.get("correct"))
        overall_accuracy = round((correct_questions / total_questions) * 100, 1) if total_questions > 0 else 0.0

        # Unique exam count
        exam_ids = set(item.get("exam_id") for item in history if item.get("exam_id"))
        total_exams = len(exam_ids)

        # Group attempts by topic
        grouped_topics: Dict[str, List[Dict[str, Any]]] = {}
        for item in history:
            subject = item.get("subject", "General")
            topic = item.get("topic", "General")
            key = f"{subject}::: {topic}"
            if key not in grouped_topics:
                grouped_topics[key] = []
            grouped_topics[key].append(item)

        topic_metrics: List[Dict[str, Any]] = []
        strong_list = []
        developing_list = []
        weak_list = []
        recommendations_list = []

        for key, attempts in grouped_topics.items():
            subject, topic = key.split("::: ")
            n_attempts = len(attempts)
            n_correct = sum(1 for a in attempts if a.get("is_correct") or a.get("correct"))
            simple_acc = (n_correct / n_attempts) if n_attempts > 0 else 0.0

            # Data Sufficiency Guard
            if n_attempts < settings.MIN_TOPIC_ATTEMPTS:
                metric = {
                    "subject": subject,
                    "topic": topic,
                    "attempts": n_attempts,
                    "correct": n_correct,
                    "accuracy": round(simple_acc * 100, 1),
                    "status": "insufficient_data",
                    "classification": "INSUFFICIENT_DATA",
                    "trend": "insufficient_data",
                    "priority_score": 0.0,
                    "recommended_difficulty": "medium",
                    "reason": "insufficient_attempts",
                    "explanation": f"Not enough data yet ({n_attempts}/{settings.MIN_TOPIC_ATTEMPTS} attempts). Complete more practice questions for a reliable recommendation."
                }
                topic_metrics.append(metric)
                continue

            # Weighted Topic Accuracy calculation (60% Recent / 40% Historical)
            if n_attempts >= 6:
                half = n_attempts // 2
                hist_attempts = attempts[:half]
                rec_attempts = attempts[half:]

                hist_acc = sum(1 for a in hist_attempts if a.get("is_correct") or a.get("correct")) / len(hist_attempts)
                rec_acc = sum(1 for a in rec_attempts if a.get("is_correct") or a.get("correct")) / len(rec_attempts)

                topic_score = (settings.RECENT_ACCURACY_WEIGHT * rec_acc) + (settings.HISTORICAL_ACCURACY_WEIGHT * hist_acc)
            else:
                hist_acc = simple_acc
                rec_acc = simple_acc
                topic_score = simple_acc

            # Trend Calculation
            if rec_acc > hist_acc + settings.TREND_MARGIN:
                trend = "improving"
            elif rec_acc < hist_acc - settings.TREND_MARGIN:
                trend = "declining"
            else:
                trend = "stable"

            # Classification
            if topic_score >= settings.STRONG_ACCURACY_THRESHOLD:
                classification = "STRONG"
                rec_diff = "hard"
                reason_tag = "strong_topic_challenge"
                rec_action = "challenge_practice"
                strong_list.append(topic)
            elif topic_score >= settings.DEVELOPING_ACCURACY_THRESHOLD:
                classification = "DEVELOPING"
                rec_diff = "medium"
                reason_tag = "developing_topic"
                rec_action = "review_and_practice"
                developing_list.append(topic)
            else:
                classification = "NEEDS_IMPROVEMENT"
                rec_diff = "easy"
                reason_tag = "accuracy_below_threshold" if trend != "declining" else "recent_performance_declining"
                rec_action = "review_and_easy_practice"
                weak_list.append(topic)

            # Bounded Priority Score Calculation [0.0 - 1.0]
            weakness_score = max(0.0, 1.0 - topic_score)
            
            if trend == "declining":
                trend_score = 1.0
            elif trend == "stable":
                trend_score = 0.5
            else:  # improving
                trend_score = 0.0

            recency_score = 0.5  # Standard normalized baseline

            priority_score = (
                settings.PRIORITY_WEAKNESS_WEIGHT * weakness_score +
                settings.PRIORITY_TREND_WEIGHT * trend_score +
                settings.PRIORITY_RECENCY_WEIGHT * recency_score
            )
            priority_score = round(min(1.0, max(0.0, priority_score)), 2)

            explanation_text = (
                f"Topic accuracy is {round(topic_score*100, 1)}% across {n_attempts} attempts "
                f"with a {trend} trend ({reason_tag})."
            )

            metric_entry = {
                "subject": subject,
                "topic": topic,
                "attempts": n_attempts,
                "correct": n_correct,
                "accuracy": round(topic_score * 100, 1),
                "recent_accuracy": round(rec_acc * 100, 1),
                "historical_accuracy": round(hist_acc * 100, 1),
                "status": classification.lower(),
                "classification": classification,
                "trend": trend,
                "priority_score": priority_score,
                "recommended_difficulty": rec_diff,
                "recommended_action": rec_action,
                "reason": reason_tag,
                "explanation": explanation_text
            }
            topic_metrics.append(metric_entry)

            if classification in ["NEEDS_IMPROVEMENT", "DEVELOPING"]:
                recommendations_list.append(metric_entry)

        # Sort recommendations by priority score descending
        topic_metrics.sort(key=lambda x: x.get("priority_score", 0.0), reverse=True)
        recommendations_list.sort(key=lambda x: x.get("priority_score", 0.0), reverse=True)

        return {
            "student_id": student_id,
            "status": "reliable" if total_questions >= settings.MIN_TOPIC_ATTEMPTS else "insufficient_data",
            "message": "Student learning profile generated successfully.",
            "overall_accuracy": overall_accuracy,
            "total_questions": total_questions,
            "total_exams": total_exams,
            "strong_topics": strong_list,
            "developing_topics": developing_list,
            "weak_topics": weak_list,
            "topic_metrics": topic_metrics,
            "recommendations": recommendations_list
        }

    @classmethod
    def generate_personalized_plan(
        cls,
        profile: Dict[str, Any],
        rag_service_fn,
        llm_service_obj
    ) -> Dict[str, Any]:
        """
        Integrates RAG course materials and LLM explanation layer to generate an actionable study plan.
        """
        metrics = profile.get("topic_metrics", [])
        if not metrics:
            return {
                "student_id": profile.get("student_id"),
                "status": "insufficient_data",
                "message": "Complete an exam or practice session to receive a personalized study plan.",
                "plan_items": []
            }

        plan_items = []

        for metric in metrics[:5]:  # Top 5 priority topics
            subj = metric["subject"]
            top = metric["topic"]

            # Search RAG material using Phase G RAG service
            try:
                rag_res = rag_service_fn(query=f"{top} core concepts", subject=subj, topic=top, top_k=2)
                has_rag = rag_res.get("has_sufficient_context", False)
                rag_chunks = rag_res.get("chunks", []) if has_rag else []
            except Exception as e:
                logger.warning(f"RAG lookup failed for topic '{top}': {e}")
                has_rag = False
                rag_chunks = []

            sources = []
            if has_rag and rag_chunks:
                for c in rag_chunks:
                    sources.append({
                        "filename": c.get("filename"),
                        "page_number": c.get("page_number", 1)
                    })

            plan_items.append({
                "subject": subj,
                "topic": top,
                "classification": metric["classification"],
                "accuracy": metric["accuracy"],
                "trend": metric["trend"],
                "priority_score": metric["priority_score"],
                "recommended_difficulty": metric["recommended_difficulty"],
                "recommended_question_count": settings.DEFAULT_PRACTICE_COUNT,
                "reason": metric["reason"],
                "has_course_material": has_rag,
                "course_material_notice": "Study material available" if has_rag else "No course material is currently available for this topic.",
                "sources": sources,
                "suggested_action": f"Review course material and complete {settings.DEFAULT_PRACTICE_COUNT} {metric['recommended_difficulty'].capitalize()} questions."
            })

        # LLM Natural-Language Explanation Layer
        summary_text = cls._generate_llm_summary(profile, plan_items, llm_service_obj)

        return {
            "student_id": profile.get("student_id"),
            "status": "success",
            "overall_accuracy": profile.get("overall_accuracy"),
            "summary_explanation": summary_text,
            "plan_items": plan_items
        }

    @classmethod
    def _generate_llm_summary(cls, profile: Dict[str, Any], plan_items: List[Dict[str, Any]], llm_obj) -> str:
        """
        Generates natural-language summary without altering deterministic recommendations.
        """
        weak_tops = profile.get("weak_topics", [])
        dev_tops = profile.get("developing_topics", [])
        str_tops = profile.get("strong_topics", [])

        deterministic_summary = (
            f"Your current overall accuracy is {profile.get('overall_accuracy')}%. "
            f"Focus on improving {', '.join(weak_tops) if weak_tops else 'your developing topics'} "
            f"by reviewing study materials and practicing targeted questions."
        )

        if not getattr(settings, "LLM_API_KEY", None) or getattr(settings, "LLM_PROVIDER", "") == "heuristic":
            return deterministic_summary

        try:
            sys_prompt = "You are an encouraging academic study advisor. Summarize the student's study plan in 2-3 concise sentences based ONLY on the provided stats."
            user_prompt = f"Overall Accuracy: {profile.get('overall_accuracy')}%. Weak Topics: {weak_tops}. Developing Topics: {dev_tops}. Strong Topics: {str_tops}."
            llm_text = llm_obj.generate_completion(sys_prompt, user_prompt)
            return llm_text.strip()
        except Exception as e:
            logger.warning(f"LLM summary generation failed: {e}")
            return deterministic_summary
