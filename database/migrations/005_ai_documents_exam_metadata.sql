-- Migration 005: Add exam_id, source_question_ids, and is_generated to ai_documents
ALTER TABLE `ai_documents` ADD COLUMN `exam_id` INT NULL DEFAULT NULL;
ALTER TABLE `ai_documents` ADD COLUMN `source_question_ids` TEXT NULL DEFAULT NULL;
ALTER TABLE `ai_documents` ADD COLUMN `is_generated` TINYINT(1) NOT NULL DEFAULT 0;
