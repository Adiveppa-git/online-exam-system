-- Migration 004: Question-Specific Focused Study Notes Table
-- Cross-database compatible schema (PostgreSQL & MySQL)

CREATE TABLE IF NOT EXISTS question_study_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT DEFAULT NULL,
    ai_question_id INT DEFAULT NULL,
    practice_answer_id INT DEFAULT NULL,
    subject VARCHAR(100) NOT NULL,
    topic VARCHAR(100) NOT NULL,
    concept_title VARCHAR(255) NOT NULL,
    notes_content TEXT NOT NULL,
    rag_sources JSON DEFAULT NULL,
    is_grounded BOOLEAN NOT NULL DEFAULT FALSE,
    source_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_qsn_question UNIQUE (question_id),
    CONSTRAINT unique_qsn_ai_question UNIQUE (ai_question_id),
    CONSTRAINT unique_qsn_practice UNIQUE (practice_answer_id),
    CONSTRAINT fk_qsn_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_qsn_ai_question FOREIGN KEY (ai_question_id) REFERENCES ai_generated_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_qsn_practice FOREIGN KEY (practice_answer_id) REFERENCES ai_practice_answers(id) ON DELETE CASCADE
);

CREATE INDEX idx_qsn_subject_topic ON question_study_notes(subject, topic);
