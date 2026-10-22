import re
from typing import Optional, Tuple, List

class SubjectDetector:
    """
    Detects intended Subject and Topic from user queries to ensure strict Subject Isolation in RAG retrieval.
    """

    SUBJECT_PATTERNS = {
        "C++": [
            r"(?<!\w)(?:c\+\+|cpp|stl|clang|cxx)(?!\w)"
        ],
        "C": [
            r"(?<!\w)(?:c\s+programming|c\s+language|language\s+c)(?!\w)",
            r"(?:\bpointers?\s+in\s+c(?![a-zA-Z0-9_+#]))",
            r"(?:\b(?:compare|difference\s+between|vs|versus)\s+c\b(?!\+))",
            r"(?:\bc\s+(?:and|vs|versus|or)\s+(?:python|java|javascript|c\+\+|cpp|c#|rust|go)\b)",
            r"(?:\bwhat\s+do\s+you\s+know\s+about\s+c\b(?!\+))",
            r"(?:\b(?:learn|study|master|about)\s+c\s+language\b)"
        ],
        "Java": [
            r"(?<!\w)(?:java|jvm|jre|jdk|spring|hibernate|servlet|jsp|javadoc)(?!\w)"
        ],
        "Python": [
            r"(?<!\w)(?:python|pytest|django|flask|numpy|pandas|scipy|pip|py)(?!\w)"
        ],
        "JavaScript": [
            r"(?<!\w)(?:javascript|typescript|js|ts|nodejs|node\.js|react|express)(?!\w)"
        ],
        "C#": [
            r"(?<!\w)(?:c#|csharp|\.net)(?!\w)"
        ],
        "PHP": [
            r"(?<!\w)(?:php|laravel|symfony)(?!\w)"
        ],
        "GK": [
            r"(?<!\w)(?:gk|general knowledge)(?!\w)"
        ],
        "English": [
            r"(?<!\w)(?:english|grammar|punctuation|sentence|vocabulary|verbs|nouns|tenses|adjectives|adverbs|comprehension|essay|spelling|writing)(?!\w)"
        ],
        "Maths": [
            r"(?<!\w)(?:maths|math|mathematics|calculus|algebra|geometry|trigonometry|differentiation|integration|derivatives|equations|statistics)(?!\w)"
        ],
        "Operating Systems": [
            r"(?<!\w)(?:operating systems|operating system|os|process management|process scheduling|cpu scheduling|memory management|paging|deadlock|concurrency)(?!\w)"
        ],
        "Database": [
            r"(?<!\w)(?:database|dbms|sql|mysql|postgresql|sqlite|rdbms|tables|queries)(?!\w)"
        ],
        "Computer Science": [
            r"(?<!\w)(?:computer science|cs|data structures|binary search tree|binary tree|bst|algo|algorithm|networking|tcp/ip)(?!\w)"
        ]
    }

    TOPIC_PATTERNS = {
        "Grammar": [r"\b(grammar|tenses|verbs|nouns|punctuation)\b"],
        "Differentiation": [r"\b(differentiation|derivatives)\b"],
        "Integration": [r"\b(integration|integrals)\b"],
        "Binary Search Trees": [r"\b(binary search tree|binary search trees|bst|binary tree)\b"],
        "Process Management": [r"\b(process management|process scheduling|cpu scheduling)\b"],
        "TCP/IP": [r"\b(tcp/ip|networking|tcp|ip)\b"],
        "Pointers": [r"(?<!\w)(?:pointers?|pointer\s+arithmetic|pointer\s+syntax|referencing|dereferencing)(?!\w)"],
        "Core Principles": [r"\b(core principles|core concepts|oop|object oriented|classes and objects)\b"]
    }

    TOPIC_TO_SUBJECT_MAP = {
        "Grammar": "English",
        "Differentiation": "Maths",
        "Integration": "Maths",
        "Binary Search Trees": "Computer Science",
        "Process Management": "Operating Systems",
        "TCP/IP": "Computer Science",
        "Pointers": "C++",
        "Core Principles": "Java"
    }

    SUBJECT_NORMALIZATION_MAP = {
        "java": "Java",
        "python": "Python",
        "py": "Python",
        "c++": "C++",
        "cpp": "C++",
        "c": "C",
        "javascript": "JavaScript",
        "js": "JavaScript",
        "typescript": "JavaScript",
        "ts": "JavaScript",
        "c#": "C#",
        "csharp": "C#",
        "php": "PHP",
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
        "dbms": "Database",
        "sql": "Database"
    }

    @classmethod
    def is_comparison(cls, query: str) -> bool:
        """
        Determines whether the query is explicitly asking for a comparison between subjects or concepts.
        """
        q_lower = query.lower()
        return bool(re.search(r"\b(compare|comparison|difference\s+between|vs|versus|compared\s+to|differ\s+from)\b", q_lower))

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

        # 1. Check Subject Patterns first for explicit language mentions
        for subj_name, patterns in cls.SUBJECT_PATTERNS.items():
            for pat in patterns:
                if re.search(pat, q_lower):
                    if subj_name not in matched:
                        matched.append(subj_name)
                    break

        # 2. Check Topic Patterns if no explicit subject matched
        if not matched:
            for topic_name, patterns in cls.TOPIC_PATTERNS.items():
                for pat in patterns:
                    if re.search(pat, q_lower):
                        if topic_name in cls.TOPIC_TO_SUBJECT_MAP:
                            s_name = cls.TOPIC_TO_SUBJECT_MAP[topic_name]
                            if s_name not in matched:
                                matched.append(s_name)
                        break

        # If C++ was matched, do not allow 'C' to trigger false ambiguity
        if len(matched) > 1 and "C++" in matched and "C" in matched:
            is_c_and_cpp_comp = bool(re.search(r"(?:\bc\s+(?:and|vs|versus)\s+c\+\+|\bc\+\+\s+(?:and|vs|versus)\s+c\b)", q_lower))
            if not is_c_and_cpp_comp and not re.search(r"(?<!\w)(?:c\s+programming|c\s+language|language\s+c)(?!\w)", q_lower):
                matched.remove("C")

        # If a specific programming language or discipline is matched,
        # do not treat general "Computer Science" as an ambiguous secondary subject
        # unless "computer science" or "cs" was explicitly typed.
        if len(matched) > 1 and "Computer Science" in matched:
            specific_subs = [s for s in matched if s in ("Java", "Python", "C++", "C", "JavaScript", "Database", "Operating Systems")]
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

        # 1. Check explicit Subject Patterns first (explicit language/subject takes priority)
        explicit_subject = None
        # Check C++ before C to avoid ambiguity
        for subj_name, patterns in cls.SUBJECT_PATTERNS.items():
            for pat in patterns:
                if re.search(pat, q_lower):
                    explicit_subject = subj_name
                    break
            if explicit_subject:
                break

        # 2. Check Topic Patterns
        for topic_name, patterns in cls.TOPIC_PATTERNS.items():
            for pat in patterns:
                if re.search(pat, q_lower):
                    detected_topic = topic_name
                    if not explicit_subject and topic_name in cls.TOPIC_TO_SUBJECT_MAP:
                        detected_subject = cls.TOPIC_TO_SUBJECT_MAP[topic_name]
                    break
            if detected_topic:
                break

        if explicit_subject:
            detected_subject = explicit_subject

        normalized_subject = cls.normalize_subject(detected_subject)
        return (normalized_subject, detected_topic)
