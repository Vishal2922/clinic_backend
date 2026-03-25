<?php

namespace App\Modules\SettingsSecurity\Services;

use App\Core\Security\JwtService;
use App\Core\Security\TokenService;
use App\Core\Security\CryptoService;
use App\Core\Middleware\CsrfGuard;
use App\Modules\SettingsSecurity\Models\UserSession;
use App\Modules\SettingsSecurity\Models\AuditLog;
use App\Modules\UsersRoles\Models\Permission;

class SettingsService
{
    private function db(): \App\Core\TenantDatabase { return tenant_db(); }

    private JwtService $jwt;
    private TokenService $tokenService;
    private CryptoService $crypto;
    private UserSession $sessionModel;
    private AuditLog $auditModel;
    private Permission $permissionModel;

    public function __construct()
    {
        $this->jwt             = new JwtService();
        $this->tokenService    = new TokenService();
        $this->crypto          = new CryptoService();
        $this->sessionModel    = new UserSession();
        $this->auditModel      = new AuditLog();
        $this->permissionModel = new Permission();
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword, array $requestMeta = []): void
    {
        $user = $this->db()->fetch(
            'SELECT id, password_hash FROM users WHERE id = :id AND deleted_at IS NULL',
            ['id' => $userId]
        );

        if (!$user) {
            throw new \RuntimeException('User not found');
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            $this->logAudit($userId, $requestMeta['tenant_id'] ?? null, 'PASSWORD_CHANGE_FAILED', 'user', $userId, $requestMeta);
            throw new \RuntimeException('Current password is incorrect');
        }

        if ($currentPassword === $newPassword) {
            throw new \RuntimeException('New password must be different from current password');
        }

        $newHash = password_hash($newPassword, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 3,
        ]);

        $this->db()->execute(
            'UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id',
            ['hash' => $newHash, 'id' => $userId]
        );

        $this->tokenService->revokeAllUserTokens($userId);
        $this->sessionModel->invalidateAllForUser($userId);
        CsrfGuard::destroy();

        $this->logAudit($userId, $requestMeta['tenant_id'] ?? null, 'PASSWORD_CHANGED', 'user', $userId, $requestMeta);

        app_log("Password changed for user ID: {$userId}");
    }

    public function logout(?string $rawRefreshToken, int $userId, array $requestMeta = []): void
    {
        if ($rawRefreshToken) {
            $this->tokenService->revokeToken($rawRefreshToken);
        }

        $this->tokenService->clearRefreshTokenCookie();
        CsrfGuard::destroy();

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->sessionModel->invalidateAllForUser($userId);
            session_regenerate_id(true);
        }

        $this->logAudit($userId, $requestMeta['tenant_id'] ?? null, 'LOGOUT', 'user', $userId, $requestMeta);

        app_log("User logged out (ID: {$userId})");
    }

    public function logoutAll(int $userId, array $requestMeta = []): void
    {
        $this->tokenService->revokeAllUserTokens($userId);
        $this->tokenService->clearRefreshTokenCookie();
        $this->sessionModel->invalidateAllForUser($userId);
        CsrfGuard::destroy();

        $this->logAudit($userId, $requestMeta['tenant_id'] ?? null, 'LOGOUT_ALL_DEVICES', 'user', $userId, $requestMeta);

        app_log("User logged out from all devices (ID: {$userId})");
    }

    public function rotateTokens(string $rawRefreshToken, int $tenantId): array
    {
        $tokenRecord = $this->tokenService->validateRefreshToken($rawRefreshToken);
        if (!$tokenRecord) {
            throw new \RuntimeException('Invalid or expired refresh token. Please log in again.');
        }

        $userId = (int) $tokenRecord['user_id'];
        // Use the tenant_id from ResolveTenant middleware (passed by the controller),
        // NOT from the token record — the refresh_tokens table does not store tenant_id.

        $user = $this->db()->fetch(
            'SELECT u.*, r.name AS role_name
             FROM users u
             JOIN roles r ON u.role_id = r.id
             WHERE u.id = :id AND u.deleted_at IS NULL',
            ['id' => $userId]
        );

        if (!$user || $user['status'] !== 'active') {
            $this->tokenService->revokeAllUserTokens($userId);
            throw new \RuntimeException('User not found or inactive');
        }

        $rotationResult = $this->tokenService->rotateRefreshToken($rawRefreshToken, $userId, $tenantId);
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

        $this->logAudit($userId, $tenantId, 'TOKEN_ROTATED', 'user', $userId, []);

        app_log("Tokens rotated for user ID: {$userId} in tenant {$tenantId}");

        return [
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => $this->jwt->getAccessTtl(),
            'csrf_token'   => $rotationResult['csrf_token'],
        ];
    }

    public function regenerateCsrf(): array
    {
        $token = CsrfGuard::regenerate();

        return [
            'csrf_token' => $token,
            'expires_in' => (int) env('CSRF_TTL', 3600),
        ];
    }

    public function getActiveSessions(int $userId): array
    {
        return $this->sessionModel->getActiveSessions($userId);
    }

    public function getTheme(int $tenantId): array
    {
        $setting = $this->db()->fetch(
            'SELECT setting_value FROM tenant_settings WHERE tenant_id = :tid AND setting_key = :key',
            ['tid' => $tenantId, 'key' => 'theme']
        );
        if ($setting && $setting['setting_value']) {
            return json_decode($setting['setting_value'], true);
        }
        return ['primaryColor' => '#20b486'];
    }

    public function updateTheme(int $tenantId, array $themeData, int $userId, array $requestMeta = []): array
    {
        $jsonValue = json_encode($themeData);
        $this->db()->execute(
            'INSERT INTO tenant_settings (tenant_id, setting_key, setting_value, created_at, updated_at)
             VALUES (:tid, :key, :val, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = :val2, updated_at = NOW()',
            [
                'tid' => $tenantId,
                'key' => 'theme',
                'val' => $jsonValue,
                'val2' => $jsonValue
            ]
        );
        $this->logAudit($userId, $tenantId, 'THEME_UPDATED', 'tenant_settings', null, $requestMeta);
        return $themeData;
    }

    public function invalidateSession(int $sessionId, int $userId, array $requestMeta = []): bool
    {
        $result = $this->sessionModel->invalidate($sessionId, $userId);

        if ($result) {
            $this->logAudit(
                $userId,
                $requestMeta['tenant_id'] ?? null,
                'SESSION_INVALIDATED',
                'session',
                $sessionId,
                $requestMeta
            );
        }

        return $result;
    }

    public function getAuditLog(int $tenantId, int $page = 1, int $perPage = 50, array $filters = []): array
    {
        return $this->auditModel->getByTenant($tenantId, $page, $perPage, $filters);
    }

    public function getAuditActions(int $tenantId): array
    {
        return $this->auditModel->getDistinctActions($tenantId);
    }

    private function logAudit(
        ?int $userId,
        ?int $tenantId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $meta = []
    ): void {
        try {
            $this->auditModel->record([
                'user_id'     => $userId,
                'tenant_id'   => $tenantId,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'ip_address'  => $meta['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                'user_agent'  => $meta['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
                'details'     => $meta['details'] ?? null,
            ]);
        } catch (\Exception $e) {
            app_log('Audit log failed: ' . $e->getMessage(), 'ERROR');
        }
    }
}