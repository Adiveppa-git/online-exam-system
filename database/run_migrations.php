<?php
require_once __DIR__ . '/../config/db.php';

echo "Running Database Migrations...\n";

$migrationsDir = __DIR__ . '/migrations';
$files = glob($migrationsDir . '/*.sql');

sort($files);

$overallSuccess = true;

foreach ($files as $file) {
    echo "Applying: " . basename($file) . "... ";
    $sql = file_get_contents($file);
    
    // Split statements by semicolon
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $fileSuccess = true;
    foreach ($statements as $stmt) {
        if (empty($stmt)) continue;
        try {
            if (!$conn->query($stmt)) {
                $err = $conn->error ?? 'Unknown error';
                // Ignore duplicate column or duplicate key warnings on re-runs
                if (str_contains($err, 'Duplicate') || str_contains($err, 'already exists')) {
                    continue;
                }
                echo "\nERROR in statement: " . $err . "\nSQL: " . $stmt . "\n";
                $fileSuccess = false;
                break;
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate') || str_contains($msg, 'already exists')) {
                continue;
            }
            echo "\nERROR in statement: " . $msg . "\nSQL: " . $stmt . "\n";
            $fileSuccess = false;
            break;
        }
    }
    
    if ($fileSuccess) {
        echo "SUCCESS\n";
    } else {
        $overallSuccess = false;
    }
}

echo "Migration process finished.\n";
if (!$overallSuccess) {
    exit(1);
}
