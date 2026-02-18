<?php

namespace App\Modules\AuthTenant\Services;

use App\Core\Database;
use App\Core\Security\JwtService;
use App\Core\Security\TokenService;
use App\Core\Security\CryptoService;
use App\Core\Middleware\CsrfGuard;
use App\Modules\UsersRoles\Models\Permission;

class AuthService
{
    private Database $db;
    private JwtService $jwt;
    private TokenService $tokenService;
    private CryptoService $crypto;
    private Permission $permissionModel;

    public function __construct()
    {
        $this->db              = Database::getInstance();
        $this->jwt             = new JwtService();
        $this->tokenService    = new TokenService();
        $this->crypto          = new CryptoService();
        $this->permissionModel = new Permission();
    }

    /**
     * Register a new user
     */
    public function register(array $data, int $tenantId): array
    {
        // Check if username already exists in this tenant
        $existing = $this->db->fetch(
            'SELECT id FROM users WHERE tenant_id = :tid AND username = :username AND deleted_at IS NULL',
            ['tid' => $tenantId, 'username' => $data['username']]
        );

        if ($existing) {
            throw new \RuntimeException('Username already exists in this tenant');
        }

        // Check if email already exists (using email hash for lookup)
        $emailHash = $this->crypto->hash($data['email']);
        $existingEmail = $this->db->fetch(
            'SELECT id FROM users WHERE tenant_id = :tid AND email_hash = :hash AND deleted_at IS NULL',
            ['tid' => $tenantId, 'hash' => $emailHash]
        );

        if ($existingEmail) {
            throw new \RuntimeException('Email already registered in this tenant');
        }

        // Get default role (Patient for self-registration)
        $roleId = $data['role_id'] ?? null;
        if (!$roleId) {
            $defaultRole = $this->db->fetch(
                'SELECT id FROM roles WHERE tenant_id = :tid AND role_name = :name',
                ['tid' => $tenantId, 'name' => 'Patient']
            );
            $roleId = $defaultRole ? $defaultRole['id'] : null;
        }

        if (!$roleId) {
            throw new \RuntimeException('Default role not found for this tenant');
        }

        // Validate role belongs to tenant
        $role = $this->db->fetch(
            'SELECT id, role_name FROM roles WHERE id = :rid AND tenant_id = :tid',
            ['rid' => $roleId, 'tid' => $tenantId]
        );

        if (!$role) {
            throw new \RuntimeException('Invalid role for this tenant');
        }

        // Encrypt sensitive data with AES-256-CBC
        $encryptedEmail    = $this->crypto->encrypt($data['email']);
        $encryptedFullName = isset($data['full_name']) ? $this->crypto->encrypt($data['full_name']) : null;
        $encryptedPhone    = isset($data['phone']) ? $this->crypto->encrypt($data['phone']) : null;

        // Hash password with Argon2ID
        $passwordHash = password_hash($data['password'], PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 3,
        ]);

        // Insert user
        $userId = $this->db->insert(
            'INSERT INTO users (tenant_id, role_id, username, encrypted_email, email_hash, password_hash, encrypted_full_name, encrypted_phone, status) 
             VALUES (:tenant_id, :role_id, :username, :encrypted_email, :email_hash, :password_hash, :encrypted_full_name, :encrypted_phone, :status)',
            [
                'tenant_id'          => $tenantId,
                'role_id'            => $roleId,
                'username'           => sanitize($data['username']),
                'encrypted_email'    => $encryptedEmail,
                'email_hash'         => $emailHash,
                'password_hash'      => $passwordHash,
                'encrypted_full_name'=> $encryptedFullName,
                'encrypted_phone'    => $encryptedPhone,
                'status'             => 'active',
            ]
        );

        app_log("User registered: {$data['username']} (ID: {$userId}) in tenant {$tenantId}");

        return [
            'user_id'  => $userId,
            'username' => $data['username'],
            'role'     => $role['role_name'],
        ];
    }

    /**
     * Login user — returns access token, sets refresh cookie, generates CSRF
     */
    public function login(string $username, string $password, int $tenantId): array
    {
        // Find user with role
        $user = $this->db->fetch(
            'SELECT u.*, r.role_name 
             FROM users u 
             JOIN roles r ON u.role_id = r.id 
             WHERE u.tenant_id = :tid 
               AND u.username = :username 
               AND u.deleted_at IS NULL',
            ['tid' => $tenantId, 'username' => $username]
        );

        if (!$user) {
            throw new \RuntimeException('Invalid credentials');
        }

        if ($user['status'] !== 'active') {
            throw new \RuntimeException('Account is inactive. Contact your administrator.');
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            app_log("Failed login attempt for user: {$username} in tenant {$tenantId}", 'WARNING');
            throw new \RuntimeException('Invalid credentials');
        }

        // Rehash if algorithm upgraded
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            $newHash = password_hash($password, PASSWORD_ARGON2ID);
            $this->db->execute(
                'UPDATE users SET password_hash = :hash WHERE id = :id',
                ['hash' => $newHash, 'id' => $user['id']]
            );
        }

        // Get user's permissions for this role
        $permissions    = $this->permissionModel->getByRoleId((int) $user['role_id']);
        $permissionKeys = array_column($permissions, 'permission_key');

        // Generate access token (JWT) with role + permissions
        $accessToken = $this->jwt->generateAccessToken([
            'sub'         => (int) $user['id'],
            'tenant_id'   => $tenantId,
            'role_id'     => (int) $user['role_id'],
            'role_name'   => $user['role_name'],
            'username'    => $user['username'],
            'permissions' => $permissionKeys,
        ]);

        // Generate refresh token (stored hashed in DB)
        $refreshToken = $this->tokenService->createRefreshToken(
            (int) $user['id'],
            $tenantId
        );

        // Set refresh token as HttpOnly cookie
        $this->tokenService->setRefreshTokenCookie($refreshToken);

        // Generate CSRF token (stored in PHP session)
        $csrfToken = CsrfGuard::generate();

        // Decrypt user data for response
        $decryptedEmail = $this->crypto->decrypt($user['encrypted_email']);
        $decryptedName  = $user['encrypted_full_name']
            ? $this->crypto->decrypt($user['encrypted_full_name'])
            : null;

        app_log("User logged in: {$username} (ID: {$user['id']}) in tenant {$tenantId}");

        return [
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => $this->jwt->getAccessTtl(),
            'csrf_token'   => $csrfToken,
            'user' => [
                'id'          => (int) $user['id'],
                'username'    => $user['username'],
                'email'       => $decryptedEmail,
                'full_name'   => $decryptedName,
                'role'        => $user['role_name'],
                'role_id'     => (int) $user['role_id'],
                'permissions' => $permissionKeys,
                'tenant_id'   => $tenantId,
            ],
        ];
    }

    /**
     * Refresh access token using refresh token from cookie
     * Rotates refresh token + regenerates CSRF
     */
    public function refreshAccessToken(string $rawRefreshToken): array
    {
        // Validate refresh token via TokenService -> Model
        $tokenRecord = $this->tokenService->validateRefreshToken($rawRefreshToken);

        if (!$tokenRecord) {
            throw new \RuntimeException('Invalid or expired refresh token');
        }

        $userId   = (int) $tokenRecord['user_id'];
        $tenantId = (int) $tokenRecord['tenant_id'];

        // Get fresh user data
        $user = $this->db->fetch(
            'SELECT u.*, r.role_name 
             FROM users u 
             JOIN roles r ON u.role_id = r.id 
             WHERE u.id = :id AND u.deleted_at IS NULL',
            ['id' => $userId]
        );

        if (!$user || $user['status'] !== 'active') {
            $this->tokenService->revokeAllUserTokens($userId);
            throw new \RuntimeException('User not found or inactive');
        }

        // Rotate refresh token (revoke old, create new, regenerate CSRF)
        $rotationResult = $this->tokenService->rotateRefreshToken(
            $rawRefreshToken,
            $userId,
            $tenantId
        );

        if (!$rotationResult) {
            throw new \RuntimeException('Token rotation failed');
        }

        // Get fresh permissions for the new access token
        $permissions    = $this->permissionModel->getByRoleId((int) $user['role_id']);
        $permissionKeys = array_column($permissions, 'permission_key');

        // Generate new access token with current role + permissions
        $accessToken = $this->jwt->generateAccessToken([
            'sub'         => $userId,
            'tenant_id'   => $tenantId,
            'role_id'     => (int) $user['role_id'],
            'role_name'   => $user['role_name'],
            'username'    => $user['username'],
            'permissions' => $permissionKeys,
        ]);

        // Set new refresh token cookie
        $this->tokenService->setRefreshTokenCookie($rotationResult['refresh_token']);

        app_log("Token refreshed for user: {$user['username']} (ID: {$userId})");

        return [
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => $this->jwt->getAccessTtl(),
            'csrf_token'   => $rotationResult['csrf_token'],
        ];
    }

    /**
     * Logout: revoke refresh token, clear cookie, destroy CSRF session
     */
    public function logout(?string $rawRefreshToken, int $userId): void
    {
        if ($rawRefreshToken) {
            $this->tokenService->revokeToken($rawRefreshToken);
        }

        $this->tokenService->clearRefreshTokenCookie();

        // Destroy CSRF token from session
        CsrfGuard::destroy();

        app_log("User logged out (ID: {$userId})");
    }

    /**
     * Logout from all devices: revoke ALL refresh tokens
     */
    public function logoutAll(int $userId): void
    {
        $this->tokenService->revokeAllUserTokens($userId);
        $this->tokenService->clearRefreshTokenCookie();
        CsrfGuard::destroy();

        app_log("User logged out from all devices (ID: {$userId})");
    }

    /**
     * Change password: update hash, revoke all tokens (force re-login)
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->db->fetch(
            'SELECT id, password_hash FROM users WHERE id = :id AND deleted_at IS NULL',
            ['id' => $userId]
        );

        if (!$user) {
            throw new \RuntimeException('User not found');
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            throw new \RuntimeException('Current password is incorrect');
        }

        $newHash = password_hash($newPassword, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 3,
        ]);

        $this->db->execute(
            'UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id',
            ['hash' => $newHash, 'id' => $userId]
        );

        // Revoke all refresh tokens (force re-login on all devices)
        $this->tokenService->revokeAllUserTokens($userId);
        CsrfGuard::destroy();

        app_log("Password changed for user ID: {$userId}");
    }
}