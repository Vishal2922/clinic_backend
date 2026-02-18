<?php

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Security\JwtService;

/**
 * AuthJWT Middleware: Fixed Version.
 * Bug Fixed:
 * 1. $response->error() was called as an instance method, but Response::error() is static.
 *    In PHP you can call static methods on instances, BUT since error() calls exit, it works —
 *    however it's semantically incorrect. Changed to Response::error() for clarity and correctness.
 * 2. After calling $response->error() the code continued (no return). Static exit handles it,
 *    but explicit returns added for clarity.
 */
class AuthJWT
{
    public function handle(Request $request, Response $response, array $params = []): void
    {
        $token = $request->getBearerToken();

        if (!$token) {
            Response::error('Access token required. Send Authorization: Bearer <token>', 401);
            return; // Never reached due to exit in error(), but explicit for clarity
        }

        $jwt     = new JwtService();
        $payload = $jwt->verifyToken($token);

        if (!$payload) {
            Response::error('Invalid or expired access token. Please refresh.', 401);
            return;
        }

        // Verify tenant matches (if tenant middleware already ran)
        $tenantId = $request->getAttribute('tenant_id');
        if ($tenantId && isset($payload['tenant_id']) && (int) $payload['tenant_id'] !== (int) $tenantId) {
            Response::error('Token does not belong to this tenant.', 403);
            return;
        }

        // Set full auth user data including role and permissions
        $request->setAttribute('auth_user', [
            'user_id'     => (int) $payload['sub'],
            'tenant_id'   => (int) $payload['tenant_id'],
            'role_id'     => (int) $payload['role_id'],
            'role_name'   => $payload['role_name'],
            'username'    => $payload['username'],
            'permissions' => $payload['permissions'] ?? [],
        ]);
    }
}