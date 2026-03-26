<?php

namespace App\Modules\SuperAdmin\Services;

use App\Core\MasterDatabase;
use PDO;
use RuntimeException;

class TenantProvisioningService
{
    private MasterDatabase $masterDb;
    private string $rootHost;
    private string $rootPort;
    private string $rootUser;
    private string $rootPassword;

    public function __construct()
    {
        $this->masterDb     = MasterDatabase::getInstance();
        $this->rootHost     = env('DB_HOST', '127.0.0.1');
        $this->rootPort     = env('DB_PORT', '3306');
        $this->rootUser     = env('DB_USERNAME', 'root');
        $this->rootPassword = env('DB_PASSWORD', '');
    }

    public function provision(array $tenantData, array $superAdminContext): array
    {
        $tenantCode = strtolower(preg_replace('/[^a-z0-9_]/', '_', $tenantData['tenant_code']));
        $dbName     = "clinic_tenant_{$tenantCode}_db";
        $dbUser     = "clinic_{$tenantCode}";
        $dbPassword = bin2hex(random_bytes(24));

        $existing = $this->masterDb->fetch('SELECT id FROM tenants WHERE tenant_code = :code', ['code' => $tenantCode]);
        if ($existing) {
            throw new RuntimeException("Tenant code '{$tenantCode}' already exists.");
        }

        $rootPdo = $this->getRootConnection();

        try {
            $this->createDatabase($rootPdo, $dbName);
            $this->createDatabaseUser($rootPdo, $dbUser, $dbPassword, $dbName);
            $this->runTenantSchema($rootPdo, $dbName);
            $this->seedTenantDefaults($rootPdo, $dbName);
            $adminInfo = $this->createInitialTenantAdmin($rootPdo, $dbName, $tenantData);
        } catch (\Exception $e) {
            try {
                $rootPdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
                $rootPdo->exec("DROP USER IF EXISTS '{$dbUser}'@'%'");
            } catch (\Exception $cleanupError) {
                error_log('[TenantProvision] Cleanup failed: ' . $cleanupError->getMessage());
            }
            throw new RuntimeException('Tenant provisioning failed: ' . $e->getMessage(), 0, $e);
        }

        $plan          = $tenantData['plan'] ?? 'basic';
        $subscriptionDays = $this->getPlanDays($plan);
        $expiresAt     = date('Y-m-d H:i:s', strtotime("+{$subscriptionDays} days"));

        $tenantId = $this->masterDb->insert(
            'INSERT INTO tenants (
                tenant_code, name, slug, email, phone, address, city, state, country,
                plan, status, subscription_starts_at, subscription_expires_at,
                db_host, db_port, db_name, db_username, db_password,
                max_users, max_patients, max_doctors,
                created_by_super_admin_id, created_at, updated_at
            ) VALUES (
                :code, :name, :slug, :email, :phone, :address, :city, :state, :country,
                :plan, "active", NOW(), :expires_at,
                :db_host, :db_port, :db_name, :db_user, :db_pass,
                :max_users, :max_patients, :max_doctors,
                :created_by, NOW(), NOW()
            )',
            [
                'code'         => $tenantCode,
                'name'         => $tenantData['name'],
                'slug'         => $tenantCode,
                'email'        => $tenantData['email'],
                'phone'        => $tenantData['phone']    ?? null,
                'address'      => $tenantData['address']  ?? null,
                'city'         => $tenantData['city']     ?? null,
                'state'        => $tenantData['state']    ?? null,
                'country'      => $tenantData['country']  ?? 'IN',
                'plan'         => $plan,
                'expires_at'   => $expiresAt,
                'db_host'      => $this->rootHost,
                'db_port'      => $this->rootPort,
                'db_name'      => $dbName,
                'db_user'      => $dbUser,
                'db_pass'      => $dbPassword,
                'max_users'    => $this->getPlanLimit($plan, 'users'),
                'max_patients' => $this->getPlanLimit($plan, 'patients'),
                'max_doctors'  => $this->getPlanLimit($plan, 'doctors'),
                'created_by'   => $superAdminContext['super_admin_id'],
            ]
        );

        $this->masterDb->insert(
            'INSERT INTO master_audit_logs (super_admin_id, action, resource_type, resource_id, details, ip_address, created_at)
             VALUES (:sa_id, "tenant_created", "tenant", :tenant_id, :details, :ip, NOW())',
            [
                'sa_id'     => $superAdminContext['super_admin_id'],
                'tenant_id' => $tenantId,
                'details'   => json_encode(['tenant_code' => $tenantCode, 'plan' => $plan, 'db_name' => $dbName]),
                'ip'        => $superAdminContext['ip'] ?? '0.0.0.0',
            ]
        );

        return [
            'tenant_id'            => $tenantId,
            'tenant_code'          => $tenantCode,
            'database'             => $dbName,
            'admin_email'          => $adminInfo['email'],
            'admin_password_plain' => $adminInfo['password_plain'],
            'expires_at'           => $expiresAt,
            'plan'                 => $plan,
        ];
    }

    public function suspendTenant(string $tenantCode, int $superAdminId, string $reason = ''): bool
    {
        $updated = $this->masterDb->execute(
            'UPDATE tenants SET status = "suspended", updated_at = NOW() WHERE tenant_code = :code',
            ['code' => $tenantCode]
        );
        $this->masterDb->insert(
            'INSERT INTO master_audit_logs (super_admin_id, action, resource_type, details, ip_address, created_at)
             VALUES (:sa_id, "tenant_suspended", "tenant", :details, "system", NOW())',
            ['sa_id' => $superAdminId, 'details' => json_encode(['tenant_code' => $tenantCode, 'reason' => $reason])]
        );
        return $updated > 0;
    }

    public function reactivateTenant(string $tenantCode, int $superAdminId): bool
    {
        $updated = $this->masterDb->execute(
            'UPDATE tenants SET status = "active", updated_at = NOW() WHERE tenant_code = :code',
            ['code' => $tenantCode]
        );
        $this->masterDb->insert(
            'INSERT INTO master_audit_logs (super_admin_id, action, resource_type, details, ip_address, created_at)
             VALUES (:sa_id, "tenant_reactivated", "tenant", :details, "system", NOW())',
            ['sa_id' => $superAdminId, 'details' => json_encode(['tenant_code' => $tenantCode])]
        );
        return $updated > 0;
    }

    public function changePlan(string $tenantCode, string $newPlan, int $superAdminId): array
    {
        $planDays  = $this->getPlanDays($newPlan);
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$planDays} days"));

        $this->masterDb->execute(
            'UPDATE tenants SET plan = :plan, max_users = :max_users, max_patients = :max_patients,
                max_doctors = :max_doctors, subscription_expires_at = :expires_at, updated_at = NOW()
             WHERE tenant_code = :code',
            [
                'plan'         => $newPlan,
                'max_users'    => $this->getPlanLimit($newPlan, 'users'),
                'max_patients' => $this->getPlanLimit($newPlan, 'patients'),
                'max_doctors'  => $this->getPlanLimit($newPlan, 'doctors'),
                'expires_at'   => $expiresAt,
                'code'         => $tenantCode,
            ]
        );

        $this->masterDb->insert(
            'INSERT INTO master_audit_logs (super_admin_id, action, resource_type, details, ip_address, created_at)
             VALUES (:sa_id, "tenant_plan_changed", "tenant", :details, "system", NOW())',
            ['sa_id' => $superAdminId, 'details' => json_encode(['tenant_code' => $tenantCode, 'new_plan' => $newPlan])]
        );

        return ['plan' => $newPlan, 'expires_at' => $expiresAt];
    }

    // ─── Private Helpers ─────────────────────────────────────────

    private function getRootConnection(): PDO
    {
        $dsn = "mysql:host={$this->rootHost};port={$this->rootPort};charset=utf8mb4";
        return new PDO($dsn, $this->rootUser, $this->rootPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function createDatabase(PDO $pdo, string $dbName): void
    {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function createDatabaseUser(PDO $pdo, string $user, string $password, string $dbName): void
    {
        $pdo->exec("DROP USER IF EXISTS '{$user}'@'%'");
        $pdo->exec("CREATE USER '{$user}'@'%' IDENTIFIED BY '{$password}'");
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, ALTER ON `{$dbName}`.* TO '{$user}'@'%'");
        $pdo->exec("FLUSH PRIVILEGES");
    }

    private function runTenantSchema(PDO $pdo, string $dbName): void
    {
        $pdo->exec("USE `{$dbName}`");
        $schemaFile = BASE_PATH . '/database/tenant_template/schema.sql';
        if (!file_exists($schemaFile)) {
            throw new RuntimeException("Centralized schema not found: {$schemaFile}");
        }
        $statements = array_filter(array_map('trim', explode(';', file_get_contents($schemaFile))));
        foreach ($statements as $stmt) {
            $stripped = trim(preg_replace('/--.*$/m', '', $stmt));
            if (!empty($stripped)) $pdo->exec($stmt);
        }
    }

    private function seedTenantDefaults(PDO $pdo, string $dbName): void
    {
        $pdo->exec("USE `{$dbName}`");
        $seedsFile = BASE_PATH . '/database/tenant_template/seeds.sql';
        if (!file_exists($seedsFile)) {
            throw new RuntimeException("Centralized seeds not found: {$seedsFile}");
        }
        $statements = array_filter(array_map('trim', explode(';', file_get_contents($seedsFile))));
        foreach ($statements as $stmt) {
            $stripped = trim(preg_replace('/--.*$/m', '', $stmt));
            if (!empty($stripped)) $pdo->exec($stmt);
        }
    }

    private function createInitialTenantAdmin(PDO $pdo, string $dbName, array $tenantData): array
    {
        $pdo->exec("USE `{$dbName}`");

        // Use the user-supplied password (from tenantData), or fall back to a default
        $plainPassword  = $tenantData['admin_password'] ?? 'Admin@1234';
        $hashedPassword = password_hash($plainPassword, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 3,
        ]);

        $adminEmail    = $tenantData['admin_email'] ?? $tenantData['email'];
        $adminFullName = $tenantData['admin_name']  ?? ('Admin - ' . $tenantData['name']);
        $adminUsername = strtolower(preg_replace('/[^a-z0-9]/', '', $tenantData['tenant_code'])) . '_admin';

        // Encrypt sensitive fields using CryptoService — matches AuthService decryption on login
        $crypto         = new \App\Core\Security\CryptoService();
        $encryptedEmail = $crypto->encrypt($adminEmail);
        $emailHash      = $crypto->hash($adminEmail);
        $encryptedName  = $crypto->encrypt($adminFullName);

        $stmt = $pdo->prepare(
            "INSERT INTO users
                (role_id, username, encrypted_email, email_hash,
                 password_hash, encrypted_full_name, status, created_at, updated_at)
             VALUES
                (1, :username, :enc_email, :email_hash,
                 :password, :enc_name, 'active', NOW(), NOW())"
        );
        $stmt->execute([
            'username'   => $adminUsername,
            'enc_email'  => $encryptedEmail,
            'email_hash' => $emailHash,
            'password'   => $hashedPassword,
            'enc_name'   => $encryptedName,
        ]);

        return ['email' => $adminEmail, 'username' => $adminUsername, 'password_plain' => $plainPassword];
    }

    private function getPlanDays(string $plan): int
    {
        return match($plan) {
            'trial'        => 14,
            'enterprise'   => 365,
            default        => 30,
        };
    }

    private function getPlanLimit(string $plan, string $resource): int
    {
        $limits = [
            'trial'        => ['users' => 5,   'patients' => 100,  'doctors' => 2],
            'basic'        => ['users' => 10,  'patients' => 500,  'doctors' => 3],
            'standard'     => ['users' => 25,  'patients' => 2000, 'doctors' => 10],
            'professional' => ['users' => 50,  'patients' => 5000, 'doctors' => 20],
            'enterprise'   => ['users' => 999, 'patients' => 99999,'doctors' => 999],
        ];
        return $limits[$plan][$resource] ?? 10;
    }
}