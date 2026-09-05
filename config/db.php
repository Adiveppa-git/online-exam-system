<?php
/**
 * Database Connection Module
 * Reads database configuration from environment variables with local defaults.
 */

$host = getenv('DB_HOST') ?: "127.0.0.1";
$user = getenv('DB_USER') ?: "root";
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";
$db   = getenv('DB_NAME') ?: "online_exam_system";
$port = getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3306;

$conn = @mysqli_connect($host, $user, $pass, $db, $port);

if (!$conn) {
    // Try fallback to localhost if 127.0.0.1 failed
    $conn = @mysqli_connect("localhost", $user, $pass, $db, $port);
}

if (!$conn) {
    http_response_code(500);
    die("Database Connection Error: Unable to connect to MySQL database service. Check DB_HOST and DB_PORT settings.");
}
?>
