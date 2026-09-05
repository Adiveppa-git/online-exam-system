-- Migration 003: AI Personalized Practice Sessions & Immutable Question Snapshots
CREATE TABLE IF NOT EXISTS `ai_practice_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `subject` VARCHAR(100) NOT NULL,
  `topic` VARCHAR(100) NOT NULL,
  `difficulty` ENUM('easy', 'medium', 'hard') NOT NULL DEFAULT 'medium',
  `total_questions` INT NOT NULL DEFAULT 5,
  `status` ENUM('in_progress', 'completed') NOT NULL DEFAULT 'in_progress',
  `score` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  INDEX `idx_student_id` (`student_id`),
  INDEX `idx_topic` (`topic`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_practice_answers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `student_id` INT NOT NULL,
  `question_index` INT NOT NULL DEFAULT 0,
  `question_text` TEXT NOT NULL,
  `option_a` VARCHAR(255) NOT NULL,
  `option_b` VARCHAR(255) NOT NULL,
  `option_c` VARCHAR(255) NOT NULL,
  `option_d` VARCHAR(255) NOT NULL,
  `correct_option` CHAR(1) NOT NULL,
  `explanation` TEXT NULL,
  `subject` VARCHAR(100) NOT NULL DEFAULT 'General',
  `topic` VARCHAR(100) NOT NULL DEFAULT 'General',
  `difficulty` ENUM('easy', 'medium', 'hard') NOT NULL DEFAULT 'medium',
  `student_answer` CHAR(1) NULL,
  `is_correct` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`session_id`) REFERENCES `ai_practice_sessions`(`id`) ON DELETE CASCADE,
  INDEX `idx_session_id` (`session_id`),
  INDEX `idx_student_practice` (`student_id`, `topic`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
