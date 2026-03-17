<?php

namespace App\Modules\UsersRoles\Models;

class Role
{
    private function db(): \App\Core\TenantDatabase
    {
        return tenant_db();
    }

    public function findById(int $id): ?array
    {
        return $this->db()->fetch(
            'SELECT id, name AS role_name, description, is_system_role FROM roles WHERE id = :id',
            ['id' => $id]
        );
    }

    public function getAllByTenant(): array
    {
        return $this->db()->fetchAll(
            'SELECT id, name AS role_name, description, is_system_role FROM roles ORDER BY name'
        );
    }

    public function create(string $roleName, string $description): int
    {
        return $this->db()->insert(
            'INSERT INTO roles (name, description, created_at) VALUES (:name, :desc, NOW())',
            ['name' => $roleName, 'desc' => $description]
        );
    }

    public function update(int $id, string $roleName, string $description): bool
    {
        $affected = $this->db()->execute(
            'UPDATE roles SET name = :name, description = :desc WHERE id = :id',
            ['name' => $roleName, 'desc' => $description, 'id' => $id]
        );
        return $affected > 0;
    }

    public function delete(int $id): bool
    {
        $count = $this->db()->fetch(
            'SELECT COUNT(*) as count FROM users WHERE role_id = :rid AND deleted_at IS NULL',
            ['rid' => $id]
        );

        if ((int) $count['count'] > 0) {
            throw new \RuntimeException('Cannot delete role with assigned users');
        }

        $affected = $this->db()->execute(
            'DELETE FROM roles WHERE id = :id AND is_system_role = 0',
            ['id' => $id]
        );
        return $affected > 0;
    }

    public function roleNameExists(string $roleName, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT id FROM roles WHERE name = :name';
        $params = ['name' => $roleName];

        if ($excludeId) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        return (bool) $this->db()->fetch($sql, $params);
    }
}