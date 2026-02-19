<?php

namespace App\Modules\Staff\Models;

use App\Core\Database;

/**
 * Staff Model
 * Manages staff records linked to users table.
 * Provides tenant-isolated CRUD with soft delete support.
 */
class Staff
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find a staff record by its own ID within a tenant.
     */
    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db->fetch(
            'SELECT s.*, u.username, u.encrypted_email, u.encrypted_full_name,
                    u.encrypted_phone, u.status AS user_status, r.role_name
             FROM staff s
             JOIN users u ON s.user_id = u.id
             JOIN roles r ON u.role_id = r.id
             WHERE s.id = :id AND s.tenant_id = :tid AND s.deleted_at IS NULL
               AND u.deleted_at IS NULL',
            ['id' => $id, 'tid' => $tenantId]
        );
    }

    /**
     * Find a staff record by user_id within a tenant.
     */
    public function findByUserId(int $userId, int $tenantId): ?array
    {
        return $this->db->fetch(
            'SELECT s.*, u.username, u.encrypted_email, u.encrypted_full_name,
                    u.encrypted_phone, u.status AS user_status, r.role_name
             FROM staff s
             JOIN users u ON s.user_id = u.id
             JOIN roles r ON u.role_id = r.id
             WHERE s.user_id = :uid AND s.tenant_id = :tid AND s.deleted_at IS NULL
               AND u.deleted_at IS NULL',
            ['uid' => $userId, 'tid' => $tenantId]
        );
    }

    /**
     * Get all staff for a tenant with pagination and filters.
     */
    public function getAllByTenant(
        int $tenantId,
        int $page = 1,
        int $perPage = 20,
        array $filters = []
    ): array {
        $offset = ($page - 1) * $perPage;
        $where  = 's.tenant_id = :tid AND s.deleted_at IS NULL AND u.deleted_at IS NULL';
        $params = ['tid' => $tenantId];

        // Filter by staff status
        if (!empty($filters['status'])) {
            $where .= ' AND s.status = :status';
            $params['status'] = $filters['status'];
        }

        // Filter by role
        if (!empty($filters['role_id'])) {
            $where .= ' AND u.role_id = :role_id';
            $params['role_id'] = $filters['role_id'];
        }

        // Filter by department
        if (!empty($filters['department'])) {
            $where .= ' AND s.department = :department';
            $params['department'] = $filters['department'];
        }

        // Search by username or specialization
        if (!empty($filters['search'])) {
            $where .= ' AND (u.username LIKE :search OR s.specialization LIKE :search2)';
            $params['search']  = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
        }

        $countResult = $this->db->fetch(
            "SELECT COUNT(*) AS total
             FROM staff s
             JOIN users u ON s.user_id = u.id
             WHERE $where",
            $params
        );
        $total = (int) ($countResult['total'] ?? 0);

        $staff = $this->db->fetchAll(
            "SELECT s.*, u.username, u.encrypted_email, u.encrypted_full_name,
                    u.encrypted_phone, u.status AS user_status, r.role_name
             FROM staff s
             JOIN users u ON s.user_id = u.id
             JOIN roles r ON u.role_id = r.id
             WHERE $where
             ORDER BY s.created_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        return [
            'staff'      => $staff,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }

    /**
     * Create a new staff record.
     */
    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO staff (user_id, tenant_id, department, specialization,
                                license_number, hire_date, status, notes, created_at, updated_at)
             VALUES (:user_id, :tenant_id, :department, :specialization,
                     :license_number, :hire_date, :status, :notes, NOW(), NOW())',
            [
                'user_id'        => $data['user_id'],
                'tenant_id'      => $data['tenant_id'],
                'department'     => $data['department'] ?? null,
                'specialization' => $data['specialization'] ?? null,
                'license_number' => $data['license_number'] ?? null,
                'hire_date'      => $data['hire_date'] ?? null,
                'status'         => $data['status'] ?? 'active',
                'notes'          => $data['notes'] ?? null,
            ]
        );
    }

    /**
     * Update staff fields dynamically.
     */
    public function update(int $id, int $tenantId, array $data): bool
    {
        $sets   = [];
        $params = ['id' => $id, 'tid' => $tenantId];

        $allowedFields = [
            'department', 'specialization', 'license_number',
            'hire_date', 'status', 'notes',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[]          = "$field = :$field";
                $params[$field]  = $data[$field];
            }
        }

        if (empty($sets)) {
            return false;
        }

        $sets[]  = 'updated_at = NOW()';
        $setStr  = implode(', ', $sets);

        $affected = $this->db->execute(
            "UPDATE staff SET $setStr WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL",
            $params
        );

        return $affected > 0;
    }

    /**
     * Soft delete a staff record.
     */
    public function softDelete(int $id, int $tenantId): bool
    {
        $affected = $this->db->execute(
            'UPDATE staff SET deleted_at = NOW(), status = :status
             WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            ['status' => 'inactive', 'id' => $id, 'tid' => $tenantId]
        );
        return $affected > 0;
    }

    /**
     * Check if a user already has a staff record in this tenant.
     */
    public function existsForUser(int $userId, int $tenantId): bool
    {
        $result = $this->db->fetch(
            'SELECT id FROM staff WHERE user_id = :uid AND tenant_id = :tid AND deleted_at IS NULL',
            ['uid' => $userId, 'tid' => $tenantId]
        );
        return (bool) $result;
    }

    /**
     * Get distinct departments for a tenant.
     */
    public function getDepartments(int $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT DISTINCT department FROM staff
             WHERE tenant_id = :tid AND deleted_at IS NULL AND department IS NOT NULL
             ORDER BY department',
            ['tid' => $tenantId]
        );
    }
}