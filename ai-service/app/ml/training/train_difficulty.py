import os
import joblib
import numpy as np
from typing import Dict, Any, Tuple
from sklearn.model_selection import train_test_split
from sklearn.linear_model import LogisticRegression
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score, confusion_matrix
from app.ml.features.feature_extractor import extract_features_from_dict, FEATURE_NAMES

def generate_synthetic_benchmark_dataset(num_samples: int = 500) -> Tuple[np.ndarray, np.ndarray]:
    """
    Generates synthetic question interaction data for ML pipeline validation.
    Note: Target (empirical_difficulty) is constructed from correct_rate thresholds.
    This creates a direct relationship used for pipeline validation only.
    """
    np.random.seed(42)
    X_list = []
    y_list = []

    for i in range(num_samples):
        attempts = np.random.randint(10, 200)
        true_diff = np.random.choice(['easy', 'medium', 'hard'], p=[0.35, 0.45, 0.20])
        
        if true_diff == 'easy':
            correct_rate = np.random.uniform(0.75, 0.98)
        elif true_diff == 'medium':
            correct_rate = np.random.uniform(0.45, 0.74)
        else:
            correct_rate = np.random.uniform(0.05, 0.44)
            
        correct = int(round(attempts * correct_rate))
        students = int(round(attempts * np.random.uniform(0.7, 1.0)))
        topic_acc = float(np.clip(correct_rate * 100 + np.random.normal(0, 5), 0, 100))
        subject_acc = float(np.clip(correct_rate * 100 + np.random.normal(0, 8), 0, 100))

        feat_dict = {
            'total_attempts': attempts,
            'correct_attempts': correct,
            'unique_students': students,
            'topic_avg_accuracy': topic_acc,
            'subject_avg_accuracy': subject_acc
        }
        feats = extract_features_from_dict(feat_dict)
        X_list.append(feats)
        y_list.append(true_diff)

    return np.array(X_list), np.array(y_list)

def train_and_evaluate_models() -> Dict[str, Any]:
    X, y = generate_synthetic_benchmark_dataset(num_samples=600)
    X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.20, random_state=42, stratify=y)

    # 1. Baseline: Majority Class Classifier
    majority_class = max(set(y_train), key=list(y_train).count)
    y_pred_base = [majority_class] * len(y_test)
    base_acc = accuracy_score(y_test, y_pred_base)
    base_f1 = f1_score(y_test, y_pred_base, average='macro', zero_division=0)

    # 2. Model 1: Logistic Regression
    lr = LogisticRegression(multi_class='multinomial', max_iter=500, random_state=42)
    lr.fit(X_train, y_train)
    y_pred_lr = lr.predict(X_test)
    lr_acc = accuracy_score(y_test, y_pred_lr)
    lr_prec = precision_score(y_test, y_pred_lr, average='macro', zero_division=0)
    lr_rec = recall_score(y_test, y_pred_lr, average='macro', zero_division=0)
    lr_f1 = f1_score(y_test, y_pred_lr, average='macro', zero_division=0)

    # 3. Model 2: Random Forest Classifier
    rf = RandomForestClassifier(n_estimators=100, max_depth=6, random_state=42)
    rf.fit(X_train, y_train)
    y_pred_rf = rf.predict(X_test)
    rf_acc = accuracy_score(y_test, y_pred_rf)
    rf_prec = precision_score(y_test, y_pred_rf, average='macro', zero_division=0)
    rf_rec = recall_score(y_test, y_pred_rf, average='macro', zero_division=0)
    rf_f1 = f1_score(y_test, y_pred_rf, average='macro', zero_division=0)
    rf_cm = confusion_matrix(y_test, y_pred_rf, labels=['easy', 'medium', 'hard'])

    # Save artifact with clear synthetic_benchmark label
    models_dir = os.path.join(os.path.dirname(__file__), '..', 'models')
    os.makedirs(models_dir, exist_ok=True)
    model_path = os.path.abspath(os.path.join(models_dir, 'difficulty_model.joblib'))

    artifact = {
        'model': rf,
        'classes': list(rf.classes_),
        'feature_names': FEATURE_NAMES,
        'model_version': 'difficulty-rf-v1.0-synthetic',
        'data_mode': 'synthetic_benchmark',
        'benchmark_notice': 'Synthetic Benchmark — Pipeline Validation Only. Not evidence of production model accuracy.',
        'metrics': {
            'accuracy': float(rf_acc),
            'precision': float(rf_prec),
            'recall': float(rf_rec),
            'f1_score': float(rf_f1)
        }
    }
    joblib.dump(artifact, model_path)

    return {
        'dataset_size': len(X),
        'train_size': len(X_train),
        'test_size': len(X_test),
        'data_mode': 'synthetic_benchmark',
        'baseline': {'accuracy': float(base_acc), 'f1_score': float(base_f1), 'majority_class': majority_class},
        'logistic_regression': {'accuracy': float(lr_acc), 'precision': float(lr_prec), 'recall': float(lr_rec), 'f1_score': float(lr_f1)},
        'random_forest': {'accuracy': float(rf_acc), 'precision': float(rf_prec), 'recall': float(rf_rec), 'f1_score': float(rf_f1), 'confusion_matrix': rf_cm.tolist()},
        'selected_model': 'RandomForestClassifier',
        'model_path': model_path
    }

if __name__ == '__main__':
    res = train_and_evaluate_models()
    print("=== ML DIFFICULTY PIPELINE VALIDATION COMPLETED ===")
    print(f"Data Mode: {res['data_mode'].upper()} (Pipeline Validation Only)")
    print(f"Dataset Size: {res['dataset_size']} samples")
    print(f"Baseline Accuracy: {res['baseline']['accuracy']:.4f}")
    print(f"Logistic Regression Acc: {res['logistic_regression']['accuracy']:.4f}, F1: {res['logistic_regression']['f1_score']:.4f}")
    print(f"Random Forest Acc: {res['random_forest']['accuracy']:.4f}, F1: {res['random_forest']['f1_score']:.4f}")
    print(f"Model saved to: {res['model_path']}")