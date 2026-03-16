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
        if (file_exists($schemaFile)) {
            $statements = array_filter(array_map('trim', explode(';', file_get_contents($schemaFile))));
            foreach ($statements as $stmt) {
                if (!empty($stmt)) $pdo->exec($stmt);
            }
        } else {
            foreach ($this->getInlineSchema() as $sql) {
                $pdo->exec($sql);
            }
        }
    }

    private function seedTenantDefaults(PDO $pdo, string $dbName): void
    {
        $pdo->exec("USE `{$dbName}`");

        $roles = [
            ['Admin', 'Full system access', 1],
            ['Provider', 'Doctor / Physician', 1],
            ['Nurse', 'Nursing staff access', 1],
            ['Receptionist', 'Front desk staff', 1],
            ['Pharmacist', 'Pharmacy access', 1],
            ['Patient', 'Patient portal access', 1],
        ];
        foreach ($roles as [$name, $desc, $sys]) {
            $pdo->exec("INSERT INTO roles (name, description, is_system_role, created_at) VALUES ('{$name}', '{$desc}', {$sys}, NOW())");
        }

        $permissions = [
            ['patients.view','View patient records'], ['patients.create','Create new patients'],
            ['patients.edit','Edit patient records'], ['patients.delete','Delete patients'],
            ['appointments.view','View appointments'], ['appointments.create','Book appointments'],
            ['appointments.manage','Manage appointment status'], ['prescriptions.view','View prescriptions'],
            ['prescriptions.create','Create prescriptions'], ['prescriptions.dispense','Dispense prescriptions'],
            ['billing.view','View billing'], ['billing.create','Create invoices'],
            ['billing.manage','Manage payments'], ['staff.view','View staff'],
            ['staff.manage','Manage staff'], ['reports.view','View reports'],
            ['settings.manage','System settings'], ['audit.view','View audit logs'],
        ];
        foreach ($permissions as [$key, $desc]) {
            $pdo->exec("INSERT INTO permissions (permission_key, description, created_at) VALUES ('{$key}', '{$desc}', NOW())");
        }

        // Admin gets all permissions
        $pdo->exec("INSERT INTO role_permissions (role_id, permission_id, created_at) SELECT 1, id, NOW() FROM permissions");

        // Provider permissions
        $providerPerms = "'patients.view','patients.create','patients.edit','appointments.view','appointments.create','appointments.manage','prescriptions.view','prescriptions.create','billing.view','billing.create','reports.view'";
        $pdo->exec("INSERT INTO role_permissions (role_id, permission_id, created_at) SELECT 2, id, NOW() FROM permissions WHERE permission_key IN ({$providerPerms})");
    }

    private function createInitialTenantAdmin(PDO $pdo, string $dbName, array $tenantData): array
    {
        $pdo->exec("USE `{$dbName}`");

        // Use ARGON2ID to match the algorithm verified by AuthService::login()
        $plainPassword  = $this->generateTemporaryPassword();
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

    private function generateTemporaryPassword(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$';
        $pass  = '';
        for ($i = 0; $i < 12; $i++) {
            $pass .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pass;
    }

    private function getInlineSchema(): array
    {
        // Full tenant schema — column names match all module models exactly
        return [
            // --- RBAC ---
            "CREATE TABLE IF NOT EXISTS `roles` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(50) NOT NULL UNIQUE,
                `description` VARCHAR(255),
                `is_system_role` TINYINT(1) DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `permissions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `permission_key` VARCHAR(100) NOT NULL UNIQUE,
                `description` VARCHAR(255),
                `created_at` DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `role_permissions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `role_id` INT UNSIGNED NOT NULL,
                `permission_id` INT UNSIGNED NOT NULL,
                `created_at` DATETIME NOT NULL,
                UNIQUE KEY `uq_role_perm` (`role_id`, `permission_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // --- USERS (encrypted, NO tenant_id — DB-level isolation) ---
            "CREATE TABLE IF NOT EXISTS `users` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `role_id` INT UNSIGNED NOT NULL,
                `username` VARCHAR(50) NOT NULL UNIQUE,
                `encrypted_email` TEXT,
                `email_hash` VARCHAR(64),
                `password_hash` VARCHAR(255) NOT NULL,
                `encrypted_full_name` TEXT,
                `encrypted_phone` TEXT,
                `status` VARCHAR(20) DEFAULT 'active',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME,
                `deleted_at` DATETIME
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // --- REFRESH TOKENS (with family for rotation) ---
            "CREATE TABLE IF NOT EXISTS `refresh_tokens` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `token_hash` VARCHAR(255) NOT NULL,
                `family` VARCHAR(64),
                `expires_at` DATETIME NOT NULL,
                `revoked` TINYINT(1) DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // --- PATIENTS (encrypted, with tenant_id) ---
            "CREATE TABLE IF NOT EXISTS `patients` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `tenant_id` INT UNSIGNED,
                `encrypted_name` TEXT,
                `name_hash` VARCHAR(64),
                `encrypted_phone` TEXT,
                `phone_hash` VARCHAR(64),
                `encrypted_email` TEXT,
                `email_hash` VARCHAR(64),
                `encrypted_medical_history` TEXT,
                `encrypted_date_of_birth` TEXT,
                `encrypted_gender` TEXT,
                `encrypted_blood_group` TEXT,
                `encrypted_address` TEXT,
                `encrypted_emergency_contact` TEXT,
                `status` VARCHAR(20) DEFAULT 'active',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME,
                `deleted_at` DATETIME
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // --- STAFF (encrypted, with tenant_id) ---
            "CREATE TABLE IF NOT EXISTS `staff` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED,
                `tenant_id` INT UNSIGNED,
                `encrypted_department` TEXT,
                `encrypted_specialization` TEXT,
                `encrypted_license_number` TEXT,
                `encrypted_notes` TEXT,
                `hire_date` DATE,
                `status` VARCHAR(20) DEFAULT 'active',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME,
                `deleted_at` DATETIME
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // --- APPOINTMENTS ---
            "CREATE TABLE IF NOT EXISTS `appointments` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `tenant_id` INT UNSIGNED,
                `patient_id` INT UNSIGNED NOT NULL,
                `doctor_id` INT UNSIGNED NOT NULL,
                `appointment_time` DATETIME NOT NULL,
                `encrypted_reason` TEXT,
                `status` VARCHAR(30) DEFAULT 'scheduled',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME,
                `deleted_at` DATETIME
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // --- APPOINTMENT NOTES ---
            "CREATE TABLE IF NOT EXISTS `appointment_notes` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `tenant_id` INT UNSIGNED,
                `appointment_id` INT UNSIGNED NOT NULL,
                `author_id` INT UNSIGNED NOT NULL,
                `message_encrypted` TEXT,
                `note_type` VARCHAR(30) DEFAULT 'note',
                `visible_to_role` VARCHAR(30) DEFAULT 'all',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME,
                `deleted_at` DATETIME
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // --- PRESCRIPTIONS ---
            "CREATE TABLE IF NOT EXISTS `prescriptions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `tenant_id` INT UNSIGNED,
                `appointment_id` INT UNSIGNED,
                `patient_id` INT UNSIGNED NOT NULL,
                `provider_id` INT UNSIGNED NOT NULL,
                `pharmacist_id` INT UNSIGNED,
                `encrypted_medicine_name` TEXT,
                `encrypted_dosage` TEXT,
                `encrypted_notes` TEXT,
                `duration_days` INT DEFAULT 7,
                `status` VARCHAR(20) DEFAULT 'pending',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // --- INVOICES ---
            "CREATE TABLE IF NOT EXISTS `invoices` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `tenant_id` INT UNSIGNED,
                `invoice_number` VARCHAR(30) UNIQUE,
                `patient_id` INT UNSIGNED NOT NULL,
                `provider_id` INT UNSIGNED,
                `appointment_id` INT UNSIGNED,
                `amount` DECIMAL(10,2) DEFAULT 0,
                `subtotal` DECIMAL(10,2) DEFAULT 0,
                `tax` DECIMAL(10,2) DEFAULT 0,
                `discount` DECIMAL(10,2) DEFAULT 0,
                `total` DECIMAL(10,2) DEFAULT 0,
                `total_amount` DECIMAL(10,2) DEFAULT 0,
                `paid_amount` DECIMAL(10,2) DEFAULT 0,
                `status` VARCHAR(30) DEFAULT 'draft',
                `payment_method` VARCHAR(30),
                `paid_at` DATETIME,
                `due_date` DATE,
                `encrypted_notes` TEXT,
                `created_by` INT UNSIGNED,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME,
                `deleted_at` DATETIME
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // --- AUDIT LOG ---
            "CREATE TABLE IF NOT EXISTS `audit_log` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED,
                `tenant_id` INT UNSIGNED,
                `action` VARCHAR(100) NOT NULL,
                `entity_type` VARCHAR(100),
                `entity_id` INT UNSIGNED,
                `old_values` JSON,
                `new_values` JSON,
                `ip_address` VARCHAR(45),
                `user_agent` TEXT,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // --- USER SESSIONS ---
            "CREATE TABLE IF NOT EXISTS `user_sessions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `tenant_id` INT UNSIGNED,
                `session_id` VARCHAR(255) NOT NULL UNIQUE,
                `ip_address` VARCHAR(45),
                `user_agent` TEXT,
                `is_active` TINYINT(1) DEFAULT 1,
                `last_active` DATETIME,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }
}