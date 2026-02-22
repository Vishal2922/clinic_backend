<?php

namespace App\Core\Security;

use App\Core\Database;
use App\Core\Middleware\CsrfGuard;
use App\Modules\AuthTenant\Models\RefreshToken;

class TokenService
{
    private RefreshToken $refreshTokenModel;
    private int $refreshTtl;
    private array $cookieConfig;

    public function __construct()
    {
        $this->refreshTokenModel = new RefreshToken();
        $this->refreshTtl  = (int) env('JWT_REFRESH_TTL', 604800);
        $this->cookieConfig = [
            'name'     => env('REFRESH_COOKIE_NAME', 'refresh_token'),
            'httponly'  => true,
            'secure'   => (bool) env('REFRESH_COOKIE_SECURE', false),
            'samesite' => env('REFRESH_COOKIE_SAMESITE', 'Strict'),
            'path'     => '/',
        ];
    }

    /**
     * Generate a refresh token, store hashed in DB via Model, return raw token
     */
    public function createRefreshToken(int $userId, int $tenantId, ?string $family = null): string
    {
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $family    = $family ?? bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + $this->refreshTtl);

        $this->refreshTokenModel->create($userId, $tenantId, $tokenHash, $family, $expiresAt);

        return $rawToken;
    }

    /**
     * Validate refresh token using Model
     * Returns token record or null
     * Detects token reuse (potential theft) and revokes entire family
     */
    public function validateRefreshToken(string $rawToken): ?array
    {
        $tokenHash = hash('sha256', $rawToken);

        // Try to find a valid (non-revoked, non-expired) token
        $record = $this->refreshTokenModel->findValidByHash($tokenHash);

        if (!$record) {
            // Check if this is a REVOKED token — possible token theft!
            $revokedRecord = $this->refreshTokenModel->findRevokedByHash($tokenHash);

            if ($revokedRecord) {
                // SECURITY ALERT: Token reuse detected
                // Revoke the entire token family to protect the user
                $this->refreshTokenModel->revokeFamily($revokedRecord['family']);
                app_log(
                    "SECURITY: Refresh token reuse detected! Family revoked: {$revokedRecord['family']}",
                    'CRITICAL'
                );
            }

            return null;
        }

        // Check user is still active
        if ($record['user_status'] !== 'active') {
            return null;
        }

        return $record;
    }

    /**
     * Rotate refresh token: revoke old, create new in same family
     * Also regenerates CSRF token for security
     * 
     * Returns array with new refresh token and new CSRF token, or null on failure
     */
    public function rotateRefreshToken(string $oldRawToken, int $userId, int $tenantId): ?array
    {
        $oldHash = hash('sha256', $oldRawToken);
        $db = Database::getInstance();

        $db->beginTransaction();

        try {
            // Find old token via Model
            $oldRecord = $this->refreshTokenModel->findByHash($oldHash);

            if (!$oldRecord) {
                $db->rollBack();
                return null;
            }

            // Revoke old token via Model
            $this->refreshTokenModel->revokeById((int) $oldRecord['id']);

            // Create new token in same family
            $newRefreshToken = $this->createRefreshToken($userId, $tenantId, $oldRecord['family']);

            // Regenerate CSRF token during rotation
            $newCsrfToken = CsrfGuard::regenerate();

            $db->commit();

            app_log("Token rotated for user ID: {$userId}, family: {$oldRecord['family']}");

            return [
                'refresh_token' => $newRefreshToken,
                'csrf_token'    => $newCsrfToken,
            ];
        } catch (\Exception $e) {
            $db->rollBack();
            app_log('Token rotation failed: ' . $e->getMessage(), 'ERROR');
            return null;
        }
    }

    /**
     * Revoke all tokens in a family (security measure)
     */
    public function revokeTokenFamily(string $family): void
    {
        $this->refreshTokenModel->revokeFamily($family);
    }

    /**
     * Revoke all tokens for a user (logout from all devices)
     */
    public function revokeAllUserTokens(int $userId): void
    {
        $this->refreshTokenModel->revokeAllByUser($userId);
    }

    /**
     * Revoke a specific token by raw value
     */
    public function revokeToken(string $rawToken): void
    {
        $tokenHash = hash('sha256', $rawToken);
        $this->refreshTokenModel->revokeByHash($tokenHash);
    }

    /**
     * Cleanup expired and revoked tokens from DB
     */
    public function cleanupExpiredTokens(): int
    {
        return $this->refreshTokenModel->deleteExpiredAndRevoked();
    }

    /**
     * Set refresh token as HttpOnly cookie
     */
    public function setRefreshTokenCookie(string $rawToken): void
    {
        setcookie(
            $this->cookieConfig['name'],
            $rawToken,
            [
                'expires'  => time() + $this->refreshTtl,
                'path'     => $this->cookieConfig['path'],
                'httponly'  => $this->cookieConfig['httponly'],
                'secure'   => $this->cookieConfig['secure'],
                'samesite' => $this->cookieConfig['samesite'],
            ]
        );
    }

    /**
     * Clear refresh token cookie
     */
    public function clearRefreshTokenCookie(): void
    {
        setcookie(
            $this->cookieConfig['name'],
            '',
            [
                'expires'  => time() - 3600,
                'path'     => $this->cookieConfig['path'],
                'httponly'  => true,
                'samesite' => 'Strict',
            ]
        );
    }
}