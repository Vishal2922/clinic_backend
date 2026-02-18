<?php

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Security\JwtService;

class AuthJWT
{
    public function handle(Request $request, Response $response, array $params = []): void
    {
        $token = $request->getBearerToken();

        if (!$token) {
            $response->error('Access token required. Send Authorization: Bearer <token>', 401);
        }

        $jwt     = new JwtService();
        $payload = $jwt->verifyToken($token);

        if (!$payload) {
            $response->error('Invalid or expired access token. Please refresh.', 401);
        }

        // Verify tenant matches (if tenant middleware already ran)
        $tenantId = $request->getAttribute('tenant_id');
        if ($tenantId && isset($payload['tenant_id']) && (int) $payload['tenant_id'] !== $tenantId) {
            $response->error('Token does not belong to this tenant.', 403);
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