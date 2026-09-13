import re
from typing import Tuple

class IntentClassifier:
    """
    Intelligent Intent Classification Service for the Online Examination System AI Assistant.
    Categorizes user queries into specific system intent categories BEFORE any RAG retrieval:
    - greeting
    - thanks
    - conversation_ok
    - goodbye
    - help
    - assistant_identity
    - current_user_identity
    - other_user_information
    - admin_information
    - performance
    - recommendation
    - exam_information
    - off_topic
    - academic_question
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
        r"tell me about student",
        r"tell me about user",
        r"who is student",
        r"who is user",
        r"tell me about \w+",
        r"marks of \w+",
        r"performance of \w+",
        r"score of \w+",
        r"information of \w+",
        r"details of \w+",
        r"profile of \w+"
    ]

    ADMIN_PATTERNS = [
        r"how many students",
        r"total students",
        r"how many exams",
        r"total exams",
        r"how many questions",
        r"question bank size",
        r"system statistics",
        r"admin dashboard",
        r"total results"
    ]

    PERFORMANCE_PATTERNS = [
        r"how am i performing",
        r"how did i do",
        r"why am i getting low marks",
        r"my performance",
        r"my accuracy",
        r"my exam scores",
        r"my grades",
        r"which subject am i weak in",
        r"weakest subject"
    ]

    RECOMMENDATION_PATTERNS = [
        r"not getting (more|good|high) marks",
        r"low marks",
        r"low score",
        r"weak in",
        r"how can i improve",
        r"how to improve",
        r"suggest me how",
        r"what should i study",
        r"scored low",
        r"improve my (marks|score|performance|grade|grades)",
        r"my weak topics",
        r"which subject should i improve",
        r"which subject to improve",
        r"which subject do i need to improve"
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
    def classify(cls, question: str) -> Tuple[str, str]:
        """
        Classifies user question string and returns (intent_name, direct_response).
        If intent requires database/RAG retrieval, direct_response will be empty string.
        """
        q_raw = question.strip()
        q_lower = re.sub(r'[^\w\s]', '', q_raw.lower()).strip()

        # 1. Current User Identity
        for pat in cls.USER_IDENTITY_PATTERNS:
            if pat in q_lower:
                return ("current_user_identity", "")

        # 2. Assistant Identity
        for pat in cls.ASSISTANT_IDENTITY_PATTERNS:
            if pat in q_lower:
                return (
                    "assistant_identity",
                    "I'm the AI Assistant for the Online Examination System. I can help you with exams, performance, study materials, recommendations, practice, and other information available to you."
                )

        # 3. Thanks
        for pat in cls.THANKS_PATTERNS:
            if re.search(pat, q_lower):
                return ("thanks", "You're welcome! 😊 I'm here whenever you need help.")

        # 4. OK / Acknowledgement
        for pat in cls.OK_PATTERNS:
            if re.search(pat, q_lower):
                return ("conversation_ok", "Sure! Let me know what you'd like help with.")

        # 5. Goodbye
        for pat in cls.BYE_PATTERNS:
            if re.search(pat, q_lower):
                return ("goodbye", "Goodbye! 👋 All the best with your studies!")

        # 6. Help
        for pat in cls.HELP_PATTERNS:
            if re.search(pat, q_lower):
                return (
                    "help",
                    "Of course! You can ask me about your exams, marks, performance, study materials, recommendations, practice questions, or the examination system."
                )

        # 7. How are you
        if "how are you" in q_lower:
            return ("greeting", "I'm doing well, thank you! 😊 How can I help you today?")

        # 8. Greetings (single or compound)
        for tok in cls.GREETING_TOKENS:
            m = re.match(r'^(' + tok + r')(?:\s+(.*))?$', q_lower)
            if m:
                greeting_matched = m.group(1)
                remainder = m.group(2).strip() if m.group(2) else ""
                if remainder:
                    # Compound query: e.g. "hi explain grammar"
                    sub_intent, sub_direct = cls.classify(remainder)
                    if sub_direct:
                        return (sub_intent, f"Hello! 👋 {sub_direct}")
                    else:
                        return (sub_intent, "")
                else:
                    # Single greeting
                    if "morning" in greeting_matched:
                        resp = "Good morning! ☀️ I'm your AI Assistant. How can I help you today?"
                    elif "afternoon" in greeting_matched:
                        resp = "Good afternoon! 😊 How can I help you today?"
                    elif "evening" in greeting_matched:
                        resp = "Good evening! 🌙 How can I help you today?"
                    else:
                        resp = "Hello! 👋 I'm your AI Assistant. How can I help you today?"
                    return ("greeting", resp)

        # 9. Other User Information
        for pat in cls.OTHER_USER_PATTERNS:
            if re.search(pat, q_lower) or "friend" in q_lower:
                return ("other_user_information", "")

        # 10. Admin System Stats
        for pat in cls.ADMIN_PATTERNS:
            if re.search(pat, q_lower):
                return ("admin_information", "")

        # 11. Exam Information
        for pat in cls.EXAM_INFO_PATTERNS:
            if re.search(pat, q_lower):
                return ("exam_information", "")

        # 12. Performance Queries
        for pat in cls.PERFORMANCE_PATTERNS:
            if re.search(pat, q_lower):
                return ("performance", "")

        # 13. Recommendation Queries
        for pat in cls.RECOMMENDATION_PATTERNS:
            if re.search(pat, q_lower):
                return ("recommendation", "")

        # 14. Off-topic
        for pat in cls.OFF_TOPIC_PATTERNS:
            if pat in q_lower:
                return (
                    "off_topic",
                    "I’m designed primarily to assist with the Online Examination System, your performance, and course materials. Please ask a system or study-related question."
                )

        # Default to academic_question
        return ("academic_question", "")
