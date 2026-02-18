<?php

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * CsrfGuard Middleware: Fixed Version.
 * Bug Fixed:
 * 1. $response->error() called as instance method — changed to Response::error() static call.
 */
class CsrfGuard
{
    public function handle(Request $request, Response $response, array $params = []): void
    {
        $method = $request->getMethod();
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return;
        }

        $headerToken = $request->getHeader('x-csrf-token');

        if (!$headerToken) {
            Response::error('CSRF token missing. Send X-CSRF-TOKEN header.', 403);
            return;
        }

        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_expires'])) {
            Response::error('CSRF session expired. Call GET /api/auth/csrf-token first.', 403);
            return;
        }

        if ($_SESSION['csrf_token_expires'] < time()) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_expires']);
            Response::error('CSRF token expired. Call GET /api/auth/csrf-token to get a new one.', 403);
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