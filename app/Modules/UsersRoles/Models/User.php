<?php

namespace App\Modules\UsersRoles\Models;

use App\Core\Security\CryptoService;

class User
{
    private CryptoService $crypto;

    public function __construct()
    {
        $this->crypto = new CryptoService();
    }

    private function db(): \App\Core\TenantDatabase
    {
        return tenant_db();
    }

    public function findById(int $id, int $tenantId): ?array
    {
        $user = $this->db()->fetch(
            'SELECT u.id, u.role_id, u.username, u.encrypted_email,
                    u.encrypted_full_name, u.encrypted_phone, u.status,
                    u.created_at, u.updated_at, r.name AS role_name
             FROM users u
             JOIN roles r ON u.role_id = r.id
             WHERE u.id = :id AND u.deleted_at IS NULL',
            ['id' => $id]
        );

        if ($user) {
            $user = $this->decryptUserData($user);
        }

        return $user;
    }

    public function getActiveUserIds(int $tenantId): array
    {
        $users = $this->db()->fetchAll(
            "SELECT id FROM users WHERE status = 'active' AND deleted_at IS NULL"
        );
        return array_column($users, 'id');
    }

    public function getAllByTenant(int $tenantId, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $offset = ($page - 1) * $perPage;
        $where = 'u.deleted_at IS NULL';
        $params = [];

        if (!empty($filters['status'])) {
            $where .= ' AND u.status = :status';
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

        $countResult = $this->db()->fetch(
            "SELECT COUNT(*) as total FROM users u WHERE $where",
            $params
        );
        $total = (int) $countResult['total'];

        $users = $this->db()->fetchAll(
            "SELECT u.id, u.role_id, u.username, u.encrypted_email,
                    u.encrypted_full_name, u.encrypted_phone, u.status,
                    u.created_at, u.updated_at, r.name AS role_name
             FROM users u
             JOIN roles r ON u.role_id = r.id
             WHERE $where
             ORDER BY u.created_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        $users = array_map([$this, 'decryptUserData'], $users);

        return [
            'users' => $users,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => ceil($total / $perPage),
            ],
        ];
    }

    public function create(array $data, int $tenantId): int
    {
        $emailHash         = $this->crypto->hash($data['email']);
        $encryptedEmail    = $this->crypto->encrypt($data['email']);
        $encryptedFullName = isset($data['full_name']) ? $this->crypto->encrypt($data['full_name']) : null;
        $encryptedPhone    = isset($data['phone']) ? $this->crypto->encrypt($data['phone']) : null;

        $passwordHash = password_hash($data['password'], PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 3,
        ]);

        return $this->db()->insert(
            'INSERT INTO users (role_id, username, encrypted_email, email_hash, password_hash, encrypted_full_name, encrypted_phone, status)
             VALUES (:role_id, :username, :encrypted_email, :email_hash, :password_hash, :encrypted_full_name, :encrypted_phone, :status)',
            [
                'role_id'             => $data['role_id'],
                'username'            => sanitize($data['username']),
                'encrypted_email'     => $encryptedEmail,
                'email_hash'          => $emailHash,
                'password_hash'       => $passwordHash,
                'encrypted_full_name' => $encryptedFullName,
                'encrypted_phone'     => $encryptedPhone,
                'status'              => $data['status'] ?? 'active',
            ]
        );
    }

    public function update(int $id, array $data, int $tenantId): bool
    {
        $sets   = [];
        $params = ['id' => $id];

        if (isset($data['role_id'])) {
            $sets[] = 'role_id = :role_id';
            $params['role_id'] = $data['role_id'];
        }
        if (isset($data['email'])) {
            $sets[] = 'encrypted_email = :enc_email';
            $sets[] = 'email_hash = :email_hash';
            $params['enc_email']  = $this->crypto->encrypt($data['email']);
            $params['email_hash'] = $this->crypto->hash($data['email']);
        }
        if (isset($data['full_name'])) {
            $sets[] = 'encrypted_full_name = :enc_name';
            $params['enc_name'] = $this->crypto->encrypt($data['full_name']);
        }
        if (isset($data['phone'])) {
            $sets[] = 'encrypted_phone = :enc_phone';
            $params['enc_phone'] = $this->crypto->encrypt($data['phone']);
        }
        if (isset($data['status'])) {
            $sets[] = 'status = :status';
            $params['status'] = $data['status'];
        }

        if (empty($sets)) {
            return false;
        }

        $sets[] = 'updated_at = NOW()';
        $setStr = implode(', ', $sets);

        $affected = $this->db()->execute(
            "UPDATE users SET $setStr WHERE id = :id AND deleted_at IS NULL",
            $params
        );

        return $affected > 0;
    }

    public function softDelete(int $id, int $tenantId): bool
    {
        $affected = $this->db()->execute(
            'UPDATE users SET deleted_at = NOW(), status = :status WHERE id = :id AND deleted_at IS NULL',
            ['status' => 'inactive', 'id' => $id]
        );

        return $affected > 0;
    }

    public function usernameExists(string $username, int $tenantId, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT id FROM users WHERE username = :username AND deleted_at IS NULL';
        $params = ['username' => $username];

        if ($excludeId) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        return (bool) $this->db()->fetch($sql, $params);
    }

    public function emailExists(string $email, int $tenantId, ?int $excludeId = null): bool
    {
        $emailHash = $this->crypto->hash($email);
        $sql       = 'SELECT id FROM users WHERE email_hash = :hash AND deleted_at IS NULL';
        $params    = ['hash' => $emailHash];

        if ($excludeId) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        return (bool) $this->db()->fetch($sql, $params);
    }

    private function decryptUserData(array $user): array
    {
        try {
            if (!empty($user['encrypted_email'])) {
                $user['email'] = $this->crypto->decrypt($user['encrypted_email']);
            }
            if (!empty($user['encrypted_full_name'])) {
                $user['full_name'] = $this->crypto->decrypt($user['encrypted_full_name']);
            }
            if (!empty($user['encrypted_phone'])) {
                $user['phone'] = $this->crypto->decrypt($user['encrypted_phone']);
            }
        } catch (\Exception $e) {
            app_log('Decryption error for user ' . ($user['id'] ?? 'unknown') . ': ' . $e->getMessage(), 'ERROR');
        }

        unset($user['encrypted_email'], $user['encrypted_full_name'], $user['encrypted_phone']);

        return $user;
    }
}