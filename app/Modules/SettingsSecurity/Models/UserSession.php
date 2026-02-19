<?php

namespace App\Modules\SettingsSecurity\Models;

use App\Core\Database;

/**
 * UserSession Model
 * Tracks active sessions for a user, supports invalidation.
 */
class UserSession
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Create or update a session record.
     */
    public function upsert(int $userId, int $tenantId, string $sessionId, ?string $ip, ?string $ua): int
    {
        // Check if session exists
        $existing = $this->db->fetch(
            'SELECT id FROM user_sessions WHERE session_id = :sid',
            ['sid' => $sessionId]
        );

        if ($existing) {
            $this->db->execute(
                'UPDATE user_sessions SET last_active = NOW(), ip_address = :ip, user_agent = :ua
                 WHERE session_id = :sid',
                ['ip' => $ip, 'ua' => $ua, 'sid' => $sessionId]
            );
            return (int) $existing['id'];
        }

        return $this->db->insert(
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

    /**
     * Get all active sessions for a user.
     */
    public function getActiveSessions(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT id, session_id, ip_address, user_agent, last_active, created_at
             FROM user_sessions
             WHERE user_id = :uid AND is_active = 1
             ORDER BY last_active DESC',
            ['uid' => $userId]
        );
    }

    /**
     * Invalidate a specific session.
     */
    public function invalidate(int $sessionId, int $userId): bool
    {
        $affected = $this->db->execute(
            'UPDATE user_sessions SET is_active = 0 WHERE id = :id AND user_id = :uid',
            ['id' => $sessionId, 'uid' => $userId]
        );
        return $affected > 0;
    }

    /**
     * Invalidate all sessions for a user (logout everywhere).
     */
    public function invalidateAllForUser(int $userId): int
    {
        return $this->db->execute(
            'UPDATE user_sessions SET is_active = 0 WHERE user_id = :uid',
            ['uid' => $userId]
        );
    }

    /**
     * Cleanup stale sessions older than given days.
     */
    public function cleanupStale(int $days = 30): int
    {
        return $this->db->execute(
            'DELETE FROM user_sessions WHERE last_active < DATE_SUB(NOW(), INTERVAL :days DAY)',
            ['days' => $days]
        );
    }
}