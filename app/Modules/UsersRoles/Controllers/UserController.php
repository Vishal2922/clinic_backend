<?php

namespace App\Modules\UsersRoles\Controllers;

use App\Core\Controller;
use App\Modules\UsersRoles\Services\RbacService;

class UserController extends Controller
{
    private RbacService $rbacService;

    public function __construct()
    {
        $this->rbacService = new RbacService();
    }

    // ═══════════════════════════════════════════════════
    //  USER CRUD (Admin only)
    // ═══════════════════════════════════════════════════

    /**
     * GET /api/users
     */
    public function index(): void
    {
        $tenantId = $this->getTenantId();
        $page = (int) ($this->request->getQueryParam('page', 1));
        $perPage = (int) ($this->request->getQueryParam('per_page', 20));

        $filters = [
            'status' => $this->request->getQueryParam('status'),
            'role_id' => $this->request->getQueryParam('role_id'),
            'search' => $this->request->getQueryParam('search'),
        ];

        try {
            $result = $this->rbacService->listUsers($tenantId, $page, $perPage, $filters);
            $this->response->success($result, 'Users retrieved');
        } catch (\Exception $e) {
            app_log('List users error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to retrieve users', 500);
        }
    }

    /**
     * GET /api/users/{id}
     */
    public function show(string $id): void
    {
        $tenantId = $this->getTenantId();

        try {
            $user = $this->rbacService->getUser((int) $id, $tenantId);

            if (!$user) {
                $this->response->error('User not found', 404);
            }

            $this->response->success($user, 'User retrieved');
        } catch (\Exception $e) {
            app_log('Get user error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to retrieve user', 500);
        }
    }

    /**
     * POST /api/users
     */
    public function store(): void
    {
        $tenantId = $this->getTenantId();
        $data = $this->request->getBody();

        $errors = $this->validate($data, [
            'username' => 'required|min:3|max:50',
            'email' => 'required|email',
            'password' => 'required|min:8',
            'role_id' => 'required|numeric',
            'full_name' => 'required|min:2|max:100',
        ]);

        if (!empty($errors)) {
            $this->response->error('Validation failed', 422, $errors);
        }

        try {
            $user = $this->rbacService->createUser($data, $tenantId);
            $this->response->success($user, 'User created successfully', 201);
        } catch (\RuntimeException $e) {
            $this->response->error($e->getMessage(), 409);
        } catch (\Exception $e) {
            app_log('Create user error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to create user', 500);
        }
    }

    /**
     * PUT /api/users/{id}
     */
    public function update(string $id): void
    {
        $tenantId = $this->getTenantId();
        $data = $this->request->getBody();

        try {
            $user = $this->rbacService->updateUser((int) $id, $data, $tenantId);
            $this->response->success($user, 'User updated successfully');
        } catch (\RuntimeException $e) {
            $this->response->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Update user error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to update user', 500);
        }
    }

    /**
     * DELETE /api/users/{id}
     */
    public function destroy(string $id): void
    {
        $tenantId = $this->getTenantId();
        $authUser = $this->getAuthUser();

        try {
            $this->rbacService->deleteUser((int) $id, $tenantId, $authUser['user_id']);
            $this->response->success([], 'User deleted successfully');
        } catch (\RuntimeException $e) {
            $this->response->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Delete user error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to delete user', 500);
        }
    }

    // ═══════════════════════════════════════════════════
    //  ROLE MANAGEMENT (Admin only)
    // ═══════════════════════════════════════════════════

    /**
     * GET /api/roles
     */
    public function listRoles(): void
    {
        $tenantId = $this->getTenantId();

        try {
            $roles = $this->rbacService->listRoles($tenantId);
            $this->response->success(['roles' => $roles], 'Roles retrieved');
        } catch (\Exception $e) {
            app_log('List roles error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to retrieve roles', 500);
        }
    }

    /**
     * GET /api/roles/{id}
     */
    public function showRole(string $id): void
    {
        $tenantId = $this->getTenantId();

        try {
            $role = $this->rbacService->getRole((int) $id, $tenantId);

            if (!$role) {
                $this->response->error('Role not found', 404);
            }

            $this->response->success($role, 'Role retrieved');
        } catch (\Exception $e) {
            app_log('Get role error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to retrieve role', 500);
        }
    }

    /**
     * POST /api/roles
     */
    public function createRole(): void
    {
        $tenantId = $this->getTenantId();
        $data = $this->request->getBody();

        $errors = $this->validate($data, [
            'role_name' => 'required|min:2|max:50',
        ]);

        if (!empty($errors)) {
            $this->response->error('Validation failed', 422, $errors);
        }

        try {
            $role = $this->rbacService->createRole(
                sanitize($data['role_name']),
                sanitize($data['description'] ?? ''),
                $tenantId,
                $data['permission_ids'] ?? []
            );
            $this->response->success($role, 'Role created successfully', 201);
        } catch (\RuntimeException $e) {
            $this->response->error($e->getMessage(), 409);
        } catch (\Exception $e) {
            app_log('Create role error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to create role', 500);
        }
    }

    /**
     * PUT /api/roles/{id}
     */
    public function updateRole(string $id): void
    {
        $tenantId = $this->getTenantId();
        $data = $this->request->getBody();

        $errors = $this->validate($data, [
            'role_name' => 'required|min:2|max:50',
        ]);

        if (!empty($errors)) {
            $this->response->error('Validation failed', 422, $errors);
        }

        try {
            $role = $this->rbacService->updateRole(
                (int) $id,
                sanitize($data['role_name']),
                sanitize($data['description'] ?? ''),
                $tenantId
            );
            $this->response->success($role, 'Role updated successfully');
        } catch (\RuntimeException $e) {
            $this->response->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Update role error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to update role', 500);
        }
    }

    /**
     * DELETE /api/roles/{id}
     */
    public function deleteRole(string $id): void
    {
        $tenantId = $this->getTenantId();

        try {
            $this->rbacService->deleteRole((int) $id, $tenantId);
            $this->response->success([], 'Role deleted successfully');
        } catch (\RuntimeException $e) {
            $this->response->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Delete role error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to delete role', 500);
        }
    }

    /**
     * PUT /api/roles/{id}/permissions
     */
    public function assignPermissions(string $id): void
    {
        $tenantId = $this->getTenantId();
        $data = $this->request->getBody();

        if (!isset($data['permission_ids']) || !is_array($data['permission_ids'])) {
            $this->response->error('permission_ids array is required', 422);
        }

        try {
            $role = $this->rbacService->assignPermissions(
                (int) $id,
                $data['permission_ids'],
                $tenantId
            );
            $this->response->success($role, 'Permissions assigned successfully');
        } catch (\RuntimeException $e) {
            $this->response->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Assign permissions error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to assign permissions', 500);
        }
    }

    // ═══════════════════════════════════════════════════
    //  PERMISSIONS LIST
    // ═══════════════════════════════════════════════════

    /**
     * GET /api/permissions
     */
    public function listPermissions(): void
    {
        try {
            $permissions = $this->rbacService->listPermissions();
            $this->response->success(['permissions' => $permissions], 'Permissions retrieved');
        } catch (\Exception $e) {
            app_log('List permissions error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to retrieve permissions', 500);
        }
    }

    // ═══════════════════════════════════════════════════
    //  PROFILE (Authenticated user, any role)
    // ═══════════════════════════════════════════════════

    /**
     * GET /api/profile
     */
    public function getProfile(): void
    {
        $authUser = $this->getAuthUser();
        $tenantId = $this->getTenantId();

        try {
            $profile = $this->rbacService->getProfile($authUser['user_id'], $tenantId);

            if (!$profile) {
                $this->response->error('Profile not found', 404);
            }

            $this->response->success($profile, 'Profile retrieved');
        } catch (\Exception $e) {
            app_log('Get profile error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to retrieve profile', 500);
        }
    }

    /**
     * PUT /api/profile
     */
    public function updateProfile(): void
    {
        $authUser = $this->getAuthUser();
        $tenantId = $this->getTenantId();
        $data = $this->request->getBody();

        try {
            $profile = $this->rbacService->updateProfile($authUser['user_id'], $data, $tenantId);
            $this->response->success($profile, 'Profile updated successfully');
        } catch (\RuntimeException $e) {
            $this->response->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Update profile error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to update profile', 500);
        }
    }
}