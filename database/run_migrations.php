<?php
require_once __DIR__ . '/../config/db.php';

echo "Running Database Migrations...\n";

$migrationsDir = __DIR__ . '/migrations';
$files = glob($migrationsDir . '/*.sql');

sort($files);

foreach ($files as $file) {
    echo "Applying: " . basename($file) . "... ";
    $sql = file_get_contents($file);
    
    // Split statements by semicolon
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $success = true;
    foreach ($statements as $stmt) {
        if (empty($stmt)) continue;
        if (!$conn->query($stmt)) {
            echo "\nERROR in statement: " . $conn->error . "\nSQL: " . $stmt . "\n";
            $success = false;
            break;
        }
    }
    
    if ($success) {
        echo "SUCCESS\n";
    }
}
echo "Migration process finished.\n";
