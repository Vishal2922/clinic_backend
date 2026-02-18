<?php

namespace App\Modules\UsersRoles\Models;

use App\Core\Database;
use App\Core\Security\CryptoService;

class User
{
    private Database $db;
    private CryptoService $crypto;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->crypto = new CryptoService();
    }

    /**
     * Find user by ID within tenant
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $user = $this->db->fetch(
            'SELECT u.id, u.tenant_id, u.role_id, u.username, u.encrypted_email, 
                    u.encrypted_full_name, u.encrypted_phone, u.status, 
                    u.created_at, u.updated_at, r.role_name
             FROM users u
             JOIN roles r ON u.role_id = r.id
             WHERE u.id = :id AND u.tenant_id = :tid AND u.deleted_at IS NULL',
            ['id' => $id, 'tid' => $tenantId]
        );

        if ($user) {
            $user = $this->decryptUserData($user);
        }

        return $user;
    }

    /**
     * Get all users for a tenant (with pagination)
     */
    public function getAllByTenant(int $tenantId, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $offset = ($page - 1) * $perPage;
        $where = 'u.tenant_id = :tid AND u.deleted_at IS NULL';
        $params = ['tid' => $tenantId];

        // Apply filters
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

        // Get total count
        $countResult = $this->db->fetch(
            "SELECT COUNT(*) as total FROM users u WHERE $where",
            $params
        );
        $total = (int) $countResult['total'];

        // Get paginated results
        $users = $this->db->fetchAll(
            "SELECT u.id, u.tenant_id, u.role_id, u.username, u.encrypted_email, 
                    u.encrypted_full_name, u.encrypted_phone, u.status, 
                    u.created_at, u.updated_at, r.role_name
             FROM users u
             JOIN roles r ON u.role_id = r.id
             WHERE $where
             ORDER BY u.created_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        // Decrypt user data
        $users = array_map([$this, 'decryptUserData'], $users);

        return [
            'users' => $users,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Create a new user
     */
    public function create(array $data, int $tenantId): int
    {
        $emailHash = $this->crypto->hash($data['email']);
        $encryptedEmail = $this->crypto->encrypt($data['email']);
        $encryptedFullName = isset($data['full_name']) ? $this->crypto->encrypt($data['full_name']) : null;
        $encryptedPhone = isset($data['phone']) ? $this->crypto->encrypt($data['phone']) : null;

        $passwordHash = password_hash($data['password'], PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3,
        ]);

        return $this->db->insert(
            'INSERT INTO users (tenant_id, role_id, username, encrypted_email, email_hash, password_hash, encrypted_full_name, encrypted_phone, status)
             VALUES (:tenant_id, :role_id, :username, :encrypted_email, :email_hash, :password_hash, :encrypted_full_name, :encrypted_phone, :status)',
            [
                'tenant_id' => $tenantId,
                'role_id' => $data['role_id'],
                'username' => sanitize($data['username']),
                'encrypted_email' => $encryptedEmail,
                'email_hash' => $emailHash,
                'password_hash' => $passwordHash,
                'encrypted_full_name' => $encryptedFullName,
                'encrypted_phone' => $encryptedPhone,
                'status' => $data['status'] ?? 'active',
            ]
        );
    }

    /**
     * Update user
     */
    public function update(int $id, array $data, int $tenantId): bool
    {
        $sets = [];
        $params = ['id' => $id, 'tid' => $tenantId];

        if (isset($data['role_id'])) {
            $sets[] = 'role_id = :role_id';
            $params['role_id'] = $data['role_id'];
        }

        if (isset($data['email'])) {
            $sets[] = 'encrypted_email = :enc_email';
            $sets[] = 'email_hash = :email_hash';
            $params['enc_email'] = $this->crypto->encrypt($data['email']);
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

        $affected = $this->db->execute(
            "UPDATE users SET $setStr WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL",
            $params
        );

        return $affected > 0;
    }

    /**
     * Soft delete user
     */
    public function softDelete(int $id, int $tenantId): bool
    {
        $affected = $this->db->execute(
            'UPDATE users SET deleted_at = NOW(), status = :status WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            ['status' => 'inactive', 'id' => $id, 'tid' => $tenantId]
        );

        return $affected > 0;
    }

    /**
     * Check if username exists in tenant
     */
    public function usernameExists(string $username, int $tenantId, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM users WHERE tenant_id = :tid AND username = :username AND deleted_at IS NULL';
        $params = ['tid' => $tenantId, 'username' => $username];

        if ($excludeId) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        return (bool) $this->db->fetch($sql, $params);
    }

    /**
     * Check if email exists in tenant
     */
    public function emailExists(string $email, int $tenantId, ?int $excludeId = null): bool
    {
        $emailHash = $this->crypto->hash($email);
        $sql = 'SELECT id FROM users WHERE tenant_id = :tid AND email_hash = :hash AND deleted_at IS NULL';
        $params = ['tid' => $tenantId, 'hash' => $emailHash];

        if ($excludeId) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        return (bool) $this->db->fetch($sql, $params);
    }

    /**
     * Decrypt user data for response
     */
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

        // Remove encrypted fields from response
        unset($user['encrypted_email'], $user['encrypted_full_name'], $user['encrypted_phone']);

        return $user;
    }
}