import re
import logging
from typing import Tuple, Optional, List, Dict, Any

logger = logging.getLogger("intent_classifier")

class IntentClassifier:
    """
    Intelligent Intent Classification Service for the Online Examination System AI Assistant.
    Distinguishes user goals across 10 core categories + conversational & privacy guards:
    1. general_educational
    2. exam_preparation
    3. concept_explanation
    4. coding_help
    5. practice_questions
    6. performance_analysis
    7. personalized_recommendations
    8. material_search
    9. system_support
    10. follow_up

    Conversational / System guards:
    - greeting, thanks, conversation_ok, goodbye, help
    - assistant_identity, current_user_identity, other_user_information, admin_information
    - off_topic, clarification
    """

    GREETING_TOKENS = [
        r"good\s+morning",
        r"good\s+afternoon",
        r"good\s+evening",
        r"hi+",
        r"hello",
        r"hey+",
        r"greetings",
        r"howdy",
        r"hola"
    ]

    THANKS_PATTERNS = [
        r"^(thank you|thanks|thanks a lot|thank you so much|many thanks|thx|ty)\b"
    ]

    OK_PATTERNS = [
        r"^(okay|ok|k|alright|got it)\b"
    ]

    BYE_PATTERNS = [
        r"^(bye|goodbye|see you|see ya|cya|take care)\b"
    ]

    HELP_PATTERNS = [
        r"^(help|help me|can you help me|i need help)\b"
    ]

    ASSISTANT_IDENTITY_PATTERNS = [
        r"what is your name",
        r"whats your name",
        r"who are you",
        r"what are you",
        r"tell me about yourself",
        r"are you an ai",
        r"who created you",
        r"what can you do",
        r"what are your capabilities"
    ]

    USER_IDENTITY_PATTERNS = [
        r"what is my name",
        r"whats my name",
        r"who am i",
        r"what username am i using",
        r"who am i logged in as",
        r"my account",
        r"my user name",
        r"my profile"
    ]

    OTHER_USER_PATTERNS = [
        r"personal info",
        r"personal information",
        r"email",
        r"password",
        r"phone",
        r"mobile",
        r"contact",
        r"login info",
        r"login information",
        r"private details",
        r"private info",
        r"private data",
        r"friend",
        r"another student",
        r"other student",
        r"tell me about my friend",
        r"tell me about student\b",
        r"tell me about user\b",
        r"who is student\b",
        r"who is user\b",
        r"tell me about (?:student|user|friend|classmate|candidate)\b",
        r"marks of (?:student|user|friend|classmate)\b",
        r"performance of (?:student|user|friend|classmate)\b",
        r"score of (?:student|user|friend|classmate)\b",
        r"information of (?:student|user|friend|classmate)\b",
        r"details of (?:student|user|friend|classmate)\b",
        r"profile of (?:student|user|friend|classmate)\b"
    ]

    ADMIN_PATTERNS = [
        r"how many students",
        r"total students",
        r"how many exams",
        r"total exams",
        r"how many questions",
        r"question bank size",
        r"system statistics",
        r"system\s+(admin|administrator)?\s*stats",
        r"admin\s+(stats|statistics)",
        r"administrator\s+(stats|statistics)",
        r"system\s+stats",
        r"admin dashboard",
        r"total results"
    ]

    # Specific Follow-Up Patterns referring to preceding turn
    FOLLOW_UP_PATTERNS = [
        r"explain\s+(the\s+)?(first|second|third|fourth|fifth|1st|2nd|3rd|4th|5th|last)\s+point",
        r"explain\s+point\s+\d+",
        r"what\s+about\s+(the\s+)?(first|second|third|1st|2nd|3rd)\s+(point|one|phase|step)",
        r"can\s+you\s+give\s+(me\s+)?an\s+example\s+of\s+(that|this|it)",
        r"give\s+(me\s+)?an\s+example\s+of\s+(that|this|it)",
        r"show\s+(me\s+)?an\s+example\s+of\s+(that|this|it)",
        r"how\s+can\s+i\s+prepare\s+in\s+\d+\s+days",
        r"prepare\s+in\s+\d+\s+days",
        r"\d+[- ]day\s+(study\s+)?(plan|schedule|timetable)",
        r"explain\s+more(\s+about\s+that)?",
        r"tell\s+me\s+more(\s+about\s+that)?",
        r"elaborate(\s+on\s+that)?",
        r"continue",
        r"go\s+on",
        r"what\s+else\s+should\s+i\s+know",
        r"give\s+more\s+details"
    ]

    # Practice Questions / Quiz Request Patterns
    PRACTICE_QUESTIONS_PATTERNS = [
        r"\b(give\s+me|generate|create|provide|show\s+me)\s+(\d+\s+)?([A-Za-z0-9_+\-]+\s+)*(practice\s+)?(questions?|mcqs?|quiz|problems?)\b",
        r"\b\d+\s+([A-Za-z0-9_+\-]+\s+)*(practice\s+)?(questions?|mcqs?)\b",
        r"\bquiz\s+me\b",
        r"\bpractice\s+(questions?|quiz|mcqs?)\b",
        r"\btest\s+me\s+on\b",
        r"\bquestions?\s+on\s+(loops?|oop|functions?|arrays?|strings?|syntax|recursion|database|sql)\b",
        r"\bsample\s+questions?\b"
    ]

    # Coding Help, Debugging & Programming Performance Patterns
    CODING_HELP_PATTERNS = [
        r"\b(write|create|implement|code)\s+(a\s+)?([A-Za-z0-9_+\-]+\s+)*(program|code|script|function|class|method|algorithm)\b",
        r"\b(python|java|c\+\+|javascript|c#|sql)\s+(function|code|script|program)\s+to\b",
        r"\bhow\s+to\s+.*\s+in\s+(python|java|c\+\+|javascript|c#|sql)\b",
        r"\bhow\s+to\s+(reverse|sort|search|traverse|filter|map|find|iterate)\b",
        r"\bhow\s+to\s+(code|implement|write\s+code)\b",
        r"\b(debug|fix\s+(this\s+)?(code|error|bug|issue))\b",
        r"\b(nullpointerexception|syntaxerror|typeerror|indexerror|nameerror|zerodivisionerror|runtime\s+error)\b",
        r"\bwhy\s+(is\s+)?(my\s+)?code\s+(not\s+working|throwing|failing|giving\s+error)\b",
        r"\b(improve|optimize|boost|speed\s+up|accelerate)\s+(my\s+)?([A-Za-z0-9_+#]+\s+)?(performance|speed|efficiency|runtime|memory|code)\b",
        r"\bhow\s+(can|do|should)\s+i\s+(improve|optimize|boost|speed\s+up)\s+(my\s+)?([A-Za-z0-9_+#]+\s+)?(performance|speed|code|efficiency)\b",
        r"\bhow\s+to\s+(improve|optimize|boost|speed\s+up)\s+(my\s+)?([A-Za-z0-9_+#]+\s+)?(performance|speed|code|efficiency)\b",
        r"\b(java|python|c\+\+|javascript|c|sql|code)\s+(performance|optimization|efficiency)\b",
        r"\bpython\s+(code|script|program)\b",
        r"\bjava\s+(code|program)\b",
        r"\bc\+\+\s+(code|program)\b",
        r"\bjavascript\s+(code|script)\b",
        r"\bsql\s+query\s+to\b"
    ]

    # Exam Performance Analysis (Student's Actual Exam Marks/Results)
    PERFORMANCE_ANALYSIS_PATTERNS = [
        r"\b(show|view|check|get|display|tell\s+me)\s+(my\s+)?(exam\s+)?(results?|marks?|scores?|grades?)\b",
        r"\bwhat\s+(are|is|were|was)\s+my\s+(exam\s+)?(results?|marks?|scores?|grades?|accuracy)\b",
        r"\b(what\s+did\s+i\s+score|how\s+much\s+did\s+i\s+score|how\s+many\s+marks\s+did\s+i\s+get)\b",
        r"\bmy\s+(exam\s+)?(marks?|scores?|results?|grades?)\b",
        r"\bmarks\s+(in|for)\s+my\s+[A-Za-z0-9_+#]+\s+(exam|test)\b",
        r"\bmy\s+exam\s+scores?\b",
        r"\bmy\s+results?\b",
        r"\bhow\s+did\s+i\s+do(\s+in\s+the\s+exam)?\b",
        r"\bdid\s+i\s+pass(\s+the\s+exam)?\b",
        r"\bhow\s+am\s+i\s+performing\b",
        r"\bmy\s+performance\s+record\b",
        r"\bmy\s+exam\s+performance\b"
    ]

    # Personalized Recommendations (Diagnosing student exam weaknesses, improving exam marks)
    PERSONALIZED_RECOMMENDATIONS_PATTERNS = [
        r"\bhow\s+(can|do|should)\s+i\s+improve\s+(my\s+)?(exam\s+)?(marks?|scores?|grades?|rank)\b",
        r"\bhow\s+to\s+improve\s+(my\s+)?(exam\s+)?(marks?|scores?|grades?|rank)\b",
        r"\bhow\s+(can|do|should)\s+i\s+improve\s+my\s+exam\s+performance\b",
        r"\bhow\s+to\s+improve\s+my\s+exam\s+performance\b",
        r"\bhow\s+to\s+improve\s+in\s+(my\s+)?exams?\b",
        r"\bimprove\s+my\s+(exam\s+)?(marks?|scores?|grades?)\b",
        r"\bwhy\s+am\s+i\s+getting\s+low\s+(marks?|scores?|grades?)\b",
        r"\b(not\s+getting|low|poor|bad)\s+([a-z]+\s+)*(marks?|scores?|grades?)\b",
        r"\blow\s+(marks?|scores?|grades?)\b",
        r"\bwhich\s+subject\s+am\s+i\s+weak\s+in\b",
        r"\bwhat\s+are\s+my\s+weak\s+(topics?|areas?|subjects?)\b",
        r"\bweakest\s+subject\b",
        r"\bweak\s+in\s+[A-Za-z0-9_+#]+\b",
        r"\bsuggest\s+(me\s+)?how\s+(can|do|to|should)\s+(i\s+)?improve(\s+(my\s+)?(marks?|scores?|grades?))?\b",
        r"\bwhich\s+subject\s+should\s+i\s+improve\b"
    ]

    # Exam Preparation and Study Planning (Non-performance strategic guides)
    EXAM_PREPARATION_PATTERNS = [
        r"\bhow\s+(can|do|should|to)\s+prepare(\s+for)?\b",
        r"\bhow\s+to\s+prepare\s+for\b",
        r"\bprepare\s+for\s+(.*)?exam\b",
        r"\bpreparation\s+(strategy|tips|plan|guide)\b",
        r"\bstudy\s+(plan|schedule|timetable|strategy)\b",
        r"\brevision\s+(plan|checklist|timetable|strategy)\b",
        r"\bhow\s+to\s+study\s+for\b",
        r"\bhow\s+to\s+crack\b",
        r"\bexam\s+prep(aration)?\b",
        r"\bwhat\s+should\s+i\s+study\s+for\b"
    ]

    # Study Material Search and Summarization
    MATERIAL_SEARCH_PATTERNS = [
        r"\b(find|search|show|get|list|display)\s+(the\s+)?(study\s+materials?|course\s+materials?|notes|study\s+notes|handouts?)\b",
        r"\bwhat\s+materials?\s+are\s+uploaded\b",
        r"\bsummarize\s+(the\s+)?(course\s+materials?|study\s+notes?|notes?|guide)\b",
        r"\bsummarize\s+.*(study\s+notes?|course\s+materials?|notes?)\b",
        r"\b(uploaded|course)\s+study\s+notes\b",
        r"\bwhere\s+are\s+the\s+study\s+materials?\b"
    ]

    # Examination System Support
    SYSTEM_SUPPORT_PATTERNS = [
        r"\bexam\s+timer\b",
        r"\bhow\s+does\s+the\s+(exam\s+)?timer\s+work\b",
        r"\btab\s+switch(ing)?\b",
        r"\btab\s+violation(s)?\b",
        r"\bwhat\s+happens\s+if\s+i\s+switch\s+tabs\b",
        r"\bhow\s+to\s+submit(\s+the\s+exam)?\b",
        r"\bcan\s+i\s+retake\s+an\s+exam\b",
        r"\bwhere\s+(is|can\s+i\s+see)\s+my\s+certificate\b",
        r"\brules\s+of\s+the\s+exam\b",
        r"\bhow\s+does\s+the\s+exam\s+system\s+work\b"
    ]

    # Concept Explanation Patterns
    CONCEPT_EXPLANATION_PATTERNS = [
        r"^explain\b",
        r"\bwhat\s+is\s+a\b",
        r"\bwhat\s+is\s+an\b",
        r"\bwhat\s+is\b",
        r"\bwhat\s+are\b",
        r"\bwhat\s+do\s+you\s+know\s+about\b",
        r"\btell\s+me\s+about\b",
        r"\bwhat\s+can\s+you\s+tell\s+me\s+about\b",
        r"\bhow\s+does\s+.*\s+work\b",
        r"\bdifference\s+between\b",
        r"\bcompare\b",
        r"\bcomparison\b",
        r"\bvs\.?\b",
        r"\bversus\b",
        r"\bcompared\s+to\b",
        r"\bdiffer\s+from\b",
        r"\bpointers?\s+in\b",
        r"\bdefinition\s+of\b",
        r"\bteach\s+me\s+about\b",
        r"\boverview\s+of\b",
        r"\bdescribe\b",
        r"\bmeaning\s+of\b"
    ]

    EXAM_INFO_PATTERNS = [
        r"what exams are available",
        r"available exams",
        r"list of exams",
        r"which exams can i take"
    ]

    OFF_TOPIC_PATTERNS = [
        r"capital of france",
        r"who is the president",
        r"what is the weather",
        r"tell me a joke",
        r"who won the match"
    ]

    @classmethod
    def classify(cls, question: str, conversation_history: Optional[List[Dict[str, Any]]] = None) -> Tuple[str, str]:
        """
        Classifies user question into one of the system intents.
        Returns: (intent_name, direct_response)
        """
        q_raw = question.strip()
        q_clean = q_raw.lower()
        q_alphanum = re.sub(r'[^\w\s]', ' ', q_clean).strip()

        # 1. Current User Identity
        for pat in cls.USER_IDENTITY_PATTERNS:
            if pat in q_clean or pat in q_alphanum:
                return ("current_user_identity", "")

        # 2. Assistant Identity
        for pat in cls.ASSISTANT_IDENTITY_PATTERNS:
            if pat in q_clean or pat in q_alphanum:
                return (
                    "assistant_identity",
                    "I am the AI Assistant (AI Study Assistant) for the Online Examination System. I can help you with concept explanations, exam preparation and study planning, coding help, practice quizzes, your performance analysis, and course study materials."
                )

        # 3. Thanks
        for pat in cls.THANKS_PATTERNS:
            if re.search(pat, q_clean):
                return ("thanks", "You're welcome! 😊 I'm always here to help you study and prepare for your exams.")

        # 4. OK / Acknowledgement
        for pat in cls.OK_PATTERNS:
            if re.search(pat, q_clean):
                return ("conversation_ok", "Sure! Let me know what you'd like to explore or practice next.")

        # 5. Goodbye
        for pat in cls.BYE_PATTERNS:
            if re.search(pat, q_clean):
                return ("goodbye", "Goodbye! 👋 Wishing you the very best with your studies and upcoming exams!")

        # 6. Help
        for pat in cls.HELP_PATTERNS:
            if re.search(pat, q_clean):
                return (
                    "help",
                    "I can assist you with:\n"
                    "- 📚 **Concept Explanations**: e.g., *'Explain inheritance in Java'*\n"
                    "- 📅 **Exam Preparation & Study Plans**: e.g., *'How to prepare for Java exam?'*\n"
                    "- 💻 **Coding Help & Debugging**: e.g., *'Write a function to reverse a string'*\n"
                    "- 📝 **Practice Questions**: e.g., *'Give me 10 Python loop questions'*\n"
                    "- 📊 **Exam Performance Analysis**: e.g., *'Show my exam results'*\n"
                    "- 🎯 **Personalized Improvement Tips**: e.g., *'How can I improve my marks?'*\n"
                    "- 📖 **Approved Course Materials**: e.g., *'Find study notes for Python'*"
                )

        # 7. How are you
        if "how are you" in q_clean:
            return ("greeting", "I'm doing well, thank you! 😊 How can I assist with your studies today?")

        # 8. Greetings
        for tok in cls.GREETING_TOKENS:
            m = re.match(r'^(' + tok + r')(?:\s+(.*))?$', q_clean)
            if m:
                greeting_matched = m.group(1)
                remainder = m.group(2).strip() if m.group(2) else ""
                if remainder:
                    sub_intent, sub_direct = cls.classify(remainder, conversation_history)
                    if sub_direct:
                        return (sub_intent, f"Hello! 👋 {sub_direct}")
                    else:
                        return (sub_intent, "")
                else:
                    if "morning" in greeting_matched:
                        resp = "Good morning! ☀️ I'm your AI Study Assistant. What subject or exam would you like help with today?"
                    elif "afternoon" in greeting_matched:
                        resp = "Good afternoon! 😊 Ready to study? Let me know how I can help!"
                    elif "evening" in greeting_matched:
                        resp = "Good evening! 🌙 What would you like to review or prepare for tonight?"
                    else:
                        resp = "Hello! 👋 I'm your AI Study Assistant. How can I help you study or prepare for your exams today?"
                    return ("greeting", resp)

        # 9. Other User Information (Privacy guard)
        for pat in cls.OTHER_USER_PATTERNS:
            if re.search(pat, q_clean) or ("friend" in q_clean and any(w in q_clean for w in ["marks", "score", "password", "details", "info"])):
                return ("other_user_information", "")

        # 10. Admin Statistics Guard
        for pat in cls.ADMIN_PATTERNS:
            if re.search(pat, q_clean):
                return ("admin_information", "")

        # 11. Contextual Follow-Up Questions (Check if conversational history exists)
        if conversation_history and len(conversation_history) > 0:
            for pat in cls.FOLLOW_UP_PATTERNS:
                if re.search(pat, q_clean):
                    return ("follow_up", "")

        # Even without explicit history passed, specific follow-up language indicates follow-up
        for pat in cls.FOLLOW_UP_PATTERNS:
            if re.search(pat, q_clean):
                return ("follow_up", "")

        # 12. Practice Questions and Quizzes
        for pat in cls.PRACTICE_QUESTIONS_PATTERNS:
            if re.search(pat, q_clean):
                return ("practice_questions", "")

        # 13. Coding Help & Debugging
        for pat in cls.CODING_HELP_PATTERNS:
            if re.search(pat, q_clean):
                return ("coding_help", "")

        # 14. Performance Analysis (Checking student results / scores)
        for pat in cls.PERFORMANCE_ANALYSIS_PATTERNS:
            if re.search(pat, q_clean):
                return ("performance_analysis", "")

        # 15. Personalized Recommendations (Improving marks / diagnosing weaknesses)
        # Check this BEFORE Exam Preparation so "how can I improve my marks" goes to recommendations
        for pat in cls.PERSONALIZED_RECOMMENDATIONS_PATTERNS:
            if re.search(pat, q_clean):
                return ("personalized_recommendations", "")

        # 16. Exam Preparation & Study Planning (Non-performance preparation guides)
        for pat in cls.EXAM_PREPARATION_PATTERNS:
            if re.search(pat, q_clean):
                return ("exam_preparation", "")

        # 17. Study Material Search & Summarization
        for pat in cls.MATERIAL_SEARCH_PATTERNS:
            if re.search(pat, q_clean):
                return ("material_search", "")

        # 18. Examination System Support
        for pat in cls.SYSTEM_SUPPORT_PATTERNS:
            if re.search(pat, q_clean):
                return ("system_support", "")

        # 19. Available Exams info
        for pat in cls.EXAM_INFO_PATTERNS:
            if re.search(pat, q_clean):
                return ("system_support", "")

        # 20. Off-topic queries
        for pat in cls.OFF_TOPIC_PATTERNS:
            if pat in q_clean:
                return (
                    "off_topic",
                    "I’m designed to assist with your educational studies, exam preparation, and the Online Examination System. Please feel free to ask any academic or course-related question!"
                )

        # 21. Concept Explanation (Explicit "explain", "what is", "difference between")
        for pat in cls.CONCEPT_EXPLANATION_PATTERNS:
            if re.search(pat, q_clean):
                return ("concept_explanation", "")

        # Default fallback
        return ("general_educational", "")
