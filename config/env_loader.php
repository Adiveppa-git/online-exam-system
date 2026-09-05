<?php
/**
 * Environment Variable Loader
 * Parses root .env file if present and populates getenv(), $_ENV, and $_SERVER.
 */

function loadEnv($filePath = null)
{
    if ($filePath === null) {
        $filePath = dirname(__DIR__) . '/.env';
    }

    if (!file_exists($filePath) || !is_readable($filePath)) {
        return false;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return false;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        if (strpos($line, '=') === false) {
            continue;
        }

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Strip surrounding quotes
        if (
            (strlen($value) >= 2) &&
            ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
             (substr($value, 0, 1) === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        // Only set if not already present or if existing value is empty string
        $current = getenv($key);
        if ($current === false || trim($current) === '') {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    return true;
}

// Auto-load on include
loadEnv();
