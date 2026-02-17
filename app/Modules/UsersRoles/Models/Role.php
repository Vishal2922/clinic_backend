<?php

namespace App\Modules\UsersRoles\Models;

use App\Core\Database;

class Role
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM roles WHERE id = :id AND tenant_id = :tid',
            ['id' => $id, 'tid' => $tenantId]
        );
    }

    public function getAllByTenant(int $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM roles WHERE tenant_id = :tid ORDER BY role_name',
            ['tid' => $tenantId]
        );
    }

    public function create(string $roleName, string $description, int $tenantId): int
    {
        return $this->db->insert(
            'INSERT INTO roles (tenant_id, role_name, description) VALUES (:tid, :name, :desc)',
            ['tid' => $tenantId, 'name' => $roleName, 'desc' => $description]
        );
    }

    public function update(int $id, string $roleName, string $description, int $tenantId): bool
    {
        $affected = $this->db->execute(
            'UPDATE roles SET role_name = :name, description = :desc WHERE id = :id AND tenant_id = :tid',
            ['name' => $roleName, 'desc' => $description, 'id' => $id, 'tid' => $tenantId]
        );
        return $affected > 0;
    }

    public function delete(int $id, int $tenantId): bool
    {
        // Check if any users are using this role
        $count = $this->db->fetch(
            'SELECT COUNT(*) as count FROM users WHERE role_id = :rid AND tenant_id = :tid AND deleted_at IS NULL',
            ['rid' => $id, 'tid' => $tenantId]
        );

        if ((int)$count['count'] > 0) {
            throw new \RuntimeException('Cannot delete role with assigned users');
        }

        $affected = $this->db->execute(
            'DELETE FROM roles WHERE id = :id AND tenant_id = :tid',
            ['id' => $id, 'tid' => $tenantId]
        );
        return $affected > 0;
    }

    public function roleNameExists(string $roleName, int $tenantId, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM roles WHERE tenant_id = :tid AND role_name = :name';
        $params = ['tid' => $tenantId, 'name' => $roleName];

        if ($excludeId) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        return (bool) $this->db->fetch($sql, $params);
    }
}