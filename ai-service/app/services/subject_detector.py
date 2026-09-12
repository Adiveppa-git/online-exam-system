import re
from typing import Optional, Tuple

class SubjectDetector:
    """
    Detects intended Subject and Topic from user queries to ensure strict Subject Isolation in RAG retrieval.
    """

    SUBJECT_PATTERNS = {
        "Maths": [
            r"\b(maths|math|mathematics|calculus|algebra|geometry|trigonometry|differentiation|integration|derivatives|equations|statistics)\b"
        ],
        "English": [
            r"\b(english|grammar|punctuation|sentence|vocabulary|verbs|nouns|tenses|adjectives|adverbs|comprehension|essay|spelling|writing)\b"
        ],
        "Computer Science": [
            r"\b(computer science|cs|programming|data structures|binary search tree|binary tree|bst|sql|database|dbms|operating system|operating systems|process management|scheduling|python|java|c\+\+|algo|algorithm|networking|tcp/ip)\b"
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

    @classmethod
    def detect(cls, query: str) -> Tuple[Optional[str], Optional[str]]:
        """
        Returns (detected_subject, detected_topic) based on user query string.
        """
        q_lower = query.lower()

        detected_subject = None
        detected_topic = None

        # Check Subject Patterns
        for subj_name, patterns in cls.SUBJECT_PATTERNS.items():
            for pat in patterns:
                if re.search(pat, q_lower):
                    detected_subject = subj_name
                    break
            if detected_subject:
                break

        # Check Topic Patterns
        for topic_name, patterns in cls.TOPIC_PATTERNS.items():
            for pat in patterns:
                if re.search(pat, q_lower):
                    detected_topic = topic_name
                    break
            if detected_topic:
                break

        return (detected_subject, detected_topic)
