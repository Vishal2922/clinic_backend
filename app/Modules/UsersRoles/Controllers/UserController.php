<?php

namespace App\Modules\UsersRoles\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Modules\UsersRoles\Services\RbacService;

/**
 * UserController: Fixed Version.
 * Bugs Fixed:
 * 1. All $this->response->success() and $this->response->error() calls target instance methods
 *    that don't exist in the original Response class (they are static). Fixed to use Response:: static calls.
 * 2. $this->request->getQueryParam() was missing in Request class (fixed there).
 *    Here the calls remain as-is since Request is now fixed.
 */
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

    public function index(): void
    {
        $tenantId = $this->getTenantId();
        $page     = (int) ($this->request->getQueryParam('page', 1));
        $perPage  = (int) ($this->request->getQueryParam('per_page', 20));

        $filters = [
            'status'  => $this->request->getQueryParam('status'),
            'role_id' => $this->request->getQueryParam('role_id'),
            'search'  => $this->request->getQueryParam('search'),
        ];

        try {
            $result = $this->rbacService->listUsers($tenantId, $page, $perPage, $filters);
            Response::json(['message' => 'Users retrieved', 'data' => $result], 200);
        } catch (\Exception $e) {
            app_log('List users error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve users', 500);
        }
    }

    public function show(string $id): void
    {
        $tenantId = $this->getTenantId();

        try {
            $user = $this->rbacService->getUser((int) $id, $tenantId);

            if (!$user) {
                Response::error('User not found', 404);
            }

            Response::json(['message' => 'User retrieved', 'data' => $user], 200);
        } catch (\Exception $e) {
            app_log('Get user error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve user', 500);
        }
    }

    public function store(): void
    {
        $tenantId = $this->getTenantId();
        $data     = $this->request->getBody();

        $errors = $this->validate($data, [
            'username'  => 'required|min:3|max:50',
            'email'     => 'required|email',
            'password'  => 'required|min:8',
            'role_id'   => 'required|numeric',
            'full_name' => 'required|min:2|max:100',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $user = $this->rbacService->createUser($data, $tenantId);
            Response::json(['message' => 'User created successfully', 'data' => $user], 201);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 409);
        } catch (\Exception $e) {
            app_log('Create user error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to create user', 500);
        }
    }

    public function update(string $id): void
    {
        $tenantId = $this->getTenantId();
        $data     = $this->request->getBody();

        try {
            $user = $this->rbacService->updateUser((int) $id, $data, $tenantId);
            Response::json(['message' => 'User updated successfully', 'data' => $user], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Update user error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to update user', 500);
        }
    }

    public function destroy(string $id): void
    {
        $tenantId = $this->getTenantId();
        $authUser = $this->getAuthUser();

        try {
            $this->rbacService->deleteUser((int) $id, $tenantId, $authUser['user_id']);
            Response::json(['message' => 'User deleted successfully', 'data' => []], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Delete user error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to delete user', 500);
        }
    }

    // ═══════════════════════════════════════════════════
    //  ROLE MANAGEMENT
    // ═══════════════════════════════════════════════════

    public function listRoles(): void
    {
        $tenantId = $this->getTenantId();

        try {
            $roles = $this->rbacService->listRoles($tenantId);
            Response::json(['message' => 'Roles retrieved', 'data' => ['roles' => $roles]], 200);
        } catch (\Exception $e) {
            app_log('List roles error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve roles', 500);
        }
    }

    public function showRole(string $id): void
    {
        $tenantId = $this->getTenantId();

        try {
            $role = $this->rbacService->getRole((int) $id, $tenantId);

            if (!$role) {
                Response::error('Role not found', 404);
            }

            Response::json(['message' => 'Role retrieved', 'data' => $role], 200);
        } catch (\Exception $e) {
            app_log('Get role error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve role', 500);
        }
    }

    public function createRole(): void
    {
        $tenantId = $this->getTenantId();
        $data     = $this->request->getBody();

        $errors = $this->validate($data, [
            'role_name' => 'required|min:2|max:50',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $role = $this->rbacService->createRole(
                sanitize($data['role_name']),
                sanitize($data['description'] ?? ''),
                $tenantId,
                $data['permission_ids'] ?? []
            );
            Response::json(['message' => 'Role created successfully', 'data' => $role], 201);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 409);
        } catch (\Exception $e) {
            app_log('Create role error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to create role', 500);
        }
    }

    public function updateRole(string $id): void
    {
        $tenantId = $this->getTenantId();
        $data     = $this->request->getBody();

        $errors = $this->validate($data, [
            'role_name' => 'required|min:2|max:50',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $role = $this->rbacService->updateRole(
                (int) $id,
                sanitize($data['role_name']),
                sanitize($data['description'] ?? ''),
                $tenantId
            );
            Response::json(['message' => 'Role updated successfully', 'data' => $role], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Update role error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to update role', 500);
        }
    }

    public function deleteRole(string $id): void
    {
        $tenantId = $this->getTenantId();

        try {
            $this->rbacService->deleteRole((int) $id, $tenantId);
            Response::json(['message' => 'Role deleted successfully', 'data' => []], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Delete role error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to delete role', 500);
        }
    }

    public function assignPermissions(string $id): void
    {
        $tenantId = $this->getTenantId();
        $data     = $this->request->getBody();

        if (!isset($data['permission_ids']) || !is_array($data['permission_ids'])) {
            Response::error('permission_ids array is required', 422);
        }

        try {
            $role = $this->rbacService->assignPermissions(
                (int) $id,
                $data['permission_ids'],
                $tenantId
            );
            Response::json(['message' => 'Permissions assigned successfully', 'data' => $role], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Assign permissions error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to assign permissions', 500);
        }
    }

    public function listPermissions(): void
    {
        try {
            $permissions = $this->rbacService->listPermissions();
            Response::json(['message' => 'Permissions retrieved', 'data' => ['permissions' => $permissions]], 200);
        } catch (\Exception $e) {
            app_log('List permissions error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve permissions', 500);
        }
    }

    // ═══════════════════════════════════════════════════
    //  PROFILE
    // ═══════════════════════════════════════════════════

    public function getProfile(): void
    {
        $authUser = $this->getAuthUser();
        $tenantId = $this->getTenantId();

        try {
            $profile = $this->rbacService->getProfile($authUser['user_id'], $tenantId);

            if (!$profile) {
                Response::error('Profile not found', 404);
            }

            Response::json(['message' => 'Profile retrieved', 'data' => $profile], 200);
        } catch (\Exception $e) {
            app_log('Get profile error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve profile', 500);
        }
    }

    public function updateProfile(): void
    {
        $authUser = $this->getAuthUser();
        $tenantId = $this->getTenantId();
        $data     = $this->request->getBody();

        try {
            $profile = $this->rbacService->updateProfile($authUser['user_id'], $data, $tenantId);
            Response::json(['message' => 'Profile updated successfully', 'data' => $profile], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Update profile error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to update profile', 500);
        }
    }
}