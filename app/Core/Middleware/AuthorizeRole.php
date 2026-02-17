<?php

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;

class AuthorizeRole
{
    public function handle(Request $request, Response $response, array $params = []): void
    {
        $authUser = $request->getAttribute('auth_user');

        if (!$authUser) {
            $response->error('Authentication required', 401);
        }

        if (empty($params)) {
            return; // No specific roles required
        }

        $allowedRoles = $params;
        $userRole = $authUser['role_name'] ?? '';

        if (!in_array($userRole, $allowedRoles)) {
            $response->error(
                'Access denied. Required roles: ' . implode(', ', $allowedRoles),
                403
            );
        }
    }
}