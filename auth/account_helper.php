<?php
/**
 * Dynamic Account Lookup & Password Reset Security Helper
 * 
 * Supports both unified `users` table (with role 'admin'|'student')
 * and distinct `admins`/`students` schema configurations.
 */

if (!defined('OTP_REQUEST_COOLDOWN_SECONDS')) {
    define('OTP_REQUEST_COOLDOWN_SECONDS', 60);
}
if (!defined('OTP_MAX_VERIFICATION_ATTEMPTS')) {
    define('OTP_MAX_VERIFICATION_ATTEMPTS', 5);
}

/**
 * Retrieves all table names in the current database schema.
 *
 * @param mysqli|object $conn
 * @return array List of lowercase table names
 */
function getDatabaseTables($conn): array {
    static $cachedTables = null;
    if ($cachedTables !== null) {
        return $cachedTables;
    }

    $tables = [];
    $res = $conn->query("SHOW TABLES");
    if ($res) {
        while ($row = $res->fetch_row()) {
            $tables[] = strtolower($row[0]);
        }
    } else {
        $res2 = $conn->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() OR table_schema = 'public'");
        if ($res2) {
            while ($row = $res2->fetch_row()) {
                $tables[] = strtolower($row[0]);
            }
        }
    }
    $cachedTables = $tables;
    return $tables;
}

/**
 * Inspects columns of a given table.
 *
 * @param mysqli|object $conn
 * @param string $tableName
 * @return array Map of lowercase column name => actual column name
 */
function getTableColumns($conn, string $tableName): array {
    static $cache = [];
    $tLower = strtolower($tableName);
    if (isset($cache[$tLower])) {
        return $cache[$tLower];
    }

    $cols = [];
    $res = $conn->query("DESCRIBE `{$tableName}`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (isset($row['Field'])) {
                $cols[strtolower($row['Field'])] = $row['Field'];
            }
        }
    } else {
        $res2 = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_name = '{$tableName}'");
        if ($res2) {
            while ($row = $res2->fetch_assoc()) {
                $c = $row['column_name'] ?? ($row['COLUMN_NAME'] ?? null);
                if ($c) {
                    $cols[strtolower($c)] = $c;
                }
            }
        }
    }
    $cache[$tLower] = $cols;
    return $cols;
}

/**
 * Dynamically looks up an account by registered email address across
 * unified users table or separate admin/student tables.
 *
 * @param mysqli|object $conn
 * @param string $email
 * @return array|null Account metadata array or null if not found
 */
function findAccountForPasswordReset($conn, string $email): ?array {
    $cleanEmail = trim(strtolower($email));
    if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $tables = getDatabaseTables($conn);

    // 1. Unified users table (primary schema for Online Examination System)
    if (in_array('users', $tables, true)) {
        $cols = getTableColumns($conn, 'users');
        $emailCol = $cols['email'] ?? null;
        if ($emailCol) {
            $stmt = $conn->prepare("SELECT * FROM users WHERE LOWER(TRIM({$emailCol})) = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $cleanEmail);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $row = $res->fetch_assoc()) {
                    $rawRole = strtolower(trim($row['role'] ?? 'student'));
                    $role = ($rawRole === 'admin') ? 'admin' : 'student';
                    return [
                        'found' => true,
                        'table' => 'users',
                        'id' => (int)$row['id'],
                        'name' => $row['name'] ?? '',
                        'email' => trim($row[$emailCol]), // Canonical registered email
                        'role' => $role,
                        'id_col' => $cols['id'] ?? 'id',
                        'email_col' => $emailCol,
                        'otp_col' => $cols['reset_otp'] ?? ($cols['otp'] ?? 'reset_otp'),
                        'expiry_col' => $cols['otp_expiry'] ?? ($cols['token_expiry'] ?? 'otp_expiry'),
                        'pass_col' => $cols['password'] ?? 'password'
                    ];
                }
            }
        }
    }

    // 2. Standalone admin tables (admins, admin, tbl_admin)
    $adminTables = ['admins', 'admin', 'tbl_admin'];
    foreach ($adminTables as $at) {
        if (in_array($at, $tables, true)) {
            $cols = getTableColumns($conn, $at);
            $emailCol = $cols['email'] ?? ($cols['admin_email'] ?? null);
            if ($emailCol) {
                $stmt = $conn->prepare("SELECT * FROM `{$at}` WHERE LOWER(TRIM({$emailCol})) = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("s", $cleanEmail);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $row = $res->fetch_assoc()) {
                        return [
                            'found' => true,
                            'table' => $at,
                            'id' => (int)($row['id'] ?? $row['admin_id'] ?? 0),
                            'name' => $row['name'] ?? 'Administrator',
                            'email' => trim($row[$emailCol]),
                            'role' => 'admin',
                            'id_col' => $cols['id'] ?? ($cols['admin_id'] ?? 'id'),
                            'email_col' => $emailCol,
                            'otp_col' => $cols['reset_otp'] ?? ($cols['otp'] ?? 'reset_otp'),
                            'expiry_col' => $cols['otp_expiry'] ?? 'otp_expiry',
                            'pass_col' => $cols['password'] ?? 'password'
                        ];
                    }
                }
            }
        }
    }

    // 3. Standalone student tables (students, student, tbl_students)
    $studentTables = ['students', 'student', 'tbl_students'];
    foreach ($studentTables as $st) {
        if (in_array($st, $tables, true)) {
            $cols = getTableColumns($conn, $st);
            $emailCol = $cols['email'] ?? ($cols['student_email'] ?? null);
            if ($emailCol) {
                $stmt = $conn->prepare("SELECT * FROM `{$st}` WHERE LOWER(TRIM({$emailCol})) = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("s", $cleanEmail);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $row = $res->fetch_assoc()) {
                        return [
                            'found' => true,
                            'table' => $st,
                            'id' => (int)($row['id'] ?? $row['student_id'] ?? 0),
                            'name' => $row['name'] ?? 'Student',
                            'email' => trim($row[$emailCol]),
                            'role' => 'student',
                            'id_col' => $cols['id'] ?? ($cols['student_id'] ?? 'id'),
                            'email_col' => $emailCol,
                            'otp_col' => $cols['reset_otp'] ?? ($cols['otp'] ?? 'reset_otp'),
                            'expiry_col' => $cols['otp_expiry'] ?? 'otp_expiry',
                            'pass_col' => $cols['password'] ?? 'password'
                        ];
                    }
                }
            }
        }
    }

    return null;
}

/**
 * Saves a generated OTP and expiry timestamp for the specified account.
 *
 * @param mysqli|object $conn
 * @param array $account
 * @param string $otp
 * @param string $expiry
 * @return bool
 */
function saveAccountResetOtp($conn, array $account, string $otp, string $expiry): bool {
    $table = $account['table'];
    $otpCol = $account['otp_col'];
    $expiryCol = $account['expiry_col'];
    $idCol = $account['id_col'];
    $id = (int)$account['id'];

    $stmt = $conn->prepare("UPDATE `{$table}` SET `{$otpCol}`=?, `{$expiryCol}`=? WHERE `{$idCol}`=?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ssi", $otp, $expiry, $id);
    return $stmt->execute();
}

/**
 * Retrieves the currently saved OTP and expiry timestamp for the specified account.
 *
 * @param mysqli|object $conn
 * @param array $account
 * @return array|null ['reset_otp' => string|null, 'otp_expiry' => string|null]
 */
function getAccountResetOtp($conn, array $account): ?array {
    $table = $account['table'];
    $otpCol = $account['otp_col'];
    $expiryCol = $account['expiry_col'];
    $idCol = $account['id_col'];
    $id = (int)$account['id'];

    $stmt = $conn->prepare("SELECT `{$otpCol}` AS reset_otp, `{$expiryCol}` AS otp_expiry FROM `{$table}` WHERE `{$idCol}`=?");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res ? $res->fetch_assoc() : null;
}

/**
 * Clears the reset OTP and expiry (single-use invalidation / attempt limit lockout).
 *
 * @param mysqli|object $conn
 * @param array $account
 * @return bool
 */
function clearAccountResetOtp($conn, array $account): bool {
    $table = $account['table'];
    $otpCol = $account['otp_col'];
    $expiryCol = $account['expiry_col'];
    $idCol = $account['id_col'];
    $id = (int)$account['id'];

    $stmt = $conn->prepare("UPDATE `{$table}` SET `{$otpCol}`=NULL, `{$expiryCol}`=NULL WHERE `{$idCol}`=?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $id);
    return $stmt->execute();
}

/**
 * Updates the account's password with the hashed password and clears OTP.
 *
 * @param mysqli|object $conn
 * @param array $account
 * @param string $hashedPassword
 * @return bool
 */
function resetAccountPassword($conn, array $account, string $hashedPassword): bool {
    $table = $account['table'];
    $passCol = $account['pass_col'];
    $otpCol = $account['otp_col'];
    $expiryCol = $account['expiry_col'];
    $idCol = $account['id_col'];
    $id = (int)$account['id'];

    $stmt = $conn->prepare("UPDATE `{$table}` SET `{$passCol}`=?, `{$otpCol}`=NULL, `{$expiryCol}`=NULL WHERE `{$idCol}`=?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("si", $hashedPassword, $id);
    return $stmt->execute();
}
