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
            r"(?<!\w)(?:javascript|typescript|js|ts|nodejs|node\.js|react|express|angular|vue)(?!\w)"
        ],
        "SQL": [
            r"(?<!\w)(?:sql|plsql|t-sql|tsql|mysql|postgresql|postgres|sqlite|sqlite3|structured\s+query\s+language)(?!\w)"
        ],
        "C#": [
            r"(?<!\w)(?:c#|csharp|\.net|dotnet)(?!\w)"
        ],
        "PHP": [
            r"(?<!\w)(?:php|laravel|symfony|composer|codeigniter)(?!\w)"
        ],
        "Electronics": [
            r"(?<!\w)(?:electronics|electronic\s+devices|analog\s+circuits?|digital\s+electronics?|semiconductors?|transistors?|bjt|mosfet|diodes?|op-amp|operational\s+amplifier|logic\s+gates?|microprocessors?|microcontrollers?|vlsi|embedded\s+systems?)(?!\w)"
        ],
        "Communication Systems": [
            r"(?<!\w)(?:communication\s+systems?|analog\s+communication|digital\s+communication|modulation|demodulation|amplitude\s+modulation|frequency\s+modulation|phase\s+modulation|am\s+modulation|fm\s+modulation|pcm|pulse\s+code\s+modulation|antennas?|telecommunications?)(?!\w)"
        ],
        "Physics": [
            r"(?<!\w)(?:physics|mechanics|kinematics|thermodynamics|electromagnetism|electrostatics|optics|quantum\s+physics|newton's\s+laws?|gravitation|wave\s+optics|magnetism|relativity)(?!\w)"
        ],
        "Chemistry": [
            r"(?<!\w)(?:chemistry|organic\s+chemistry|inorganic\s+chemistry|periodic\s+table|chemical\s+bonding|chemical\s+reactions?|stoichiometry|electrochemistry|polymers|biomolecules)(?!\w)"
        ],
        "Biology": [
            r"(?<!\w)(?:biology|botany|zoology|cell\s+biology|genetics|ecology|human\s+physiology|photosynthesis|cellular\s+respiration|evolution|dna|rna|microbiology)(?!\w)"
        ],
        "Maths": [
            r"(?<!\w)(?:maths|math|mathematics|calculus|algebra|geometry|trigonometry|differentiation|integration|derivatives|equations|statistics|probability|matrices|matrix|arithmetic)(?!\w)"
        ],
        "Operating Systems": [
            r"(?<!\w)(?:operating\s+systems?|operating\s+system|os|process\s+management|process\s+scheduling|cpu\s+scheduling|memory\s+management|paging|deadlock|concurrency|virtual\s+memory)(?!\w)"
        ],
        "Database": [
            r"(?<!\w)(?:database\s+management\s+systems?|dbms|database|relational\s+databases?|rdbms|normalization|acid\s+properties|entity\s+relationship|er\s+diagram)(?!\w)"
        ],
        "Computer Science": [
            r"(?<!\w)(?:computer\s+science|cs|data\s+structures|binary\s+search\s+tree|binary\s+tree|bst|algo|algorithm|dsa|networking|tcp/ip)(?!\w)"
        ],
        "English": [
            r"(?<!\w)(?:english|grammar|punctuation|sentence|vocabulary|verbs|nouns|tenses|adjectives|adverbs|comprehension|essay|spelling|writing)(?!\w)"
        ],
        "GK": [
            r"(?<!\w)(?:gk|general\s+knowledge|current\s+affairs)(?!\w)"
        ]
    }

    TOPIC_PATTERNS = {
        "Grammar": [r"\b(grammar|tenses|verbs|nouns|punctuation)\b"],
        "Differentiation": [r"\b(differentiation|derivatives)\b"],
        "Integration": [r"\b(integration|integrals)\b"],
        "Binary Search Trees": [r"\b(binary search tree|binary search trees|bst|binary tree)\b"],
        "Process Management": [r"\b(process management|process scheduling|cpu scheduling)\b"],
        "TCP/IP": [r"\b(tcp/ip|networking|tcp|ip)\b"],
        "Modulation": [r"\b(modulation|demodulation|amplitude modulation|frequency modulation|am modulation|fm modulation|pulse code modulation)\b"],
        "Semiconductors": [r"\b(semiconductors?|transistors?|bjt|mosfet|diodes?|op-amp|operational amplifier)\b"],
        "Newton's Laws": [r"\b(newton's\s+laws?|kinematics|gravitation)\b"],
        "Chemical Bonding": [r"\b(chemical bonding|covalent bond|ionic bond|periodic table)\b"],
        "Cell Biology": [r"\b(cell biology|mitosis|meiosis|photosynthesis|dna|rna)\b"]
    }

    TOPIC_TO_SUBJECT_MAP = {
        "Grammar": "English",
        "Differentiation": "Maths",
        "Integration": "Maths",
        "Binary Search Trees": "Computer Science",
        "Process Management": "Operating Systems",
        "TCP/IP": "Computer Science",
        "Modulation": "Communication Systems",
        "Semiconductors": "Electronics",
        "Newton's Laws": "Physics",
        "Chemical Bonding": "Chemistry",
        "Cell Biology": "Biology"
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
        "sql": "SQL",
        "c#": "C#",
        "csharp": "C#",
        "php": "PHP",
        "gk": "GK",
        "general knowledge": "GK",
        "math": "Maths",
        "maths": "Maths",
        "mathematics": "Maths",
        "physics": "Physics",
        "chemistry": "Chemistry",
        "biology": "Biology",
        "electronics": "Electronics",
        "communication systems": "Communication Systems",
        "communication system": "Communication Systems",
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

        # If SQL was matched alongside Database, prefer SQL unless 'database' or 'dbms' was explicitly typed
        if len(matched) > 1 and "SQL" in matched and "Database" in matched:
            if not re.search(r"\b(database|dbms|rdbms)\b", q_lower):
                matched.remove("Database")

        # If a specific programming language or discipline is matched,
        # do not treat general "Computer Science" as an ambiguous secondary subject
        # unless "computer science" or "cs" was explicitly typed.
        if len(matched) > 1 and "Computer Science" in matched:
            specific_subs = [s for s in matched if s in ("Java", "Python", "C++", "C", "JavaScript", "SQL", "Database", "Operating Systems", "Electronics", "Communication Systems", "Physics", "Chemistry", "Biology")]
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
