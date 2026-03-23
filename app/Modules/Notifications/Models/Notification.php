<?php

namespace App\Modules\Notifications\Models;

class Notification
{
    private function db(): \App\Core\TenantDatabase { return tenant_db(); }

    /**
     * Get paginated notifications for a user.
     */
    public function getByUser(int $userId, int $tenantId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;

        $countResult = $this->db()->fetch(
            'SELECT COUNT(*) as total FROM notifications
             WHERE user_id = :uid AND tenant_id = :tid AND deleted_at IS NULL',
            ['uid' => $userId, 'tid' => $tenantId]
        );
        $total = (int) ($countResult['total'] ?? 0);

        $notifications = $this->db()->fetchAll(
            'SELECT * FROM notifications
             WHERE user_id = :uid AND tenant_id = :tid AND deleted_at IS NULL
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset',
            ['uid' => $userId, 'tid' => $tenantId, 'limit' => $perPage, 'offset' => $offset]
        );

        return [
            'notifications' => $notifications,
            'pagination'    => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }

    /**
     * Find a single notification by ID within a tenant.
     */
    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db()->fetch(
            'SELECT * FROM notifications
             WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            ['id' => $id, 'tid' => $tenantId]
        );
    }

    /**
     * Create a new notification.
     */
    public function create(array $data): int
    {
        return $this->db()->insert(
            'INSERT INTO notifications
                (tenant_id, user_id, type, title, message, is_read, related_entity_type, related_entity_id, created_at)
             VALUES
                (:tenant_id, :user_id, :type, :title, :message, 0, :related_entity_type, :related_entity_id, NOW())',
            [
                'tenant_id'           => $data['tenant_id'],
                'user_id'             => $data['user_id'],
                'type'                => $data['type'] ?? 'system',
                'title'               => $data['title'],
                'message'             => $data['message'] ?? null,
                'related_entity_type' => $data['related_entity_type'] ?? null,
                'related_entity_id'   => $data['related_entity_id'] ?? null,
            ]
        );
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(int $id, int $tenantId): bool
    {
        $affected = $this->db()->execute(
            'UPDATE notifications SET is_read = 1, read_at = NOW()
             WHERE id = :id AND tenant_id = :tid AND is_read = 0 AND deleted_at IS NULL',
            ['id' => $id, 'tid' => $tenantId]
        );
        return $affected > 0;
    }

    /**
     * Mark all notifications as read for a user.
     */
    public function markAllAsRead(int $userId, int $tenantId): int
    {
        return $this->db()->execute(
            'UPDATE notifications SET is_read = 1, read_at = NOW()
             WHERE user_id = :uid AND tenant_id = :tid AND is_read = 0 AND deleted_at IS NULL',
            ['uid' => $userId, 'tid' => $tenantId]
        );
    }

    /**
     * Soft-delete a notification.
     */
    public function softDelete(int $id, int $tenantId): bool
    {
        $affected = $this->db()->execute(
            'UPDATE notifications SET deleted_at = NOW()
             WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            ['id' => $id, 'tid' => $tenantId]
        );
        return $affected > 0;
    }

    /**
     * Get unread count for a user (for badge polling).
     */
    public function getUnreadCount(int $userId, int $tenantId): int
    {
        $result = $this->db()->fetch(
            'SELECT COUNT(*) as cnt FROM notifications
             WHERE user_id = :uid AND tenant_id = :tid AND is_read = 0 AND deleted_at IS NULL',
            ['uid' => $userId, 'tid' => $tenantId]
        );
        return (int) ($result['cnt'] ?? 0);
    }
}
