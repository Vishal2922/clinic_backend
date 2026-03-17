<?php

namespace App\Modules\UsersRoles\Models;

class Permission
{
    private function db(): \App\Core\TenantDatabase
    {
        return tenant_db();
    }

    public function getAll(): array
    {
        return $this->db()->fetchAll('SELECT * FROM permissions ORDER BY permission_key');
    }

    public function findById(int $id): ?array
    {
        return $this->db()->fetch(
            'SELECT * FROM permissions WHERE id = :id',
            ['id' => $id]
        );
    }

    public function getByRoleId(int $roleId): array
    {
        return $this->db()->fetchAll(
            'SELECT p.* FROM permissions p
             JOIN role_permissions rp ON p.id = rp.permission_id
             WHERE rp.role_id = :rid
             ORDER BY p.permission_key',
            ['rid' => $roleId]
        );
    }

    public function assignToRole(int $roleId, array $permissionIds): void
    {
        $this->db()->beginTransaction();

        try {
            $this->db()->execute(
                'DELETE FROM role_permissions WHERE role_id = :rid',
                ['rid' => $roleId]
            );

            foreach ($permissionIds as $permId) {
                $this->db()->insert(
                    'INSERT INTO role_permissions (role_id, permission_id) VALUES (:rid, :pid)',
                    ['rid' => $roleId, 'pid' => $permId]
                );
            }

            $this->db()->commit();
        } catch (\Exception $e) {
            $this->db()->rollBack();
            throw $e;
        }
    }

    public function userHasPermission(int $userId, string $permissionKey): bool
    {
        $result = $this->db()->fetch(
            'SELECT 1 FROM users u
             JOIN role_permissions rp ON u.role_id = rp.role_id
             JOIN permissions p ON rp.permission_id = p.id
             WHERE u.id = :uid AND p.permission_key = :pkey AND u.deleted_at IS NULL',
            ['uid' => $userId, 'pkey' => $permissionKey]
        );

        return (bool) $result;
    }
}