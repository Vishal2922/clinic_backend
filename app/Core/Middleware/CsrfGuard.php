<?php

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;

class CsrfGuard
{
    public function handle(Request $request, Response $response, array $params = []): void
    {
        // Only validate for state-changing methods
        $method = $request->getMethod();
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return;
        }

        $headerToken = $request->getHeader('x-csrf-token');

        if (!$headerToken) {
            $response->error('CSRF token missing. Send X-CSRF-TOKEN header.', 403);
        }

        // Validate against session
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_expires'])) {
            $response->error('CSRF session expired. Call GET /api/auth/csrf-token first.', 403);
        }

        if ($_SESSION['csrf_token_expires'] < time()) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_expires']);
            $response->error('CSRF token expired. Call GET /api/auth/csrf-token to get a new one.', 403);
        }

        if (!hash_equals($_SESSION['csrf_token'], $headerToken)) {
            $response->error('CSRF token invalid.', 403);
        }

        // NOTE: We do NOT regenerate CSRF here anymore.
        // CSRF is only regenerated during:
        //   1. Login (AuthService::login)
        //   2. Token refresh/rotation (TokenService::rotateRefreshToken)
        //   3. Explicit call to GET /api/auth/csrf-token
        // This allows multiple CRUD operations with the same CSRF token
        // until it expires or a token rotation occurs.
    }

    /**
     * Generate a new CSRF token and store in PHP session
     */
    public static function generate(): string
    {
        $ttl   = (int) env('CSRF_TTL', 3600);
        $token = bin2hex(random_bytes(32));

        $_SESSION['csrf_token']         = $token;
        $_SESSION['csrf_token_expires'] = time() + $ttl;

        return $token;
    }

    /**
     * Regenerate CSRF token (alias for generate)
     */
    public static function regenerate(): string
    {
        return self::generate();
    }

    /**
     * Get current CSRF token without regenerating
     */
    public static function getToken(): ?string
    {
        // If expired, return null
        if (isset($_SESSION['csrf_token_expires']) && $_SESSION['csrf_token_expires'] < time()) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_expires']);
            return null;
        }

        return $_SESSION['csrf_token'] ?? null;
    }

    /**
     * Destroy CSRF token from session
     */
    public static function destroy(): void
    {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_expires']);
    }
}