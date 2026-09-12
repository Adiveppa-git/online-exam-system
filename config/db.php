<?php
require_once __DIR__ . '/env_loader.php';

/**
 * Database Connection Module
 * Reads database configuration from environment variables with local defaults.
 * External/managed MySQL database is strictly environment-driven in production.
 */

$appEnv = strtolower(getenv('APP_ENV') ?: 'development');

$envHost = getenv('DB_HOST');
if ($envHost !== false && trim($envHost) !== '') {
    $host = trim($envHost);
} else {
    // Development local default
    $host = "127.0.0.1";
}

$user = getenv('DB_USER') !== false ? getenv('DB_USER') : "root";
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";
$db   = getenv('DB_NAME') !== false ? getenv('DB_NAME') : "online_exam_system";
$port = getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3306;

$conn = @mysqli_connect($host, $user, $pass, $db, $port);

if (!$conn && ($host === "127.0.0.1" || $host === "localhost")) {
    // Try fallback to localhost if local 127.0.0.1 failed
    $conn = @mysqli_connect("localhost", $user, $pass, $db, $port);
}

if (!$conn) {
    http_response_code(500);
    $errMsg = "Database Connection Error: Unable to connect to MySQL database service at {$host}:{$port}. ";
    if (empty($envHost) && in_array($appEnv, ['production', 'prod', 'staging'], true)) {
        $errMsg .= "Production DB_HOST is unconfigured. Please configure DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASS in your production environment settings.";
    } else {
        $errMsg .= "Check DB_HOST and DB_PORT environment settings.";
    }
    die($errMsg);
}
?>
