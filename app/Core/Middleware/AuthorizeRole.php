<?php

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * AuthorizeRole Middleware: Fixed Version.
 * Bug Fixed:
 * 1. $response->error() called as instance method — changed to Response::error() static call.
 */
class AuthorizeRole
{
    public function handle(Request $request, Response $response, array $params = []): void
    {
        $authUser = $request->getAttribute('auth_user');

        if (!$authUser) {
            Response::error('Authentication required', 401);
            return;
        }

        if (empty($params)) {
            return; // No specific roles required
        }

        $allowedRoles = $params;
        $userRole     = $authUser['role_name'] ?? '';

        if (!in_array($userRole, $allowedRoles)) {
            Response::error(
                'Access denied. Required roles: ' . implode(', ', $allowedRoles),
                403
            );
        }
    }
}