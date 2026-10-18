<?php
/**
 * Safe, reversible database cleanup for trailing artificial variant suffixes.
 * Targets ONLY trailing " (Variant <digits>)" markers.
 * Preserves question options, correct answers, explanations, internal numbers, and question meaning.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/text_helpers.php';

echo "========================================================\n";
echo "   Database Migration: Clean Trailing Variant Suffixes   \n";
echo "========================================================\n\n";

$pattern = '/\s*\([Vv]ariant\s+\d+\)\s*$/u';

// 1. Create a safe backup table if it doesn't already exist
$conn->query("
    CREATE TABLE IF NOT EXISTS `_backup_variant_questions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `source_table` VARCHAR(64) NOT NULL,
        `record_id` INT NOT NULL,
        `original_question` TEXT NOT NULL,
        `cleaned_question` TEXT NOT NULL,
        `cleaned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (`source_table`, `record_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$conn->begin_transaction();

try {
    // A. Clean questions table
    $stmtSelectQ = $conn->query("SELECT id, question FROM questions WHERE question LIKE '%Variant%'");
    $qUpdates = 0;
    $backupStmt = $conn->prepare("INSERT INTO `_backup_variant_questions` (source_table, record_id, original_question, cleaned_question) VALUES (?, ?, ?, ?)");
    $updateStmtQ = $conn->prepare("UPDATE questions SET question = ? WHERE id = ?");

    while ($row = $stmtSelectQ->fetch_assoc()) {
        $orig = $row['question'];
        if (preg_match($pattern, $orig)) {
            $cleaned = clean_question_title($orig);
            $tbl = 'questions';
            $qid = (int)$row['id'];

            // Backup original record
            $backupStmt->bind_param("siss", $tbl, $qid, $orig, $cleaned);
            $backupStmt->execute();

            // Update with clean question
            $updateStmtQ->bind_param("si", $cleaned, $qid);
            $updateStmtQ->execute();
            $qUpdates++;
        }
    }
    $updateStmtQ->close();

    // B. Clean ai_generated_questions table
    $stmtSelectAI = $conn->query("SELECT id, question FROM ai_generated_questions WHERE question LIKE '%Variant%'");
    $aiUpdates = 0;
    $updateStmtAI = $conn->prepare("UPDATE ai_generated_questions SET question = ? WHERE id = ?");

    while ($row = $stmtSelectAI->fetch_assoc()) {
        $orig = $row['question'];
        if (preg_match($pattern, $orig)) {
            $cleaned = clean_question_title($orig);
            $tbl = 'ai_generated_questions';
            $aiId = (int)$row['id'];

            // Backup original record
            $backupStmt->bind_param("siss", $tbl, $aiId, $orig, $cleaned);
            $backupStmt->execute();

            // Update with clean question
            $updateStmtAI->bind_param("si", $cleaned, $aiId);
            $updateStmtAI->execute();
            $aiUpdates++;
        }
    }
    $updateStmtAI->close();
    $backupStmt->close();

    $conn->commit();

    echo "Successfully updated:\n";
    echo " - questions table: {$qUpdates} rows cleaned\n";
    echo " - ai_generated_questions table: {$aiUpdates} rows cleaned\n";
    echo " - Original questions backed up to `_backup_variant_questions` table.\n\n";

} catch (Throwable $e) {
    $conn->rollback();
    echo "ERROR during cleanup migration: " . $e->getMessage() . "\n";
    exit(1);
}

// Verification: check if any trailing variant suffixes remain
$remainingQ = $conn->query("SELECT COUNT(*) FROM questions WHERE question LIKE '%Variant%'")->fetch_row()[0];
$remainingAI = $conn->query("SELECT COUNT(*) FROM ai_generated_questions WHERE question LIKE '%Variant%'")->fetch_row()[0];

echo "Verification Results:\n";
echo " - Remaining in questions: {$remainingQ}\n";
echo " - Remaining in ai_generated_questions: {$remainingAI}\n";
echo "========================================================\n";
