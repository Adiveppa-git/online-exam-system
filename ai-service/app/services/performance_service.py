from typing import List, Dict, Any
from app.schemas.performance import (
    StudentPerformanceInput, StudentPerformanceOutput,
    TopicClassificationItem, TrendAnalysis
)

class PerformanceService:
    @staticmethod
    def analyze_performance(data: StudentPerformanceInput) -> StudentPerformanceOutput:
        strong_thresh = data.strong_threshold
        weak_thresh = data.weak_threshold

        total_exams = len(data.exams)
        total_questions = sum(t.attempted for t in data.topics)
        total_correct = sum(t.correct for t in data.topics)
        total_incorrect = total_questions - total_correct

        overall_accuracy = (total_correct / total_questions * 100.0) if total_questions > 0 else 0.0
        
        avg_exam_pct = (sum(e.percentage for e in data.exams) / total_exams) if total_exams > 0 else 0.0

        all_topics: List[TopicClassificationItem] = []
        strong_topics: List[TopicClassificationItem] = []
        weak_topics: List[TopicClassificationItem] = []

        for t in data.topics:
            accuracy = round(t.accuracy, 2)
            if accuracy >= strong_thresh:
                cls = "Strong"
            elif accuracy < weak_thresh:
                cls = "Weak"
            else:
                cls = "Developing"

            item = TopicClassificationItem(
                subject=t.subject,
                topic=t.topic,
                attempted=t.attempted,
                correct=t.correct,
                accuracy=accuracy,
                classification=cls
            )
            all_topics.append(item)
            if cls == "Strong":
                strong_topics.append(item)
            elif cls == "Weak":
                weak_topics.append(item)

        # Trend Analysis over time
        trend = PerformanceService._calculate_trend(data.exams)

        return StudentPerformanceOutput(
            student_id=data.student_id,
            total_exams_attempted=total_exams,
            total_questions_attempted=total_questions,
            total_correct=total_correct,
            total_incorrect=max(0, total_incorrect),
            overall_accuracy=round(overall_accuracy, 2),
            average_exam_percentage=round(avg_exam_pct, 2),
            strong_topics=strong_topics,
            weak_topics=weak_topics,
            all_topic_performance=all_topics,
            trend=trend,
            thresholds={
                "strong_threshold": strong_thresh,
                "weak_threshold": weak_thresh
            }
        )

    @staticmethod
    def _calculate_trend(exams: List[Any]) -> TrendAnalysis:
        if len(exams) < 2:
            return TrendAnalysis(
                has_trend=False,
                direction="insufficient_data",
                message="Not enough historical exam data yet."
            )

        # Sorted by taken_at if available or original order
        prev_pct = round(exams[-2].percentage, 2)
        curr_pct = round(exams[-1].percentage, 2)
        diff = round(curr_pct - prev_pct, 2)

        if diff > 0:
            direction = "improving"
            msg = f"Performance improved by +{diff} percentage points since previous exam."
        elif diff < 0:
            direction = "declining"
            msg = f"Performance declined by {abs(diff)} percentage points since previous exam."
        else:
            direction = "stable"
            msg = "Performance remained stable compared to previous exam."

        return TrendAnalysis(
            has_trend=True,
            previous_percentage=prev_pct,
            current_percentage=curr_pct,
            trend_percentage_points=diff,
            direction=direction,
            message=msg
        )