<?php
/**
 * Safe Data Migration Tool: MySQL / MariaDB -> Supabase PostgreSQL
 * Reads local MySQL data and populates target PostgreSQL database preserving PKs, FKs, and sequences.
 * Does NOT modify local MySQL data.
 */

require_once __DIR__ . '/../config/env_loader.php';

// 1. Connect to Local MySQL (Source)
$localHost = getenv('MYSQL_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1');
$localUser = getenv('MYSQL_USER') ?: (getenv('DB_USER') ?: 'root');
$localPass = getenv('MYSQL_PASS') !== false ? getenv('MYSQL_PASS') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
$localDb   = getenv('MYSQL_DB')   ?: (getenv('DB_NAME') ?: 'online_exam_system');
$localPort = (int)(getenv('MYSQL_PORT') ?: 3306);

$mysqlConn = @mysqli_connect($localHost, $localUser, $localPass, $localDb, $localPort);
if (!$mysqlConn) {
    die("Error connecting to source MySQL database at {$localHost}:{$localPort}: " . mysqli_connect_error() . "\n");
}
echo "[Source] Successfully connected to local MySQL database ($localDb)\n";

// 2. Connect to Target PostgreSQL (Destination)
$pgHost = getenv('PG_HOST') ?: getenv('DB_HOST');
$pgUser = getenv('PG_USER') ?: getenv('DB_USER');
$pgPass = getenv('PG_PASS') !== false ? getenv('PG_PASS') : getenv('DB_PASS');
$pgDb   = getenv('PG_NAME') ?: (getenv('DB_NAME') ?: 'postgres');
$pgPort = (int)(getenv('PG_PORT') ?: 5432);

if (empty($pgHost) || empty($pgUser)) {
    die("Target PostgreSQL environment variables (PG_HOST/DB_HOST, PG_USER/DB_USER, PG_PASS/DB_PASS) must be configured.\n");
}

try {
    $pgPdo = new PDO("pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb}", $pgUser, $pgPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "[Target] Successfully connected to target PostgreSQL database ($pgDb at $pgHost)\n\n";
} catch (PDOException $e) {
    die("Error connecting to target PostgreSQL database: " . $e->getMessage() . "\n");
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

try {
    $pgPdo->beginTransaction();

    foreach ($tables as $table) {
        echo "Migrating table '{$table}'... ";
        
        $res = mysqli_query($mysqlConn, "SELECT * FROM `{$table}`");
        if (!$res) {
            echo "SKIPPED (Source table does not exist or empty)\n";
            continue;
        }

        $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
        $count = count($rows);

        if ($count === 0) {
            echo "PASSED (0 rows)\n";
            continue;
        }

        $columns = array_keys($rows[0]);
        $colNamesStr = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
        $placeholdersStr = implode(', ', array_fill(0, count($columns), '?'));

        $sql = "INSERT INTO \"{$table}\" ({$colNamesStr}) OVERRIDING SYSTEM VALUE VALUES ({$placeholdersStr}) ON CONFLICT DO NOTHING";
        $stmt = $pgPdo->prepare($sql);

        $insertedCount = 0;
        foreach ($rows as $row) {
            $params = [];
            foreach ($columns as $col) {
                $val = $row[$col];
                // Convert boolean columns from integer to boolean
                if (($table === 'exams' && $col === 'ai_generated') ||
                    ($table === 'exam_violations' && $col === 'auto_submitted') ||
                    ($table === 'ai_practice_answers' && $col === 'is_correct')) {
                    $val = ($val == 1 || $val === '1' || $val === true) ? 'true' : 'false';
                }
                $params[] = $val;
            }
            $stmt->execute($params);
            $insertedCount++;
        }

        // Synchronize identity sequence in PostgreSQL so future inserts generate correct IDs
        $maxIdStmt = $pgPdo->query("SELECT MAX(id) AS max_id FROM \"{$table}\"");
        $maxIdRow = $maxIdStmt->fetch(PDO::FETCH_ASSOC);
        $maxId = $maxIdRow['max_id'] ?? null;

        if ($maxId !== null) {
            $pgPdo->exec("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), {$maxId}, true)");
        }

        echo "SUCCESS ({$insertedCount} / {$count} rows migrated, sequence updated)\n";
    }

    $pgPdo->commit();
    echo "\nData migration completed successfully!\n";

} catch (Exception $e) {
    if ($pgPdo->inTransaction()) {
        $pgPdo->rollBack();
    }
    die("\nMigration failed! Transaction rolled back. Error: " . $e->getMessage() . "\n");
}
