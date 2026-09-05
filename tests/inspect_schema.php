<?php
require_once __DIR__ . '/../config/db.php';
$res = $conn->query("SHOW TABLES");
while($row = $res->fetch_row()) {
    $table = $row[0];
    echo "=== Table: {$table} ===\n";
    $cols = $conn->query("DESCRIBE {$table}");
    while($c = $cols->fetch_assoc()) {
        echo "  - {$c['Field']} ({$c['Type']})\n";
    }
}
