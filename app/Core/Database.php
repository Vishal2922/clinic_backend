<?php

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database Class: Singleton Pattern merged with helper methods.
 * Conflict resolved between manual connection and advanced PDO wrapper.
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    /**
     * Private constructor to prevent multiple instances.
     * Uses config array for flexible connections.
     */
    private function __construct(array $config)
    {
        try {
            // Teammate's dynamic DSN logic
            $dsn = sprintf(
                '%s:host=%s;port=%d;dbname=%s;charset=%s',
                $config['driver'] ?? 'mysql',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? 3306,
                $config['database'] ?? 'clinic_db',
                $config['charset'] ?? 'utf8mb4'
            );

            $this->pdo = new PDO(
                $dsn,
                $config['username'] ?? 'root',
                $config['password'] ?? '',
                $config['options'] ?? [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            // Logs error and stops execution for security
            error_log('Database connection failed: ' . $e->getMessage());
            
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "Database Connection Failed. Check server logs."
            ]);
            exit();
        }
    }

    /**
     * Singleton Instance Getter
     */
    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            // If no config passed, try to load from default config file
            if (empty($config) && defined('BASE_PATH')) {
                $config = require BASE_PATH . '/config/database.php';
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Get the raw PDO connection
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Execute a query with prepared statements (Important for Security!)
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch single row
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Fetch all rows
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Insert and return last insert ID
     */
    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update/Delete and return affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    // Transaction Helpers
    public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollBack(): bool { return $this->pdo->rollBack(); }

    // Prevent cloning and unserializing
    private function __clone() {}
    public function __wakeup() { throw new RuntimeException("Cannot unserialize singleton"); }
}