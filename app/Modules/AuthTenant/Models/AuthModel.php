<?php

namespace App\Modules\AuthTenant\Models;

/**
 * AuthModel — Encapsulates all authentication-related database queries.
 *
 * Extracted from AuthService to follow the project's Model pattern
 * (Controller → Service → Model → tenant_db()).
 */
class AuthModel
{
    private function db(): \App\Core\TenantDatabase
    {
        return tenant_db();
    }

    // ─────────────────────────────────────────────
    //  USER LOOKUPS
    // ─────────────────────────────────────────────

    /**
     * Find an active user by username.
     */
    public function findByUsername(string $username): ?array
    {
        return $this->db()->fetch(
            'SELECT id FROM users WHERE username = :username AND deleted_at IS NULL',
            ['username' => $username]
        );
    }

    /**
     * Find an active user by email hash.
     */
    public function findByEmailHash(string $emailHash): ?array
    {
        return $this->db()->fetch(
            'SELECT id FROM users WHERE email_hash = :hash AND deleted_at IS NULL',
            ['hash' => $emailHash]
        );
    }

    /**
     * Find an active user by ID (with role join).
     */
    public function findById(int $userId): ?array
    {
        return $this->db()->fetch(
            'SELECT u.*, r.name AS role_name
             FROM users u
             JOIN roles r ON u.role_id = r.id
             WHERE u.id = :id AND u.deleted_at IS NULL',
            ['id' => $userId]
        );
    }

    /**
     * Find an active user by username with role (for login).
     */
    public function findForLogin(string $username): ?array
    {
        return $this->db()->fetch(
            'SELECT u.*, r.name AS role_name
             FROM users u
             JOIN roles r ON u.role_id = r.id
             WHERE u.username = :username
               AND u.deleted_at IS NULL',
            ['username' => $username]
        );
    }

    /**
     * Find a user's password hash by ID.
     */
    public function findPasswordById(int $userId): ?array
    {
        return $this->db()->fetch(
            'SELECT id, password_hash FROM users WHERE id = :id AND deleted_at IS NULL',
            ['id' => $userId]
        );
    }

    // ─────────────────────────────────────────────
    //  ROLE LOOKUPS
    // ─────────────────────────────────────────────

    /**
     * Get the default role (usually "Patient").
     */
    public function findDefaultRole(string $roleName = 'Patient'): ?array
    {
        return $this->db()->fetch(
            'SELECT id FROM roles WHERE name = :name',
            ['name' => $roleName]
        );
    }

    /**
     * Find a role by its ID.
     */
    public function findRoleById(int $roleId): ?array
    {
        return $this->db()->fetch(
            'SELECT id, name AS role_name FROM roles WHERE id = :rid',
            ['rid' => $roleId]
        );
    }

    // ─────────────────────────────────────────────
    //  MUTATIONS
    // ─────────────────────────────────────────────

    /**
     * Insert a new user and return the auto-increment ID.
     */
    public function createUser(array $params): int
    {
        return $this->db()->insert(
            'INSERT INTO users (role_id, patient_id, username, encrypted_email, email_hash, password_hash,
                                encrypted_full_name, encrypted_phone, status)
             VALUES (:role_id, :patient_id, :username, :encrypted_email, :email_hash, :password_hash,
                     :encrypted_full_name, :encrypted_phone, :status)',
            $params
        );
    }

    /**
     * Update a user's password hash.
     */
    public function updatePassword(int $userId, string $passwordHash): int
    {
        return $this->db()->execute(
            'UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id',
            ['hash' => $passwordHash, 'id' => $userId]
        );
    }

    /**
     * Re-hash a user's password (when password_needs_rehash detects it).
     */
    /**
     * Associate a user ID with a clinical patient ID.
     */
    public function associatePatient(int $userId, int $patientId): int
    {
        return $this->db()->execute(
            'UPDATE users SET patient_id = :pid, updated_at = NOW() WHERE id = :uid',
            ['pid' => $patientId, 'uid' => $userId]
        );
    }
}
