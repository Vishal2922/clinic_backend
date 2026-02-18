<?php

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database Class: Merged Singleton Pattern with advanced PDO wrapper.
 * Conflict resolved between manual connection and advanced helper methods.
 */
class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;

    // Database connection settings
    private string $host = "127.0.0.1";
    private string $port = "3308";           
    private string $db_name = "clinic_db";
    private string $username = "root";
    private string $password = ""; 

    /**
     * Private constructor to prevent multiple instances.
     * Priority: $config array > Class Properties.
     */
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
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json');
            }
            
            echo json_encode([
                "status" => "error",
                "message" => "Database Connection Failed. Please check server logs."
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
            // Check for a config file if no config is passed
            if (empty($config) && defined('BASE_PATH')) {
                $configFile = BASE_PATH . '/config/database.php';
                $config = file_exists($configFile) ? require $configFile : [];
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Get the raw PDO connection.
     * Useful for legacy code or specific PDO requirements.
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Execute a query with prepared statements (SQL Injection Protection!)
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch single row helper
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Fetch all rows helper
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

    // Prevent cloning and unserializing to maintain Singleton integrity
    private function __clone() {}
    public function __wakeup() { 
        throw new RuntimeException("Cannot unserialize singleton"); 
    }
}