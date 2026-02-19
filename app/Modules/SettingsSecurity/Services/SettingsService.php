<?php

namespace App\Modules\SettingsSecurity\Services;

use App\Core\Database;
use App\Core\Security\JwtService;
use App\Core\Security\TokenService;
use App\Core\Security\CryptoService;
use App\Core\Middleware\CsrfGuard;
use App\Modules\SettingsSecurity\Models\UserSession;
use App\Modules\SettingsSecurity\Models\AuditLog;
use App\Modules\UsersRoles\Models\Permission;

/**
 * SettingsService
 * Handles user account settings and security operations:
 *  - Change password
 *  - Logout / Invalidate session
 *  - Token rotation
 *  - CSRF regeneration
 *  - Audit logging
 */
class SettingsService
{
    private Database $db;
    private JwtService $jwt;
    private TokenService $tokenService;
    private CryptoService $crypto;
    private UserSession $sessionModel;
    private AuditLog $auditModel;
    private Permission $permissionModel;

    public function __construct()
    {
        $this->db              = Database::getInstance();
        $this->jwt             = new JwtService();
        $this->tokenService    = new TokenService();
        $this->crypto          = new CryptoService();
        $this->sessionModel    = new UserSession();
        $this->auditModel      = new AuditLog();
        $this->permissionModel = new Permission();
    }

    /**
     * Change password for a user.
     * Validates current password, enforces policy, revokes all tokens.
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword, array $requestMeta = []): void
    {
        $user = $this->db->fetch(
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

        $this->db->execute(
            'UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id',
            ['hash' => $newHash, 'id' => $userId]
        );

        // Revoke all refresh tokens (force re-login on all devices)
        $this->tokenService->revokeAllUserTokens($userId);

        // Invalidate all sessions
        $this->sessionModel->invalidateAllForUser($userId);

        // Destroy CSRF
        CsrfGuard::destroy();

        $this->logAudit($userId, $requestMeta['tenant_id'] ?? null, 'PASSWORD_CHANGED', 'user', $userId, $requestMeta);

        app_log("Password changed for user ID: {$userId}");
    }

    /**
     * Logout: revoke specific refresh token, clear cookie, destroy CSRF, invalidate session.
     */
    public function logout(?string $rawRefreshToken, int $userId, array $requestMeta = []): void
    {
        if ($rawRefreshToken) {
            $this->tokenService->revokeToken($rawRefreshToken);
        }

        $this->tokenService->clearRefreshTokenCookie();
        CsrfGuard::destroy();

        // Invalidate current PHP session
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionId = session_id();
            if ($sessionId) {
                $this->sessionModel->invalidate(0, $userId); // Mark by user
            }
            session_regenerate_id(true);
        }

        $this->logAudit($userId, $requestMeta['tenant_id'] ?? null, 'LOGOUT', 'user', $userId, $requestMeta);

        app_log("User logged out (ID: {$userId})");
    }

    /**
     * Logout from all devices: revoke ALL tokens, invalidate all sessions.
     */
    public function logoutAll(int $userId, array $requestMeta = []): void
    {
        $this->tokenService->revokeAllUserTokens($userId);
        $this->tokenService->clearRefreshTokenCookie();
        $this->sessionModel->invalidateAllForUser($userId);
        CsrfGuard::destroy();

        $this->logAudit($userId, $requestMeta['tenant_id'] ?? null, 'LOGOUT_ALL_DEVICES', 'user', $userId, $requestMeta);

        app_log("User logged out from all devices (ID: {$userId})");
    }

    /**
     * Rotate tokens: generate new access + refresh tokens, regenerate CSRF.
     * Used when the client wants to proactively rotate for security.
     */
    public function rotateTokens(string $rawRefreshToken, int $userId, int $tenantId): array
    {
        $tokenRecord = $this->tokenService->validateRefreshToken($rawRefreshToken);
        if (!$tokenRecord) {
            throw new \RuntimeException('Invalid or expired refresh token');
        }

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

        // Rotate refresh token
        $rotationResult = $this->tokenService->rotateRefreshToken($rawRefreshToken, $userId, $tenantId);
        if (!$rotationResult) {
            throw new \RuntimeException('Token rotation failed');
        }

        // Get fresh permissions
        $permissions    = $this->permissionModel->getByRoleId((int) $user['role_id']);
        $permissionKeys = array_column($permissions, 'permission_key');

        // Generate new access token
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

        $this->logAudit($userId, $tenantId, 'TOKEN_ROTATED', 'user', $userId, []);

        app_log("Tokens rotated for user ID: {$userId}");

        return [
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => $this->jwt->getAccessTtl(),
            'csrf_token'   => $rotationResult['csrf_token'],
        ];
    }

    /**
     * Regenerate CSRF token.
     */
    public function regenerateCsrf(): array
    {
        $token = CsrfGuard::regenerate();

        return [
            'csrf_token' => $token,
            'expires_in' => (int) env('CSRF_TTL', 3600),
        ];
    }

    /**
     * Get active sessions for a user.
     */
    public function getActiveSessions(int $userId): array
    {
        return $this->sessionModel->getActiveSessions($userId);
    }

    /**
     * Invalidate a specific session.
     */
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

    /**
     * Get audit log for a tenant (Admin only).
     */
    public function getAuditLog(int $tenantId, int $page = 1, int $perPage = 50, array $filters = []): array
    {
        return $this->auditModel->getByTenant($tenantId, $page, $perPage, $filters);
    }

    /**
     * Get distinct audit actions for filter UI.
     */
    public function getAuditActions(int $tenantId): array
    {
        return $this->auditModel->getDistinctActions($tenantId);
    }

    /**
     * Internal helper to log audit events.
     */
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