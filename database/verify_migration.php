<?php
/**
 * Migration Verification Tool
 * Compares row counts between MySQL source and PostgreSQL destination.
 */

require_once __DIR__ . '/../config/env_loader.php';

// 1. Connect MySQL
$localHost = getenv('MYSQL_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1');
$localUser = getenv('MYSQL_USER') ?: (getenv('DB_USER') ?: 'root');
$localPass = getenv('MYSQL_PASS') !== false ? getenv('MYSQL_PASS') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
$localDb   = getenv('MYSQL_DB')   ?: (getenv('DB_NAME') ?: 'online_exam_system');
$localPort = (int)(getenv('MYSQL_PORT') ?: 3306);

$mysqlConn = @mysqli_connect($localHost, $localUser, $localPass, $localDb, $localPort);

// 2. Connect PostgreSQL
$pgHost = getenv('PG_HOST') ?: getenv('DB_HOST');
$pgUser = getenv('PG_USER') ?: getenv('DB_USER');
$pgPass = getenv('PG_PASS') !== false ? getenv('PG_PASS') : getenv('DB_PASS');
$pgDb   = getenv('PG_NAME') ?: (getenv('DB_NAME') ?: 'postgres');
$pgPort = (int)(getenv('PG_PORT') ?: 5432);

try {
    $pgPdo = new PDO("pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb}", $pgUser, $pgPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Throwable $e) {
    $pgPdo = null;
}

$tables = [
    'users',
    'exams',
    'questions',
    'results',
    'student_answers',
    'violations',
    'violation_report',
    'exam_violations',
    'ai_generation_requests',
    'ai_generated_questions',
    'ai_documents',
    'ai_document_chunks',
    'ai_practice_sessions',
    'ai_practice_answers'
];

echo str_repeat("=", 60) . "\n";
echo sprintf("%-25s | %-12s | %-12s | %-10s\n", "Table Name", "MySQL Count", "PG Count", "Status");
echo str_repeat("=", 60) . "\n";

foreach ($tables as $table) {
    $myCount = "N/A";
    if ($mysqlConn) {
        $res = mysqli_query($mysqlConn, "SELECT COUNT(*) AS c FROM `{$table}`");
        if ($res) {
            $myCount = (int)mysqli_fetch_assoc($res)['c'];
        }
    }

    $pgCount = "N/A";
    if ($pgPdo) {
        try {
            $stmt = $pgPdo->query("SELECT COUNT(*) AS c FROM \"{$table}\"");
            if ($stmt) {
                $pgCount = (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'];
            }
        } catch (Throwable $e) {
            $pgCount = "Error";
        }
    }

    $status = ($myCount === $pgCount && $myCount !== "N/A") ? "MATCH" : "MISMATCH / UNCHECKED";
    echo sprintf("%-25s | %-12s | %-12s | %-10s\n", $table, (string)$myCount, (string)$pgCount, $status);
}
echo str_repeat("=", 60) . "\n";
