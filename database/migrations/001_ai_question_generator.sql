-- Migration 001: AI Question Generator Staging & Metadata Tables
-- Safe, non-destructive migration script for Online Exam System

ALTER TABLE questions 
ADD COLUMN IF NOT EXISTS subject VARCHAR(100) DEFAULT 'General',
ADD COLUMN IF NOT EXISTS topic VARCHAR(100) DEFAULT 'General',
ADD COLUMN IF NOT EXISTS difficulty ENUM('easy', 'medium', 'hard') DEFAULT 'medium',
ADD COLUMN IF NOT EXISTS explanation TEXT DEFAULT NULL;

CREATE TABLE IF NOT EXISTS ai_generation_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(64) UNIQUE NOT NULL,
    admin_id INT NOT NULL,
    subject VARCHAR(100) NOT NULL,
    topic VARCHAR(100) NOT NULL,
    difficulty ENUM('easy', 'medium', 'hard') NOT NULL,
    question_type VARCHAR(50) NOT NULL DEFAULT 'mcq',
    number_requested INT NOT NULL,
    additional_context TEXT DEFAULT NULL,
    model_used VARCHAR(100) NOT NULL,
    status ENUM('success', 'failed') NOT NULL DEFAULT 'success',
    error_message TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ai_req_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_generated_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(64) NOT NULL,
    admin_id INT NOT NULL,
    subject VARCHAR(100) NOT NULL,
    topic VARCHAR(100) NOT NULL,
    difficulty ENUM('easy', 'medium', 'hard') NOT NULL DEFAULT 'medium',
    question_type VARCHAR(50) NOT NULL DEFAULT 'mcq',
    question TEXT NOT NULL,
    option_a VARCHAR(255) NOT NULL,
    option_b VARCHAR(255) NOT NULL,
    option_c VARCHAR(255) NOT NULL,
    option_d VARCHAR(255) NOT NULL,
    correct_option CHAR(1) NOT NULL,
    explanation TEXT DEFAULT NULL,
    generation_model VARCHAR(100) DEFAULT 'gpt-4o-mini',
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    rejection_reason VARCHAR(255) DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_status (status),
    KEY idx_request_id (request_id),
    CONSTRAINT fk_ai_gen_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;