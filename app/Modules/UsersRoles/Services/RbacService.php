<?php

namespace App\Modules\UsersRoles\Services;

use App\Modules\UsersRoles\Models\User;
use App\Modules\UsersRoles\Models\Role;
use App\Modules\UsersRoles\Models\Permission;

class RbacService
{
    private User $userModel;
    private Role $roleModel;
    private Permission $permissionModel;

    public function __construct()
    {
        $this->userModel = new User();
        $this->roleModel = new Role();
        $this->permissionModel = new Permission();
    }

    // ─── USER OPERATIONS ─────────────────────────────

    public function getUser(int $id, int $tenantId): ?array
    {
        return $this->userModel->findById($id, $tenantId);
    }

    public function listUsers(int $tenantId, int $page, int $perPage, array $filters = []): array
    {
        return $this->userModel->getAllByTenant($tenantId, $page, $perPage, $filters);
    }

    public function createUser(array $data, int $tenantId): array
    {
        // Validate username uniqueness
        if ($this->userModel->usernameExists($data['username'], $tenantId)) {
            throw new \RuntimeException('Username already exists');
        }

        // Validate email uniqueness
        if ($this->userModel->emailExists($data['email'], $tenantId)) {
            throw new \RuntimeException('Email already exists');
        }

        // Validate role belongs to tenant
        $role = $this->roleModel->findById($data['role_id'], $tenantId);
        if (!$role) {
            throw new \RuntimeException('Invalid role for this tenant');
        }

        $userId = $this->userModel->create($data, $tenantId);

        return $this->userModel->findById($userId, $tenantId);
    }

    public function updateUser(int $id, array $data, int $tenantId): array
    {
        // Check user exists
        $existing = $this->userModel->findById($id, $tenantId);
        if (!$existing) {
            throw new \RuntimeException('User not found');
        }

        // Validate email uniqueness if changing
        if (isset($data['email'])) {
            if ($this->userModel->emailExists($data['email'], $tenantId, $id)) {
                throw new \RuntimeException('Email already exists');
            }
        }

        // Validate role if changing
        if (isset($data['role_id'])) {
            $role = $this->roleModel->findById($data['role_id'], $tenantId);
            if (!$role) {
                throw new \RuntimeException('Invalid role for this tenant');
            }
        }

        $updated = $this->userModel->update($id, $data, $tenantId);

        if (!$updated) {
            throw new \RuntimeException('No changes made');
        }

        return $this->userModel->findById($id, $tenantId);
    }

    public function deleteUser(int $id, int $tenantId, int $currentUserId): bool
    {
        if ($id === $currentUserId) {
            throw new \RuntimeException('Cannot delete your own account');
        }

        $existing = $this->userModel->findById($id, $tenantId);
        if (!$existing) {
            throw new \RuntimeException('User not found');
        }

        return $this->userModel->softDelete($id, $tenantId);
    }

    // ─── ROLE OPERATIONS ─────────────────────────────

    public function listRoles(int $tenantId): array
    {
        $roles = $this->roleModel->getAllByTenant($tenantId);

        // Attach permissions to each role
        foreach ($roles as &$role) {
            $role['permissions'] = $this->permissionModel->getByRoleId((int) $role['id']);
        }

        return $roles;
    }

    public function getRole(int $roleId, int $tenantId): ?array
    {
        $role = $this->roleModel->findById($roleId, $tenantId);
        if ($role) {
            $role['permissions'] = $this->permissionModel->getByRoleId($roleId);
        }
        return $role;
    }

    public function createRole(string $roleName, string $description, int $tenantId, array $permissionIds = []): array
    {
        if ($this->roleModel->roleNameExists($roleName, $tenantId)) {
            throw new \RuntimeException('Role name already exists in this tenant');
        }

        // Validate all permission IDs exist
        if (!empty($permissionIds)) {
            foreach ($permissionIds as $pid) {
                if (!$this->permissionModel->findById($pid)) {
                    throw new \RuntimeException("Invalid permission ID: {$pid}");
                }
            }
        }

        $roleId = $this->roleModel->create($roleName, $description, $tenantId);

        // Assign permissions
        if (!empty($permissionIds)) {
            $this->permissionModel->assignToRole($roleId, $permissionIds);
        }

        return $this->getRole($roleId, $tenantId);
    }

    public function updateRole(int $roleId, string $roleName, string $description, int $tenantId): array
    {
        $existing = $this->roleModel->findById($roleId, $tenantId);
        if (!$existing) {
            throw new \RuntimeException('Role not found');
        }

        if ($this->roleModel->roleNameExists($roleName, $tenantId, $roleId)) {
            throw new \RuntimeException('Role name already exists');
        }

        $this->roleModel->update($roleId, $roleName, $description, $tenantId);

        return $this->getRole($roleId, $tenantId);
    }

    public function deleteRole(int $roleId, int $tenantId): bool
    {
        $existing = $this->roleModel->findById($roleId, $tenantId);
        if (!$existing) {
            throw new \RuntimeException('Role not found');
        }

        return $this->roleModel->delete($roleId, $tenantId);
    }

    public function assignPermissions(int $roleId, array $permissionIds, int $tenantId): array
    {
        $role = $this->roleModel->findById($roleId, $tenantId);
        if (!$role) {
            throw new \RuntimeException('Role not found');
        }

        // Validate all permission IDs
        foreach ($permissionIds as $pid) {
            if (!$this->permissionModel->findById($pid)) {
                throw new \RuntimeException("Invalid permission ID: {$pid}");
            }
        }

        $this->permissionModel->assignToRole($roleId, $permissionIds);

        return $this->getRole($roleId, $tenantId);
    }

    // ─── PERMISSION OPERATIONS ────────────────────────

    public function listPermissions(): array
    {
        return $this->permissionModel->getAll();
    }

    // ─── PROFILE OPERATIONS ──────────────────────────

    public function getProfile(int $userId, int $tenantId): ?array
    {
        return $this->userModel->findById($userId, $tenantId);
    }

    public function updateProfile(int $userId, array $data, int $tenantId): array
    {
        // Users can only update their own profile fields (not role or status)
        $allowedFields = ['email', 'full_name', 'phone'];
        $filtered = array_intersect_key($data, array_flip($allowedFields));

        if (empty($filtered)) {
            throw new \RuntimeException('No valid fields to update');
        }

        if (isset($filtered['email'])) {
            if ($this->userModel->emailExists($filtered['email'], $tenantId, $userId)) {
                throw new \RuntimeException('Email already exists');
            }
        }

        $this->userModel->update($userId, $filtered, $tenantId);

        return $this->userModel->findById($userId, $tenantId);
    }
}