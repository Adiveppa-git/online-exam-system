-- Migration 006: Support for Multiple Question Types (Coding, Short Answer, Descriptive, Mixed)
-- Safe, backward-compatible schema migration for Online Exam System

-- 1. Update ai_generated_questions to allow NULL options for non-MCQ questions
ALTER TABLE ai_generated_questions
    MODIFY COLUMN option_a VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN option_b VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN option_c VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN option_d VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN correct_option TEXT NULL DEFAULT NULL;

-- 2. Add coding and rich question fields to ai_generated_questions
ALTER TABLE ai_generated_questions
    ADD COLUMN IF NOT EXISTS programming_language VARCHAR(50) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS input_format TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS output_format TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS constraints TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS sample_test_cases TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS hidden_test_cases TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS reference_solution TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS starter_code TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS time_complexity VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS space_complexity VARCHAR(100) NULL DEFAULT NULL;

-- 3. Update questions table to support question_type, nullable options, and coding fields
ALTER TABLE questions
    MODIFY COLUMN option_a VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN option_b VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN option_c VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN option_d VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN correct_option TEXT NULL DEFAULT NULL;

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS question_type VARCHAR(50) NOT NULL DEFAULT 'mcq',
    ADD COLUMN IF NOT EXISTS programming_language VARCHAR(50) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS starter_code TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS input_format TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS output_format TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS constraints TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS sample_test_cases TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS hidden_test_cases TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS reference_solution TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS time_complexity VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS space_complexity VARCHAR(100) NULL DEFAULT NULL;

