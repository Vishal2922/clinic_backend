<?php

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;


class CsrfGuard
{
    public function handle(Request $request, Response $response, array $params = []): void
    {
        $method = $request->getMethod();

        // Determine if this instance enforces CSRF on ALL methods or only write methods.
        // Pass 'all' as param to enforce on GET too:  CsrfGuard::class . ':all'
        $enforceOnGet = in_array('all', $params, true);

        // Skip CSRF check for safe methods UNLESS enforceOnGet is requested
        if (!$enforceOnGet && in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return;
        }

        // OPTIONS never need CSRF (pre-flight — no credentials)
        if ($method === 'OPTIONS') {
            return;
        }

        $this->validateToken($request);
    }

    private function validateToken(Request $request): void
    {
        $headerToken = $request->getHeader('x-csrf-token');

        if (!$headerToken) {
            Response::error(
                'CSRF token missing. Send X-CSRF-TOKEN header. ' .
                'Obtain token from GET /api/auth/csrf-token first.',
                403
            );
            return;
        }

        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_expires'])) {
            Response::error(
                'CSRF session not initialised. Call GET /api/auth/csrf-token first.',
                403
            );
            return;
        }

        if ($_SESSION['csrf_token_expires'] < time()) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_expires']);
            Response::error(
                'CSRF token expired. Call GET /api/auth/csrf-token to obtain a new one.',
                403
            );
            return;
        }

        if (!hash_equals($_SESSION['csrf_token'], $headerToken)) {
            Response::error('CSRF token invalid.', 403);
            return;
        }
    }

    public static function generate(): string
    {
        $ttl   = (int) env('CSRF_TTL', 3600);
        $token = bin2hex(random_bytes(32));

        $_SESSION['csrf_token']         = $token;
        $_SESSION['csrf_token_expires'] = time() + $ttl;

        return $token;
    }

    public static function regenerate(): string
    {
        return self::generate();
    }

   
    public static function getToken(): ?string
    {
        if (isset($_SESSION['csrf_token_expires']) && $_SESSION['csrf_token_expires'] < time()) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_expires']);
            return null;
        }

        return $_SESSION['csrf_token'] ?? null;
    }

    public static function destroy(): void
    {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_expires']);
    }
}