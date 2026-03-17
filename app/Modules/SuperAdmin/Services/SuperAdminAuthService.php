<?php

namespace App\Modules\SuperAdmin\Services;

use App\Core\MasterDatabase;
use App\Core\Security\JwtService;
use RuntimeException;

class SuperAdminAuthService
{
    private MasterDatabase $masterDb;
    private JwtService     $jwtService;

    public function __construct()
    {
        $this->masterDb   = MasterDatabase::getInstance();
        $this->jwtService = new JwtService();
    }

    public function login(string $email, string $password, string $ip): array
    {
        $superAdmin = $this->masterDb->fetch(
            'SELECT id, email, name, password_hash, role, is_active, failed_attempts, locked_until
             FROM super_admins WHERE email = :email',
            ['email' => strtolower(trim($email))]
        );

        if (!$superAdmin) {
            password_verify($password, '$2y$12$invalidhashfortimingprotection123456789');
            throw new RuntimeException('Invalid credentials.', 401);
        }

        if ($superAdmin['locked_until'] && strtotime($superAdmin['locked_until']) > time()) {
            $remaining = ceil((strtotime($superAdmin['locked_until']) - time()) / 60);
            throw new RuntimeException("Account locked. Try again in {$remaining} minutes.", 423);
        }

        if (!$superAdmin['is_active']) {
            throw new RuntimeException('Super admin account is deactivated.', 403);
        }

        if (!password_verify($password, $superAdmin['password_hash'])) {
            $this->incrementFailedAttempts($superAdmin['id']);
            throw new RuntimeException('Invalid credentials.', 401);
        }

        $this->masterDb->execute(
            'UPDATE super_admins SET failed_attempts = 0, locked_until = NULL,
                    last_login_at = NOW(), last_login_ip = :ip, updated_at = NOW()
             WHERE id = :id',
            ['ip' => $ip, 'id' => $superAdmin['id']]
        );

        $accessToken = $this->jwtService->generateAccessToken([
            'sub'         => $superAdmin['id'],
            'email'       => $superAdmin['email'],
            'name'        => $superAdmin['name'],
            'role_id'     => 0,
            'role_name'   => $superAdmin['role'],
            'tenant_id'   => 0,
            'username'    => $superAdmin['email'],
            'scope'       => 'super_admin',
            'permissions' => ['*'],
        ]);

        $this->masterDb->insert(
            'INSERT INTO master_audit_logs (super_admin_id, action, resource_type, details, ip_address, created_at)
             VALUES (:id, "super_admin_login", "super_admin", :details, :ip, NOW())',
            [
                'id'      => $superAdmin['id'],
                'details' => json_encode(['email' => $superAdmin['email']]),
                'ip'      => $ip,
            ]
        );

        return [
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => (int) env('JWT_ACCESS_TTL', 900),
            'admin' => [
                'id'    => $superAdmin['id'],
                'email' => $superAdmin['email'],
                'name'  => $superAdmin['name'],
                'role'  => $superAdmin['role'],
            ],
        ];
    }

    public function createSuperAdmin(array $data, int $createdById): array
    {
        $existing = $this->masterDb->fetch(
            'SELECT id FROM super_admins WHERE email = :email',
            ['email' => strtolower($data['email'])]
        );

        if ($existing) {
            throw new RuntimeException('A super admin with this email already exists.', 409);
        }

        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);

        $id = $this->masterDb->insert(
            'INSERT INTO super_admins (name, email, password_hash, role, is_active, created_by, created_at, updated_at)
             VALUES (:name, :email, :password, :role, 1, :created_by, NOW(), NOW())',
            [
                'name'       => $data['name'],
                'email'      => strtolower($data['email']),
                'password'   => $passwordHash,
                'role'       => $data['role'] ?? 'admin',
                'created_by' => $createdById,
            ]
        );

        return ['id' => $id, 'email' => $data['email'], 'role' => $data['role'] ?? 'admin'];
    }

    private function incrementFailedAttempts(int $adminId): void
    {
        $admin    = $this->masterDb->fetch('SELECT failed_attempts FROM super_admins WHERE id = :id', ['id' => $adminId]);
        $attempts = ($admin['failed_attempts'] ?? 0) + 1;
        $lockUntil = $attempts >= 5 ? date('Y-m-d H:i:s', strtotime('+15 minutes')) : null;

        $this->masterDb->execute(
            'UPDATE super_admins SET failed_attempts = :attempts, locked_until = :lock, updated_at = NOW() WHERE id = :id',
            ['attempts' => $attempts, 'lock' => $lockUntil, 'id' => $adminId]
        );
    }
}