<?php

namespace App\Modules\AuthTenant\Models;

class RefreshToken
{
    private function db(): \App\Core\TenantDatabase { return tenant_db(); }

    public function create(int $userId, int $tenantId, string $tokenHash, string $family, string $expiresAt): int
    {
        return $this->db()->insert(
            'INSERT INTO refresh_tokens (user_id, token_hash, family, expires_at) 
             VALUES (:user_id, :token_hash, :family, :expires_at)',
            [
                'user_id'    => $userId,
                'token_hash' => $tokenHash,
                'family'     => $family,
                'expires_at' => $expiresAt,
            ]
        );
    }

    public function findValidByHash(string $tokenHash): ?array
    {
        return $this->db()->fetch(
            'SELECT rt.*, u.status AS user_status 
             FROM refresh_tokens rt 
             JOIN users u ON rt.user_id = u.id 
             WHERE rt.token_hash = :token_hash 
               AND rt.revoked = 0 
               AND rt.expires_at > NOW()',
            ['token_hash' => $tokenHash]
        );
    }

    public function findRevokedByHash(string $tokenHash): ?array
    {
        return $this->db()->fetch(
            'SELECT id, family FROM refresh_tokens 
             WHERE token_hash = :token_hash AND revoked = 1',
            ['token_hash' => $tokenHash]
        );
    }

    public function findByHash(string $tokenHash): ?array
    {
        return $this->db()->fetch(
            'SELECT id, user_id, family, revoked 
             FROM refresh_tokens 
             WHERE token_hash = :token_hash AND revoked = 0',
            ['token_hash' => $tokenHash]
        );
    }

    public function revokeById(int $id): int
    {
        return $this->db()->execute(
            'UPDATE refresh_tokens SET revoked = 1 WHERE id = :id',
            ['id' => $id]
        );
    }

    public function revokeByHash(string $tokenHash): int
    {
        return $this->db()->execute(
            'UPDATE refresh_tokens SET revoked = 1 WHERE token_hash = :hash',
            ['hash' => $tokenHash]
        );
    }

    public function revokeFamily(string $family): int
    {
        return $this->db()->execute(
            'UPDATE refresh_tokens SET revoked = 1 WHERE family = :family',
            ['family' => $family]
        );
    }

    public function revokeAllByUser(int $userId): int
    {
        return $this->db()->execute(
            'UPDATE refresh_tokens SET revoked = 1 WHERE user_id = :user_id',
            ['user_id' => $userId]
        );
    }

    public function countActiveByUser(int $userId): int
    {
        $result = $this->db()->fetch(
            'SELECT COUNT(*) AS count 
             FROM refresh_tokens 
             WHERE user_id = :uid AND revoked = 0 AND expires_at > NOW()',
            ['uid' => $userId]
        );
        return (int) ($result['count'] ?? 0);
    }

    public function deleteExpiredAndRevoked(): int
    {
        return $this->db()->execute(
            'DELETE FROM refresh_tokens WHERE expires_at < NOW() OR revoked = 1'
        );
    }
}