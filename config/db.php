<?php
require_once __DIR__ . '/env_loader.php';

/**
 * Database Connection & Abstraction Module
 * Supports dual drivers: 'mysql' (default for local XAMPP) and 'pgsql' (cloud Supabase PostgreSQL).
 */

$appEnv   = strtolower(getenv('APP_ENV') ?: 'development');
$dbDriver = strtolower(trim(getenv('DB_DRIVER') ?: 'mysql'));

if (in_array($dbDriver, ['pgsql', 'postgres', 'postgresql'], true)) {
    $envHost = getenv('PG_HOST') ?: getenv('DB_HOST');
    $host    = ($envHost !== false && trim($envHost) !== '') ? trim($envHost) : '127.0.0.1';

    $envUser = getenv('PG_USER') !== false ? getenv('PG_USER') : getenv('DB_USER');
    $user    = ($envUser !== false && trim($envUser) !== '') ? trim($envUser) : 'postgres';

    $pass    = getenv('PG_PASS') !== false ? getenv('PG_PASS') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

    $envDb   = getenv('PG_NAME') ?: getenv('DB_NAME');
    $db      = ($envDb !== false && trim($envDb) !== '') ? trim($envDb) : 'postgres';

    $envPort = getenv('PG_PORT') ?: getenv('DB_PORT');
    $port    = $envPort ? (int)$envPort : 5432;

    class PgSqlResultAdapter {
        private array $rows;
        private int $pointer = 0;
        public int $num_rows = 0;

        public function __construct(array $rows) {
            $this->rows = array_values($rows);
            $this->num_rows = count($this->rows);
        }

        public function fetch_assoc(): ?array {
            if ($this->pointer < $this->num_rows) {
                return $this->rows[$this->pointer++];
            }
            return null;
        }

        public function fetch_row(): ?array {
            if ($this->pointer < $this->num_rows) {
                $row = $this->rows[$this->pointer++];
                return array_values($row);
            }
            return null;
        }

        public function fetch_array(int $mode = 3): ?array {
            if ($this->pointer < $this->num_rows) {
                $row = $this->rows[$this->pointer++];
                if ($mode === 1) { // MYSQLI_ASSOC
                    return $row;
                } elseif ($mode === 2) { // MYSQLI_NUM
                    return array_values($row);
                }
                return array_merge(array_values($row), $row);
            }
            return null;
        }

        public function fetch_all(int $mode = 1): array {
            return $this->rows;
        }
    }

    class PgSqlStmtAdapter {
        private PDO $pdo;
        private string $sql;
        private ?PDOStatement $stmt = null;
        private array $boundVars = [];
        private array $resultVars = [];
        public int $insert_id = 0;
        public int $affected_rows = 0;
        public string $error = "";

        public function __construct(PDO $pdo, string $sql) {
            $this->pdo = $pdo;
            $this->sql = $sql;
        }

        public function bind_param(string $types, &...$params): bool {
            $this->boundVars = &$params;
            return true;
        }

        public function execute(?array $params = null): bool {
            try {
                $execParams = $params !== null ? $params : array_map(function($var) {
                    return is_bool($var) ? ($var ? 1 : 0) : $var;
                }, $this->boundVars);

                $sqlToRun = str_replace('`', '', $this->sql);
                $sqlToRun = preg_replace('/IFNULL\(/i', 'COALESCE(', $sqlToRun);

                $isInsert = (bool)preg_match('/^\s*INSERT\s+INTO\s+([`"\w]+)/i', $sqlToRun, $matches);
                if ($isInsert && !preg_match('/RETURNING\s+/i', $sqlToRun)) {
                    $sqlToRun .= ' RETURNING id';
                }

                $this->stmt = $this->pdo->prepare($sqlToRun);
                $this->stmt->execute($execParams);

                $this->affected_rows = $this->stmt->rowCount();
                $this->error = "";

                if ($isInsert) {
                    try {
                        $returned = $this->stmt->fetch(PDO::FETCH_ASSOC);
                        if ($returned && isset($returned['id'])) {
                            $this->insert_id = (int)$returned['id'];
                        } else {
                            $this->insert_id = (int)$this->pdo->lastInsertId();
                        }
                    } catch (Throwable $e) {
                        $this->insert_id = (int)$this->pdo->lastInsertId();
                    }
                }

                return true;
            } catch (PDOException $e) {
                $this->error = $e->getMessage();
                error_log("PgSqlStmtAdapter execute error: " . $e->getMessage() . " | SQL: " . $this->sql);
                return false;
            }
        }

        public function get_result(): PgSqlResultAdapter {
            if (!$this->stmt) {
                return new PgSqlResultAdapter([]);
            }
            try {
                $rows = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
                return new PgSqlResultAdapter($rows ?: []);
            } catch (Throwable $e) {
                error_log("PgSqlStmtAdapter get_result error: " . $e->getMessage());
                return new PgSqlResultAdapter([]);
            }
        }

        public function bind_result(&...$vars): bool {
            $this->resultVars = &$vars;
            return true;
        }

        public function fetch(): ?bool {
            if (!$this->stmt) {
                return false;
            }
            $row = $this->stmt->fetch(PDO::FETCH_NUM);
            if ($row === false) {
                return false;
            }
            foreach ($this->resultVars as $index => &$var) {
                $var = $row[$index] ?? null;
            }
            return true;
        }

        public function close(): bool {
            $this->stmt = null;
            return true;
        }
    }

    class PgSqlDbAdapter {
        private PDO $pdo;
        public int $insert_id = 0;
        public int $affected_rows = 0;
        public string $error = "";

        public function __construct(PDO $pdo) {
            $this->pdo = $pdo;
        }

        public function prepare(string $sql): PgSqlStmtAdapter {
            return new PgSqlStmtAdapter($this->pdo, $sql);
        }

        public function query(string $sql): PgSqlResultAdapter|bool {
            try {
                $sqlToRun = str_replace('`', '', $sql);
                $sqlToRun = preg_replace('/IFNULL\(/i', 'COALESCE(', $sqlToRun);

                if (preg_match('/^\s*SHOW\s+TABLES\s+LIKE\s+[\'"]([^\'"]+)[\'"]/i', $sqlToRun, $matches)) {
                    $tbl = $matches[1];
                    $stmt = $this->pdo->prepare("SELECT tablename AS \"Tables_in_dbname\" FROM pg_tables WHERE schemaname = 'public' AND tablename = ?");
                    $stmt->execute([$tbl]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    return new PgSqlResultAdapter($rows);
                } elseif (preg_match('/^\s*SHOW\s+TABLES/i', $sqlToRun)) {
                    $stmt = $this->pdo->query("SELECT tablename AS \"Tables_in_dbname\" FROM pg_tables WHERE schemaname = 'public'");
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    return new PgSqlResultAdapter($rows);
                }

                $isInsert = (bool)preg_match('/^\s*INSERT\s+INTO\s+/i', $sqlToRun);
                if ($isInsert && !preg_match('/RETURNING\s+/i', $sqlToRun)) {
                    $sqlToRun .= ' RETURNING id';
                }

                $stmt = $this->pdo->query($sqlToRun);
                if (!$stmt) {
                    $this->error = "Query failed";
                    return false;
                }

                $this->affected_rows = $stmt->rowCount();
                if ($isInsert) {
                    try {
                        $returned = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($returned && isset($returned['id'])) {
                            $this->insert_id = (int)$returned['id'];
                        } else {
                            $this->insert_id = (int)$this->pdo->lastInsertId();
                        }
                    } catch (Throwable $e) {
                        $this->insert_id = (int)$this->pdo->lastInsertId();
                    }
                }

                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return new PgSqlResultAdapter($rows ?: []);
            } catch (PDOException $e) {
                $this->error = $e->getMessage();
                error_log("PgSqlDbAdapter query error: " . $e->getMessage() . " | SQL: " . $sql);
                return false;
            }
        }

        public function begin_transaction(): bool {
            return $this->pdo->beginTransaction();
        }

        public function commit(): bool {
            return $this->pdo->commit();
        }

        public function rollback(): bool {
            return $this->pdo->rollBack();
        }

        public function real_escape_string(string $str): string {
            $quoted = $this->pdo->quote($str);
            return substr($quoted, 1, -1);
        }

        public function escape_string(string $str): string {
            return $this->real_escape_string($str);
        }
    }

    try {
        $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
        $sslMode = getenv('PG_SSLMODE') ?: getenv('DB_SSLMODE');
        if ($sslMode !== false && trim($sslMode) !== '') {
            $dsn .= ";sslmode=" . trim($sslMode);
        }
        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $pdo = new PDO($dsn, $user, $pass, $pdoOptions);
        $conn = new PgSqlDbAdapter($pdo);
    } catch (PDOException $e) {
        http_response_code(500);
        die("Database Connection Error (PostgreSQL): " . $e->getMessage());
    }

} else {
    // Development local default (MySQL / MariaDB via MySQLi)
    $envHost = getenv('DB_HOST');
    $host = ($envHost !== false && trim($envHost) !== '') ? trim($envHost) : "127.0.0.1";
    $user = getenv('DB_USER') !== false ? getenv('DB_USER') : "root";
    $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";
    $db   = getenv('DB_NAME') !== false ? getenv('DB_NAME') : "online_exam_system";
    $port = getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3306;

    $conn = @mysqli_connect($host, $user, $pass, $db, $port);

    if (!$conn && ($host === "127.0.0.1" || $host === "localhost")) {
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
}
?>
