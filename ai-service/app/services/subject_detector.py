import re
from typing import Optional, Tuple, List

class SubjectDetector:
    """
    Detects intended Subject and Topic from user queries to ensure strict Subject Isolation in RAG retrieval.
    """

    SUBJECT_PATTERNS = {
        "Java": [
            r"\b(java|jvm|jre|jdk|spring|hibernate|servlet|jsp|javadoc)\b"
        ],
        "Python": [
            r"\b(python|pytest|django|flask|numpy|pandas|scipy|pip|py)\b"
        ],
        "C++": [
            r"\b(c\+\+|cpp|stl|clang)\b"
        ],
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
        "Database": [
            r"\b(database|dbms|sql|relational database|rdbms|tables|queries)\b"
        ],
        "Computer Science": [
            r"\b(computer science|cs|data structures|binary search tree|binary tree|bst|algo|algorithm|networking|tcp/ip)\b"
        ]
    }

    TOPIC_PATTERNS = {
        "Grammar": [r"\b(grammar|tenses|verbs|nouns|punctuation)\b"],
        "Differentiation": [r"\b(differentiation|derivatives)\b"],
        "Integration": [r"\b(integration|integrals)\b"],
        "Binary Search Trees": [r"\b(binary search tree|binary search trees|bst|binary tree)\b"],
        "Process Management": [r"\b(process management|process scheduling|cpu scheduling)\b"],
        "TCP/IP": [r"\b(tcp/ip|networking|tcp|ip)\b"],
        "Core Principles": [r"\b(core principles|core concepts|oop|object oriented|classes and objects)\b"]
    }

    TOPIC_TO_SUBJECT_MAP = {
        "Grammar": "English",
        "Differentiation": "Maths",
        "Integration": "Maths",
        "Binary Search Trees": "Computer Science",
        "Process Management": "Operating Systems",
        "TCP/IP": "Computer Science",
        "Core Principles": "Java"
    }

    SUBJECT_NORMALIZATION_MAP = {
        "java": "Java",
        "python": "Python",
        "py": "Python",
        "c++": "C++",
        "cpp": "C++",
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
        cleaned = subject_str.strip()
        lowered = cleaned.lower()
        if lowered in cls.SUBJECT_NORMALIZATION_MAP:
            return cls.SUBJECT_NORMALIZATION_MAP[lowered]
        # Preserve existing casing or title-case if lowercase
        return cleaned if any(c.isupper() for c in cleaned) else cleaned.title()

    @classmethod
    def detect_all(cls, query: str) -> List[str]:
        """
        Detects all distinct subjects explicitly referenced in the user query.
        """
        q_lower = query.lower()
        matched = []

        # 1. Check Topic Patterns
        for topic_name, patterns in cls.TOPIC_PATTERNS.items():
            for pat in patterns:
                if re.search(pat, q_lower):
                    if topic_name in cls.TOPIC_TO_SUBJECT_MAP:
                        s_name = cls.TOPIC_TO_SUBJECT_MAP[topic_name]
                        if s_name not in matched:
                            matched.append(s_name)
                    break

        # 2. Check Subject Patterns
        for subj_name, patterns in cls.SUBJECT_PATTERNS.items():
            for pat in patterns:
                if re.search(pat, q_lower):
                    if subj_name not in matched:
                        matched.append(subj_name)
                    break

        # If a specific programming language or discipline is matched,
        # do not treat general "Computer Science" as an ambiguous secondary subject
        # unless "computer science" or "cs" was explicitly typed.
        if len(matched) > 1 and "Computer Science" in matched:
            specific_subs = [s for s in matched if s in ("Java", "Python", "C++", "Database", "Operating Systems")]
            if specific_subs and not re.search(r"\b(computer science|cs)\b", q_lower):
                matched.remove("Computer Science")

        return matched

    @classmethod
    def detect_ambiguity(cls, query: str) -> List[str]:
        """
        Returns list of matched subjects if multiple distinct subjects are mentioned.
        """
        all_matched = cls.detect_all(query)
        return all_matched if len(all_matched) > 1 else []

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
