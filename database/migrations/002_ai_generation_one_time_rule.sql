-- Migration 002: One-Time AI Generation per Exam Rule & Schema Enhancements
-- Safe, idempotent migration script for Online Exam System

ALTER TABLE exams
ADD COLUMN IF NOT EXISTS ai_generated INT NOT NULL DEFAULT 0;

ALTER TABLE ai_generation_requests
ADD COLUMN IF NOT EXISTS exam_id INT DEFAULT NULL;

ALTER TABLE ai_generated_questions
ADD COLUMN IF NOT EXISTS exam_id INT DEFAULT NULL;
