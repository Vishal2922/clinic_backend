<?php

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\MasterDatabase;
use App\Core\Security\JwtService;

class AuthSuperAdmin
{
    public function handle(Request $request, Response $response, array $params = []): void
    {
        $authHeader = $request->getHeader('authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            Response::error('Super admin authorization token required.', 401);
            return;
        }

        $token = substr($authHeader, 7);

        try {
            $jwtService = new JwtService();
            $payload    = $jwtService->validateToken($token);
        } catch (\Exception $e) {
            Response::error('Invalid or expired super admin token.', 401);
            return;
        }

        if (($payload['scope'] ?? '') !== 'super_admin') {
            Response::error('Insufficient privileges. Super admin access required.', 403);
            return;
        }

        $masterDb   = MasterDatabase::getInstance();
        $superAdmin = $masterDb->fetch(
            'SELECT id, email, name, role, is_active
             FROM super_admins
             WHERE id = :id AND is_active = 1',
            ['id' => $payload['sub']]
        );

        if (!$superAdmin) {
            Response::error('Super admin account not found or deactivated.', 401);
            return;
        }

        $masterDb->execute(
            'UPDATE super_admins SET last_activity_at = NOW() WHERE id = :id',
            ['id' => $superAdmin['id']]
        );

        $request->setAttribute('super_admin_id',    (int) $superAdmin['id']);
        $request->setAttribute('super_admin_email', $superAdmin['email']);
        $request->setAttribute('super_admin_name',  $superAdmin['name']);
        $request->setAttribute('super_admin_role',  $superAdmin['role']);
        $request->setAttribute('is_super_admin',    true);
    }
}