-- Migration 002: AI RAG Course Materials & Chunks Metadata
CREATE TABLE IF NOT EXISTS `ai_documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `filename` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT NOT NULL DEFAULT 0,
  `subject` VARCHAR(100) NOT NULL DEFAULT 'General',
  `topic` VARCHAR(100) NOT NULL DEFAULT 'General',
  `total_pages` INT NOT NULL DEFAULT 1,
  `total_chunks` INT NOT NULL DEFAULT 0,
  `status` ENUM('pending', 'ingested', 'failed') NOT NULL DEFAULT 'pending',
  `error_message` TEXT NULL,
  `uploaded_by` INT NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_document_chunks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT NOT NULL,
  `chunk_index` INT NOT NULL,
  `page_number` INT NOT NULL DEFAULT 1,
  `chunk_text` TEXT NOT NULL,
  `chunk_hash` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`document_id`) REFERENCES `ai_documents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
