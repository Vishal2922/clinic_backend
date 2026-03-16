<?php

namespace App\Modules\SettingsSecurity\Models;

class UserSession
{
    private function db(): \App\Core\TenantDatabase { return tenant_db(); }

    public function upsert(int $userId, int $tenantId, string $sessionId, ?string $ip, ?string $ua): int
    {
        $existing = $this->db()->fetch(
            'SELECT id FROM user_sessions WHERE session_id = :sid',
            ['sid' => $sessionId]
        );

        if ($existing) {
            $this->db()->execute(
                'UPDATE user_sessions SET last_active = NOW(), ip_address = :ip, user_agent = :ua
                 WHERE session_id = :sid',
                ['ip' => $ip, 'ua' => $ua, 'sid' => $sessionId]
            );
            return (int) $existing['id'];
        }

        return $this->db()->insert(
            'INSERT INTO user_sessions (user_id, tenant_id, session_id, ip_address, user_agent, is_active)
             VALUES (:uid, :tid, :sid, :ip, :ua, 1)',
            [
                'uid' => $userId,
                'tid' => $tenantId,
                'sid' => $sessionId,
                'ip'  => $ip,
                'ua'  => $ua,
            ]
        );
    }

    public function getActiveSessions(int $userId): array
    {
        return $this->db()->fetchAll(
            'SELECT id, session_id, ip_address, user_agent, last_active, created_at
             FROM user_sessions
             WHERE user_id = :uid AND is_active = 1
             ORDER BY last_active DESC',
            ['uid' => $userId]
        );
    }

    public function invalidate(int $sessionId, int $userId): bool
    {
        $affected = $this->db()->execute(
            'UPDATE user_sessions SET is_active = 0 WHERE id = :id AND user_id = :uid',
            ['id' => $sessionId, 'uid' => $userId]
        );
        return $affected > 0;
    }

    public function invalidateAllForUser(int $userId): int
    {
        return $this->db()->execute(
            'UPDATE user_sessions SET is_active = 0 WHERE user_id = :uid',
            ['uid' => $userId]
        );
    }

    public function cleanupStale(int $days = 30): int
    {
        return $this->db()->execute(
            'DELETE FROM user_sessions WHERE last_active < DATE_SUB(NOW(), INTERVAL :days DAY)',
            ['days' => $days]
        );
    }
}