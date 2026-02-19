<?php

namespace App\Modules\Staff\Services;

use App\Modules\Staff\Models\Staff;
use App\Modules\UsersRoles\Models\User;
use App\Modules\UsersRoles\Models\Role;
use App\Core\Security\CryptoService;

/**
 * StaffService
 * Business logic for staff management.
 * Handles creation (linking to users), updates, role assignment, and deletion.
 */
class StaffService
{
    private Staff $staffModel;
    private User $userModel;
    private Role $roleModel;
    private CryptoService $crypto;

    public function __construct()
    {
        $this->staffModel = new Staff();
        $this->userModel  = new User();
        $this->roleModel  = new Role();
        $this->crypto     = new CryptoService();
    }

    /**
     * List all staff with pagination and filters.
     * Decrypts user PII fields for response.
     */
    public function listStaff(int $tenantId, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $result = $this->staffModel->getAllByTenant($tenantId, $page, $perPage, $filters);

        // Decrypt user data for each staff member
        $result['staff'] = array_map([$this, 'decryptStaffData'], $result['staff']);

        return $result;
    }

    /**
     * Get a single staff record by ID.
     */
    public function getStaff(int $id, int $tenantId): ?array
    {
        $staff = $this->staffModel->findById($id, $tenantId);
        if ($staff) {
            $staff = $this->decryptStaffData($staff);
        }
        return $staff;
    }

    /**
     * Create a new staff record linked to an existing user.
     * Validates that:
     *  - The user exists in the same tenant
     *  - The user doesn't already have a staff record
     *  - The user has a staff-eligible role (not Patient)
     */
    public function createStaff(array $data, int $tenantId): array
    {
        $userId = (int) $data['user_id'];

        // Verify user exists in this tenant
        $user = $this->userModel->findById($userId, $tenantId);
        if (!$user) {
            throw new \RuntimeException('User not found in this tenant');
        }

        // Check the user's role is staff-eligible
        $nonStaffRoles = ['Patient'];
        if (in_array($user['role_name'] ?? '', $nonStaffRoles, true)) {
            throw new \RuntimeException('Patients cannot be added as staff. Change role first.');
        }

        // Check for duplicate staff record
        if ($this->staffModel->existsForUser($userId, $tenantId)) {
            throw new \RuntimeException('This user already has a staff record');
        }

        // Optionally update the user's role if provided
        if (!empty($data['role_id'])) {
            $role = $this->roleModel->findById((int) $data['role_id'], $tenantId);
            if (!$role) {
                throw new \RuntimeException('Invalid role for this tenant');
            }
            $this->userModel->update($userId, ['role_id' => (int) $data['role_id']], $tenantId);
        }

        $data['tenant_id'] = $tenantId;
        $staffId = $this->staffModel->create($data);

        app_log("Staff created: ID {$staffId} for user {$userId} in tenant {$tenantId}");

        return $this->getStaff($staffId, $tenantId);
    }

    /**
     * Update a staff record.
     * Allows updating staff-specific fields and optionally the user's role.
     */
    public function updateStaff(int $id, array $data, int $tenantId): array
    {
        $staff = $this->staffModel->findById($id, $tenantId);
        if (!$staff) {
            throw new \RuntimeException('Staff record not found');
        }

        // If role_id is provided, update the linked user's role
        if (!empty($data['role_id'])) {
            $role = $this->roleModel->findById((int) $data['role_id'], $tenantId);
            if (!$role) {
                throw new \RuntimeException('Invalid role for this tenant');
            }
            $this->userModel->update((int) $staff['user_id'], ['role_id' => (int) $data['role_id']], $tenantId);
            unset($data['role_id']); // Don't pass to staff update
        }

        // If user status change is requested, propagate to users table
        if (isset($data['user_status'])) {
            $this->userModel->update(
                (int) $staff['user_id'],
                ['status' => $data['user_status']],
                $tenantId
            );
            unset($data['user_status']);
        }

        // Update staff-specific fields
        if (!empty($data)) {
            $this->staffModel->update($id, $tenantId, $data);
        }

        app_log("Staff updated: ID {$id} in tenant {$tenantId}");

        return $this->getStaff($id, $tenantId);
    }

    /**
     * Soft-delete a staff record and deactivate the linked user.
     */
    public function deleteStaff(int $id, int $tenantId): bool
    {
        $staff = $this->staffModel->findById($id, $tenantId);
        if (!$staff) {
            throw new \RuntimeException('Staff record not found');
        }

        // Deactivate the linked user account
        $this->userModel->update((int) $staff['user_id'], ['status' => 'inactive'], $tenantId);

        $result = $this->staffModel->softDelete($id, $tenantId);

        app_log("Staff deleted: ID {$id}, user {$staff['user_id']} deactivated in tenant {$tenantId}");

        return $result;
    }

    /**
     * Get available departments for a tenant.
     */
    public function getDepartments(int $tenantId): array
    {
        return $this->staffModel->getDepartments($tenantId);
    }

    /**
     * Decrypt encrypted PII fields from the joined user data.
     */
    private function decryptStaffData(array $staff): array
    {
        try {
            if (!empty($staff['encrypted_email'])) {
                $staff['email'] = $this->crypto->decrypt($staff['encrypted_email']);
            }
            if (!empty($staff['encrypted_full_name'])) {
                $staff['full_name'] = $this->crypto->decrypt($staff['encrypted_full_name']);
            }
            if (!empty($staff['encrypted_phone'])) {
                $staff['phone'] = $this->crypto->decrypt($staff['encrypted_phone']);
            }
        } catch (\Exception $e) {
            app_log(
                'Decryption error for staff ' . ($staff['id'] ?? 'unknown') . ': ' . $e->getMessage(),
                'ERROR'
            );
        }

        // Remove encrypted fields from response
        unset(
            $staff['encrypted_email'],
            $staff['encrypted_full_name'],
            $staff['encrypted_phone']
        );

        return $staff;
    }
}