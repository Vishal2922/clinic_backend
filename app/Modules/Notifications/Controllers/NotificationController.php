<?php

namespace App\Modules\Notifications\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Notifications\Models\Notification;

/**
 * NotificationController — Module 13
 *
 * Routes:
 *  GET    /api/notifications               -> index
 *  PATCH  /api/notifications/{id}/read     -> markAsRead
 *  POST   /api/notifications/mark-all-read -> markAllAsRead
 *  DELETE /api/notifications/{id}          -> destroy
 *  GET    /api/notifications/unread-count  -> unreadCount
 */
class NotificationController extends Controller
{
    private Notification $notificationModel;

    public function __construct()
    {
        $this->notificationModel = new Notification();
    }

    /**
     * GET /api/notifications?page=1&per_page=20
     * Returns paginated notifications for the authenticated user.
     */
    public function index(Request $request): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $userId   = (int) ($user['user_id'] ?? 0);
        $page     = (int) $request->getQueryParam('page', 1);
        $perPage  = (int) $request->getQueryParam('per_page', 20);

        if (!$userId) {
            Response::error('Unable to identify user.', 401);
            return;
        }

        try {
            $result      = $this->notificationModel->getByUser($userId, $tenantId, $page, $perPage);
            $unreadCount = $this->notificationModel->getUnreadCount($userId, $tenantId);

            Response::json([
                'message' => 'Notifications retrieved successfully.',
                'data'    => [
                    'notifications' => $result['notifications'],
                    'unread_count'  => $unreadCount,
                    'pagination'    => $result['pagination'],
                ],
            ], 200);
        } catch (\Exception $e) {
            app_log('Get notifications error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve notifications.', 500);
        }
    }

    /**
     * PATCH /api/notifications/{id}/read
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $userId   = (int) ($user['user_id'] ?? 0);

        try {
            $notification = $this->notificationModel->findById((int) $id, $tenantId);

            if (!$notification) {
                Response::error('Notification not found.', 404);
                return;
            }

            // Ensure user owns the notification
            if ((int) $notification['user_id'] !== $userId) {
                Response::error('Not authorised.', 403);
                return;
            }

            $this->notificationModel->markAsRead((int) $id, $tenantId);

            Response::json(['message' => 'Notification marked as read.'], 200);
        } catch (\Exception $e) {
            app_log('Mark notification read error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to mark notification as read.', 500);
        }
    }

    /**
     * POST /api/notifications/mark-all-read
     * Mark all notifications as read for the authenticated user.
     */
    public function markAllAsRead(Request $request): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $userId   = (int) ($user['user_id'] ?? 0);

        if (!$userId) {
            Response::error('Unable to identify user.', 401);
            return;
        }

        try {
            $count = $this->notificationModel->markAllAsRead($userId, $tenantId);

            Response::json([
                'message' => "All notifications marked as read ({$count} updated).",
            ], 200);
        } catch (\Exception $e) {
            app_log('Mark all read error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to mark all as read.', 500);
        }
    }

    /**
     * DELETE /api/notifications/{id}
     * Soft-delete a notification. User can only delete their own.
     */
    public function destroy(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $userId   = (int) ($user['user_id'] ?? 0);

        try {
            $notification = $this->notificationModel->findById((int) $id, $tenantId);

            if (!$notification) {
                Response::error('Notification not found.', 404);
                return;
            }

            if ((int) $notification['user_id'] !== $userId) {
                Response::error('Not authorised to delete this notification.', 403);
                return;
            }

            $deleted = $this->notificationModel->softDelete((int) $id, $tenantId);

            if (!$deleted) {
                Response::error('Failed to delete notification.', 500);
                return;
            }

            Response::json(['message' => 'Notification deleted successfully.'], 200);
        } catch (\Exception $e) {
            app_log('Delete notification error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to delete notification.', 500);
        }
    }

    /**
     * GET /api/notifications/unread-count
     * Returns just the unread count (for badge polling).
     */
    public function unreadCount(Request $request): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $userId   = (int) ($user['user_id'] ?? 0);

        if (!$userId) {
            Response::error('Unable to identify user.', 401);
            return;
        }

        try {
            $count = $this->notificationModel->getUnreadCount($userId, $tenantId);

            Response::json([
                'message' => 'Unread count retrieved.',
                'data'    => ['unread_count' => $count],
            ], 200);
        } catch (\Exception $e) {
            app_log('Unread count error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to get unread count.', 500);
        }
    }

    /**
     * POST /api/notifications/broadcast
     * Sends a system broadcast to all active users in the tenant.
     * Only Admin role can use this.
     */
    public function broadcast(Request $request): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $userId   = (int) ($user['user_id'] ?? 0);

        if (!$userId || strtolower($user['role_name'] ?? '') !== 'admin') {
            Response::error('Unauthorised. Only Admin can send broadcasts.', 403);
            return;
        }

        $data = $request->getBody();
        $title = trim($data['title'] ?? '');
        $message = trim($data['message'] ?? '');

        if (!$title || !$message) {
            Response::error('Validation failed: title and message are required.', 400);
            return;
        }

        try {
            $userModel = new \App\Modules\UsersRoles\Models\User();
            $activeUserIds = $userModel->getActiveUserIds($tenantId);

            if (empty($activeUserIds)) {
                Response::error('No active users found to broadcast to.', 400);
                return;
            }

            $service = new \App\Modules\Notifications\Services\NotificationService();
            $service->notifyMany(
                $tenantId,
                $activeUserIds,
                'system',
                $title,
                $message
            );

            Response::json(['message' => 'Broadcast sent successfully to ' . count($activeUserIds) . ' users.'], 200);
        } catch (\Exception $e) {
            app_log('Broadcast error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to send broadcast.', 500);
        }
    }
}
