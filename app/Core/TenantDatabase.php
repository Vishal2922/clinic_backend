<?php

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

class TenantDatabase
{
    private static array $instances = [];
    private PDO $pdo;
    private string $tenantCode;

    private function __construct(string $tenantCode, array $dbCredentials)
    {
        $this->tenantCode = $tenantCode;

        try {
            $host    = $dbCredentials['db_host']     ?? env('DB_HOST', '127.0.0.1');
            $port    = $dbCredentials['db_port']     ?? env('DB_PORT', '3306');
            $dbName  = $dbCredentials['db_name']     ?? "clinic_tenant_{$tenantCode}_db";
            $user    = $dbCredentials['db_username'] ?? env('DB_USERNAME', 'root');
            $pass    = $dbCredentials['db_password'] ?? env('DB_PASSWORD', '');
            $charset = 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";

            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => true, // Required to reuse named parameters multiple times
            ]);
        } catch (PDOException $e) {
            error_log("[TenantDB:{$tenantCode}] Connection failed: " . $e->getMessage());
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: application/json');
            }
            echo json_encode(['status' => 'error', 'message' => 'Tenant database unavailable. Please contact support.']);
            exit();
        }
    }

    public static function getInstance(string $tenantCode, array $credentials = []): self
    {
        if (!isset(self::$instances[$tenantCode])) {
            if (empty($credentials)) {
                $masterDb = MasterDatabase::getInstance();
                $tenant   = $masterDb->fetch(
                    'SELECT db_host, db_port, db_name, db_username, db_password FROM tenants WHERE tenant_code = :code AND status = "active"',
                    ['code' => $tenantCode]
                );
                if (!$tenant) {
                    throw new RuntimeException("Tenant '{$tenantCode}' not found or inactive.");
                }
                $credentials = $tenant;
            }
            self::$instances[$tenantCode] = new self($tenantCode, $credentials);
        }
        return self::$instances[$tenantCode];
    }

    public function getConnection(): PDO     { return $this->pdo; }
    public function getTenantCode(): string  { return $this->tenantCode; }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $paramKey = is_int($key) ? $key + 1 : ':' . ltrim($key, ':');
            if (in_array(ltrim((string)$key, ':'), ['limit', 'offset'])) {
                $stmt->bindValue($paramKey, (int)$value, PDO::PARAM_INT);
            } elseif (is_int($value))      $stmt->bindValue($paramKey, $value, PDO::PARAM_INT);
            elseif (is_null($value))       $stmt->bindValue($paramKey, null, PDO::PARAM_NULL);
            else                           $stmt->bindValue($paramKey, $value, PDO::PARAM_STR);
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