<?php

namespace App\Core\Security;

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

    public function createRefreshToken(int $userId, int $tenantId, ?string $family = null): string
    {
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $family    = $family ?? bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + $this->refreshTtl);

        $this->refreshTokenModel->create($userId, $tenantId, $tokenHash, $family, $expiresAt);

        return $rawToken;
    }

    public function validateRefreshToken(string $rawToken): ?array
    {
        $tokenHash = hash('sha256', $rawToken);

        $record = $this->refreshTokenModel->findValidByHash($tokenHash);

        if (!$record) {
            $revokedRecord = $this->refreshTokenModel->findRevokedByHash($tokenHash);

            if ($revokedRecord) {
                $this->refreshTokenModel->revokeFamily($revokedRecord['family']);
                app_log(
                    "SECURITY: Refresh token reuse detected! Family revoked: {$revokedRecord['family']}",
                    'CRITICAL'
                );
            }

            return null;
        }

        if ($record['user_status'] !== 'active') {
            return null;
        }

        return $record;
    }

    public function rotateRefreshToken(string $oldRawToken, int $userId, int $tenantId): ?array
    {
        $oldHash = hash('sha256', $oldRawToken);
        $db = tenant_db();

        $db->beginTransaction();

        try {
            $oldRecord = $this->refreshTokenModel->findByHash($oldHash);

            if (!$oldRecord) {
                $db->rollBack();
                return null;
            }

            $this->refreshTokenModel->revokeById((int) $oldRecord['id']);

            $newRefreshToken = $this->createRefreshToken($userId, $tenantId, $oldRecord['family']);

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

    public function revokeTokenFamily(string $family): void
    {
        $this->refreshTokenModel->revokeFamily($family);
    }

    public function revokeAllUserTokens(int $userId): void
    {
        $this->refreshTokenModel->revokeAllByUser($userId);
    }

    public function revokeToken(string $rawToken): void
    {
        $tokenHash = hash('sha256', $rawToken);
        $this->refreshTokenModel->revokeByHash($tokenHash);
    }

    public function cleanupExpiredTokens(): int
    {
        return $this->refreshTokenModel->deleteExpiredAndRevoked();
    }

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