<?php
namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;

    private string $host = "127.0.0.1";
    private string $port = "3306";
    private string $db_name = "clinic_db";
    private string $username = "root";
    private string $password = "";

    private function __construct(array $config = [])
    {
        try {
            $h = $config['host'] ?? $this->host;
            $p = $config['port'] ?? $this->port;
            $db = $config['database'] ?? $this->db_name;
            $user = $config['username'] ?? $this->username;
            $pass = $config['password'] ?? $this->password;
            $charset = $config['charset'] ?? 'utf8mb4';

            $dsn = "mysql:host={$h};port={$p};dbname={$db};charset={$charset}";

            $this->pdo = new PDO(
                $dsn,
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json');
            }
            echo json_encode([
                "status"  => "error",
                "message" => "Database Connection Failed. Please check server logs."
            ]);
            exit();
        }
    }

    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            if (empty($config) && defined('BASE_PATH')) {
                $configFile = BASE_PATH . '/config/database.php';
                $config = file_exists($configFile) ? require $configFile : [];
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Execute a query with prepared statements.
     * FIX: Properly binds LIMIT/OFFSET as integers when present.
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $paramKey = is_int($key) ? $key + 1 : ':' . ltrim($key, ':');

            if (in_array($key, ['limit', 'offset', 'LIMIT', 'OFFSET']) || 
                in_array(ltrim($key, ':'), ['limit', 'offset'])) {
                $stmt->bindValue($paramKey, (int)$value, PDO::PARAM_INT);
            } elseif (is_int($value)) {
                $stmt->bindValue($paramKey, $value, PDO::PARAM_INT);
            } elseif (is_null($value)) {
                $stmt->bindValue($paramKey, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($paramKey, $value, PDO::PARAM_STR);
            }
        }

        $stmt->execute();
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * FIX: Added fetchColumn() method that was missing.
     * Used by invoice number generation and other count queries.
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0)
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn($column);
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollBack(): bool { return $this->pdo->rollBack(); }

    private function __clone() {}
    public function __wakeup()
    {
        throw new RuntimeException("Cannot unserialize singleton");
    }
}