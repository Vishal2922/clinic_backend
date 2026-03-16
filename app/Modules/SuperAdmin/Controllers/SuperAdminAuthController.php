<?php

namespace App\Modules\SuperAdmin\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Modules\SuperAdmin\Services\SuperAdminAuthService;

class SuperAdminAuthController extends Controller
{
    private SuperAdminAuthService $authService;

    public function __construct()
    {
        $this->authService = new SuperAdminAuthService();
    }

    public function login(): void
    {
        $data = $this->request->getBody();

        $errors = $this->validate($data, [
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $result = $this->authService->login($data['email'], $data['password'], $ip);
            Response::json(['message' => 'Super admin login successful.', 'data' => $result]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 401);
        } catch (\Exception $e) {
            app_log('SuperAdmin login error: ' . $e->getMessage(), 'ERROR');
            Response::error('Login failed. Please try again.', 500);
        }
    }

    public function createAdmin(): void
    {
        $data = $this->request->getBody();

        if ($this->request->getAttribute('super_admin_role') !== 'superadmin') {
            Response::error('Only super admins with "superadmin" role can create other admins.', 403);
        }

        $errors = $this->validate($data, [
            'name'     => 'required|min:2|max:100',
            'email'    => 'required|email',
            'password' => 'required|min:10',
            'role'     => 'required',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $allowedRoles = ['superadmin', 'admin', 'support', 'billing'];
        if (!in_array($data['role'], $allowedRoles)) {
            Response::error('Invalid role. Allowed: ' . implode(', ', $allowedRoles), 422);
        }

        try {
            $result = $this->authService->createSuperAdmin($data, $this->request->getAttribute('super_admin_id'));
            Response::json(['message' => 'Super admin created successfully.', 'data' => $result], 201);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function me(): void
    {
        Response::json([
            'message' => 'Super admin profile retrieved.',
            'data'    => [
                'id'    => $this->request->getAttribute('super_admin_id'),
                'email' => $this->request->getAttribute('super_admin_email'),
                'name'  => $this->request->getAttribute('super_admin_name'),
                'role'  => $this->request->getAttribute('super_admin_role'),
            ],
        ]);
    }
}