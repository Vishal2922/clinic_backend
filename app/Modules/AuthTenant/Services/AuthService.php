<?php

namespace App\Modules\AuthTenant\Services;

use App\Core\Security\JwtService;
use App\Core\Security\TokenService;
use App\Core\Security\CryptoService;
use App\Core\Middleware\CsrfGuard;
use App\Modules\UsersRoles\Models\Permission;
use App\Modules\AuthTenant\Models\AuthModel;

class AuthService
{
    private JwtService $jwt;
    private TokenService $tokenService;
    private CryptoService $crypto;
    private Permission $permissionModel;
    private AuthModel $authModel;
    
    // Centralized Argon Options to ensure consistent hashing
    private array $argonOptions = [
        'memory_cost' => 65536,
        'time_cost'   => 4,
        'threads'     => 3,
    ];

    public function __construct()
    {
        $this->jwt             = new JwtService();
        $this->tokenService    = new TokenService();
        $this->crypto          = new CryptoService();
        $this->permissionModel = new Permission();
        $this->authModel       = new AuthModel();
    }

    public function register(array $data, int $tenantId): array
    {
        $existing = $this->authModel->findByUsername($data['username']);

        if ($existing) {
            throw new \RuntimeException('Username already exists in this tenant');
        }

        $emailHash = $this->crypto->hash($data['email']);
        $existingEmail = $this->authModel->findByEmailHash($emailHash);

        if ($existingEmail) {
            throw new \RuntimeException('Email already registered in this tenant');
        }

        $roleId = $data['role_id'] ?? null;
        if (!$roleId) {
            $defaultRole = $this->authModel->findDefaultRole();
            $roleId = $defaultRole ? $defaultRole['id'] : null;
        }

        if (!$roleId) {
            throw new \RuntimeException('Default role not found for this tenant');
        }

        $role = $this->authModel->findRoleById($roleId);

        if (!$role) {
            throw new \RuntimeException('Invalid role for this tenant');
        }

        $encryptedEmail    = $this->crypto->encrypt($data['email']);
        $encryptedFullName = isset($data['full_name']) ? $this->crypto->encrypt($data['full_name']) : null;
        $encryptedPhone    = isset($data['phone']) ? $this->crypto->encrypt($data['phone']) : null;

        $passwordHash = password_hash($data['password'], PASSWORD_ARGON2ID, $this->argonOptions);

        $userId = $this->authModel->createUser([
            'role_id'             => $roleId,
            'username'            => trim($data['username']),
            'encrypted_email'     => $encryptedEmail,
            'email_hash'          => $emailHash,
            'password_hash'       => $passwordHash,
            'encrypted_full_name' => $encryptedFullName,
            'encrypted_phone'     => $encryptedPhone,
            'status'              => 'active',
        ]);

        app_log("User registered: {$data['username']} (ID: {$userId}) in tenant {$tenantId}");

        return [
            'user_id'  => $userId,
            'username' => $data['username'],
            'role'     => $role['role_name'],
        ];
    }

    public function login(string $username, string $password, int $tenantId): array
    {
        app_log("[AuthService] Attempting login for '{$username}' in tenant '{$tenantId}'");
        
        $user = $this->authModel->findForLogin($username);

        if (!$user) {
            app_log("Login failed: User '{$username}' not found in tenant {$tenantId}", 'WARNING');
            throw new \RuntimeException('Invalid credentials');
        }

        if ($user['status'] !== 'active') {
            throw new \RuntimeException('Account is inactive. Contact your administrator.');
        }

        if (!password_verify($password, $user['password_hash'])) {
            app_log("Failed login attempt for user: {$username} in tenant {$tenantId}", 'WARNING');
            throw new \RuntimeException('Invalid credentials');
        }

        // FIX: Added $this->argonOptions so it doesn't default to PHP's lower settings 
        // and trigger a constant rehash loop.
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID, $this->argonOptions)) {
            $newHash = password_hash($password, PASSWORD_ARGON2ID, $this->argonOptions);
            $this->authModel->rehashPassword((int) $user['id'], $newHash);
        }

        $permissions    = $this->permissionModel->getByRoleId((int) $user['role_id']);
        $permissionKeys = array_column($permissions, 'permission_key');

        $accessToken = $this->jwt->generateAccessToken([
            'sub'         => (int) $user['id'],
            'tenant_id'   => $tenantId,
            'role_id'     => (int) $user['role_id'],
            'role_name'   => $user['role_name'],
            'username'    => $user['username'],
            'permissions' => $permissionKeys,
        ]);

        $refreshToken = $this->tokenService->createRefreshToken(
            (int) $user['id'],
            $tenantId
        );

        $this->tokenService->setRefreshTokenCookie($refreshToken);

        $csrfToken = CsrfGuard::generate();

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

    public function refreshAccessToken(string $rawRefreshToken, int $tenantId): array
    {
        $tokenRecord = $this->tokenService->validateRefreshToken($rawRefreshToken);

        if (!$tokenRecord) {
            throw new \RuntimeException('Invalid or expired refresh token');
        }

        $userId = (int) $tokenRecord['user_id'];
        // Use the tenant_id from ResolveTenant middleware (passed by the controller),
        // NOT from the token record — the refresh_tokens table does not store tenant_id.

        $user = $this->authModel->findById($userId);

        if (!$user || $user['status'] !== 'active') {
            $this->tokenService->revokeAllUserTokens($userId);
            throw new \RuntimeException('User not found or inactive');
        }

        $rotationResult = $this->tokenService->rotateRefreshToken(
            $rawRefreshToken,
            $userId,
            $tenantId
        );

        if (!$rotationResult) {
            throw new \RuntimeException('Token rotation failed');
        }

        $permissions    = $this->permissionModel->getByRoleId((int) $user['role_id']);
        $permissionKeys = array_column($permissions, 'permission_key');

        $accessToken = $this->jwt->generateAccessToken([
            'sub'         => $userId,
            'tenant_id'   => $tenantId,
            'role_id'     => (int) $user['role_id'],
            'role_name'   => $user['role_name'],
            'username'    => $user['username'],
            'permissions' => $permissionKeys,
        ]);

        $this->tokenService->setRefreshTokenCookie($rotationResult['refresh_token']);

        app_log("Token refreshed for user: {$user['username']} (ID: {$userId}) in tenant {$tenantId}");

        return [
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => $this->jwt->getAccessTtl(),
            'csrf_token'   => $rotationResult['csrf_token'],
        ];
    }

    public function logout(?string $rawRefreshToken, int $userId): void
    {
        if ($rawRefreshToken) {
            $this->tokenService->revokeToken($rawRefreshToken);
        }

        $this->tokenService->clearRefreshTokenCookie();
        CsrfGuard::destroy();

        app_log("User logged out (ID: {$userId})");
    }

    public function logoutAll(int $userId): void
    {
        $this->tokenService->revokeAllUserTokens($userId);
        $this->tokenService->clearRefreshTokenCookie();
        CsrfGuard::destroy();

        app_log("User logged out from all devices (ID: {$userId})");
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->authModel->findPasswordById($userId);

        if (!$user) {
            throw new \RuntimeException('User not found');
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            throw new \RuntimeException('Current password is incorrect');
        }

        $newHash = password_hash($newPassword, PASSWORD_ARGON2ID, $this->argonOptions);

        $this->authModel->updatePassword($userId, $newHash);

        $this->tokenService->revokeAllUserTokens($userId);
        CsrfGuard::destroy();

        app_log("Password changed for user ID: {$userId}");
    }
}