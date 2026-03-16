<?php

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

class MasterDatabase
{
    private static ?MasterDatabase $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        try {
            $host    = env('MASTER_DB_HOST', env('DB_HOST', '127.0.0.1'));
            $port    = env('MASTER_DB_PORT', env('DB_PORT', '3306'));
            $dbName  = env('MASTER_DB_DATABASE', 'clinic_master_db');
            $user    = env('MASTER_DB_USERNAME', env('DB_USERNAME', 'root'));
            $pass    = env('MASTER_DB_PASSWORD', env('DB_PASSWORD', ''));
            $charset = 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";

            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('[MasterDB] Connection failed: ' . $e->getMessage());
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: application/json');
            }
            echo json_encode(['status' => 'error', 'message' => 'Master database connection failed.']);
            exit();
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO { return $this->pdo; }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $paramKey = is_int($key) ? $key + 1 : ':' . ltrim($key, ':');
            if (is_int($value))        $stmt->bindValue($paramKey, $value, PDO::PARAM_INT);
            elseif (is_null($value))   $stmt->bindValue($paramKey, null, PDO::PARAM_NULL);
            else                       $stmt->bindValue($paramKey, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = [], int $col = 0)
    {
        return $this->query($sql, $params)->fetchColumn($col);
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool           { return $this->pdo->commit(); }
    public function rollBack(): bool         { return $this->pdo->rollBack(); }

    private function __clone() {}
    public function __wakeup() { throw new RuntimeException('Cannot unserialize singleton'); }
}