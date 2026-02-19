<?php
namespace App\Modules\Staff\Models;

use App\Core\Database;

class Staff
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

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

    public function getAllByTenant(int $tenantId, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $offset = ($page - 1) * $perPage;
        $where = 's.tenant_id = :tid AND s.deleted_at IS NULL AND u.deleted_at IS NULL';
        $params = ['tid' => $tenantId];

        if (!empty($filters['status'])) {
            $where .= ' AND s.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['role_id'])) {
            $where .= ' AND u.role_id = :role_id';
            $params['role_id'] = $filters['role_id'];
        }
        if (!empty($filters['search'])) {
            $where .= ' AND u.username LIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $countResult = $this->db->fetch(
            "SELECT COUNT(*) AS total FROM staff s JOIN users u ON s.user_id = u.id WHERE $where",
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
            'staff' => $staff,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }

    public function create(array $data): int
    {
        $crypto = new \App\Core\Security\CryptoService();
        return $this->db->insert(
            'INSERT INTO staff (user_id, tenant_id, encrypted_department, encrypted_specialization,
                    encrypted_license_number, hire_date, status, encrypted_notes, created_at, updated_at)
             VALUES (:user_id, :tenant_id, :enc_dept, :enc_spec,
                    :enc_lic, :hire_date, :status, :enc_notes, NOW(), NOW())',
            [
                'user_id'   => $data['user_id'],
                'tenant_id' => $data['tenant_id'],
                'enc_dept'  => isset($data['department']) ? $crypto->encrypt($data['department']) : null,
                'enc_spec'  => isset($data['specialization']) ? $crypto->encrypt($data['specialization']) : null,
                'enc_lic'   => isset($data['license_number']) ? $crypto->encrypt($data['license_number']) : null,
                'hire_date' => $data['hire_date'] ?? null,
                'status'    => $data['status'] ?? 'active',
                'enc_notes' => isset($data['notes']) ? $crypto->encrypt($data['notes']) : null,
            ]
        );
    }

    public function update(int $id, int $tenantId, array $data): bool
    {
        $crypto = new \App\Core\Security\CryptoService();
        $sets = [];
        $params = ['id' => $id, 'tid' => $tenantId];

        if (array_key_exists('department', $data)) {
            $sets[] = 'encrypted_department = :enc_dept';
            $params['enc_dept'] = $data['department'] ? $crypto->encrypt($data['department']) : null;
        }
        if (array_key_exists('specialization', $data)) {
            $sets[] = 'encrypted_specialization = :enc_spec';
            $params['enc_spec'] = $data['specialization'] ? $crypto->encrypt($data['specialization']) : null;
        }
        if (array_key_exists('license_number', $data)) {
            $sets[] = 'encrypted_license_number = :enc_lic';
            $params['enc_lic'] = $data['license_number'] ? $crypto->encrypt($data['license_number']) : null;
        }
        if (isset($data['hire_date'])) {
            $sets[] = 'hire_date = :hire_date';
            $params['hire_date'] = $data['hire_date'];
        }
        if (isset($data['status'])) {
            $sets[] = 'status = :status';
            $params['status'] = $data['status'];
        }
        if (array_key_exists('notes', $data)) {
            $sets[] = 'encrypted_notes = :enc_notes';
            $params['enc_notes'] = $data['notes'] ? $crypto->encrypt($data['notes']) : null;
        }

        if (empty($sets)) {
            return false;
        }

        $sets[] = 'updated_at = NOW()';
        $setStr = implode(', ', $sets);

        $affected = $this->db->execute(
            "UPDATE staff SET $setStr WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL",
            $params
        );
        return $affected > 0;
    }

    public function softDelete(int $id, int $tenantId): bool
    {
        $affected = $this->db->execute(
            'UPDATE staff SET deleted_at = NOW(), status = :status
             WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            ['status' => 'inactive', 'id' => $id, 'tid' => $tenantId]
        );
        return $affected > 0;
    }

    public function existsForUser(int $userId, int $tenantId): bool
    {
        $result = $this->db->fetch(
            'SELECT id FROM staff WHERE user_id = :uid AND tenant_id = :tid AND deleted_at IS NULL',
            ['uid' => $userId, 'tid' => $tenantId]
        );
        return (bool) $result;
    }

    public function getDepartments(int $tenantId): array
    {
        // Cannot return encrypted values as distinct - return all and dedupe in service
        return $this->db->fetchAll(
            'SELECT DISTINCT encrypted_department as department FROM staff
             WHERE tenant_id = :tid AND deleted_at IS NULL AND encrypted_department IS NOT NULL',
            ['tid' => $tenantId]
        );
    }
}