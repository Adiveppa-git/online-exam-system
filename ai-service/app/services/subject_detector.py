import re
from typing import Optional, Tuple

class SubjectDetector:
    """
    Detects intended Subject and Topic from user queries to ensure strict Subject Isolation in RAG retrieval.
    """

    SUBJECT_PATTERNS = {
        "GK": [
            r"\b(gk|general knowledge)\b"
        ],
        "English": [
            r"\b(english|grammar|punctuation|sentence|vocabulary|verbs|nouns|tenses|adjectives|adverbs|comprehension|essay|spelling|writing)\b"
        ],
        "Maths": [
            r"\b(maths|math|mathematics|calculus|algebra|geometry|trigonometry|differentiation|integration|derivatives|equations|statistics)\b"
        ],
        "Operating Systems": [
            r"\b(operating systems|operating system|os|process management|process scheduling|cpu scheduling|memory management|paging|deadlock|concurrency)\b"
        ],
        "Computer Science": [
            r"\b(computer science|cs|programming|data structures|binary search tree|binary tree|bst|python|java|c\+\+|algo|algorithm|networking|tcp/ip)\b"
        ],
        "Database": [
            r"\b(database|dbms|sql|relational database|rdbms|tables|queries)\b"
        ]
    }

    TOPIC_PATTERNS = {
        "Grammar": [r"\b(grammar|tenses|verbs|nouns|punctuation)\b"],
        "Differentiation": [r"\b(differentiation|derivatives)\b"],
        "Integration": [r"\b(integration|integrals)\b"],
        "Binary Search Trees": [r"\b(binary search tree|binary search trees|bst|binary tree)\b"],
        "Process Management": [r"\b(process management|process scheduling|cpu scheduling)\b"],
        "TCP/IP": [r"\b(tcp/ip|networking|tcp|ip)\b"]
    }

    TOPIC_TO_SUBJECT_MAP = {
        "Grammar": "English",
        "Differentiation": "Maths",
        "Integration": "Maths",
        "Binary Search Trees": "Computer Science",
        "Process Management": "Operating Systems",
        "TCP/IP": "Computer Science"
    }

    SUBJECT_NORMALIZATION_MAP = {
        "gk": "GK",
        "general knowledge": "GK",
        "math": "Maths",
        "maths": "Maths",
        "mathematics": "Maths",
        "english": "English",
        "operating systems": "Operating Systems",
        "operating system": "Operating Systems",
        "os": "Operating Systems",
        "computer science": "Computer Science",
        "cs": "Computer Science",
        "database": "Database",
        "dbms": "Database"
    }

    @classmethod
    def normalize_subject(cls, subject_str: Optional[str]) -> Optional[str]:
        if not subject_str:
            return None
        cleaned = subject_str.strip().lower()
        return cls.SUBJECT_NORMALIZATION_MAP.get(cleaned, subject_str.strip())

    @classmethod
    def detect(cls, query: str) -> Tuple[Optional[str], Optional[str]]:
        """
        Returns (detected_subject, detected_topic) based on user query string.
        """
        q_lower = query.lower()

        detected_subject = None
        detected_topic = None

        # 1. Check Topic Patterns first (more specific)
        for topic_name, patterns in cls.TOPIC_PATTERNS.items():
            for pat in patterns:
                if re.search(pat, q_lower):
                    detected_topic = topic_name
                    if topic_name in cls.TOPIC_TO_SUBJECT_MAP:
                        detected_subject = cls.TOPIC_TO_SUBJECT_MAP[topic_name]
                    break
            if detected_topic:
                break

        # 2. Check Subject Patterns if subject not already mapped from topic
        if not detected_subject:
            for subj_name, patterns in cls.SUBJECT_PATTERNS.items():
                for pat in patterns:
                    if re.search(pat, q_lower):
                        detected_subject = subj_name
                        break
                if detected_subject:
                    break

        normalized_subject = cls.normalize_subject(detected_subject)
        return (normalized_subject, detected_topic)
