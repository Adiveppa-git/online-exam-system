import os
import joblib
import numpy as np
from typing import Dict, Any
from app.ml.features.feature_extractor import extract_features_from_dict
from app.schemas.ml_difficulty import DifficultyPredictRequest, DifficultyPredictResponse

MODEL_PATH = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'models', 'difficulty_model.joblib'))

_cached_artifact = None

def _get_model_artifact():
    global _cached_artifact
    if _cached_artifact is None:
        if not os.path.exists(MODEL_PATH):
            raise FileNotFoundError(f"ML Model artifact not found at: {MODEL_PATH}. Train model first.")
        _cached_artifact = joblib.load(MODEL_PATH)
    return _cached_artifact

class DifficultyPredictor:
    @staticmethod
    def predict(req: DifficultyPredictRequest) -> DifficultyPredictResponse:
        attempts = req.total_attempts
        min_thresh = req.min_attempts_threshold
        correct_rate = (req.correct_attempts / attempts) if attempts > 0 else 0.0

        artifact = _get_model_artifact()
        model_version = artifact.get('model_version', 'difficulty-rf-v1.0')

        # Cold start / Insufficient data guard
        if attempts < min_thresh:
            return DifficultyPredictResponse(
                question_id=req.question_id,
                status="insufficient_real_data",
                data_mode="real_data_production",
                predicted_difficulty="insufficient_data",
                confidence=0.0,
                observed_correct_rate=round(correct_rate, 4),
                model_version=model_version,
                message=f"Insufficient real student interaction data ({attempts} attempts vs minimum required {min_thresh}).",
                disclaimer=f"Insufficient real student data (< {min_thresh} attempts). Fallback to assigned difficulty."
            )

        # Extract feature vector
        feat_dict = {
            'total_attempts': req.total_attempts,
            'correct_attempts': req.correct_attempts,
            'unique_students': req.unique_students,
            'topic_avg_accuracy': req.topic_avg_accuracy,
            'subject_avg_accuracy': req.subject_avg_accuracy
        }
        features = extract_features_from_dict(feat_dict)
        X_in = np.array([features])

        model = artifact['model']
        classes = list(artifact['classes'])

        # Inference
        probs = model.predict_proba(X_in)[0]
        max_idx = int(np.argmax(probs))
        predicted_label = str(classes[max_idx])
        confidence = float(probs[max_idx])

        # Low-data warning vs sufficient sample size disclaimer
        if attempts < 30:
            disclaimer = f"Early Real-Data Inference (Low Sample Size: {attempts} attempts). Low statistical confidence; recommended target for robust retraining is >= 30 attempts across >= 50 distinct students."
        else:
            disclaimer = f"Real Student Data Production Inference ({attempts} attempts). Retraining sample threshold met."

        return DifficultyPredictResponse(
            question_id=req.question_id,
            status="predicted",
            data_mode="real_data_production",
            predicted_difficulty=predicted_label,
            confidence=round(confidence, 4),
            observed_correct_rate=round(correct_rate, 4),
            model_version=model_version,
            message=f"[REAL_DATA_PRODUCTION] Classified empirical difficulty as {predicted_label.upper()} with {round(confidence*100, 1)}% confidence.",
            disclaimer=disclaimer
        )