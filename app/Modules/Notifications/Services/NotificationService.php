<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Models\Notification;

/**
 * NotificationService
 *
 * Provides a reusable API for other modules to create notifications.
 * Example usage from an appointment controller:
 *   (new NotificationService())->notify($tenantId, $userId, 'appointment_alert', 'New appointment', 'You have a new appointment.', 'appointment', $appointmentId);
 */
class NotificationService
{
    private Notification $model;

    public function __construct()
    {
        $this->model = new Notification();
    }

    /**
     * Create a notification for a specific user.
     *
     * @param int         $tenantId
     * @param int         $userId            Recipient user ID
     * @param string      $type              e.g. 'appointment_alert', 'payment_alert', 'system'
     * @param string      $title             Short notification title
     * @param string|null $message           Optional longer message body
     * @param string|null $relatedEntityType e.g. 'appointment', 'invoice'
     * @param int|null    $relatedEntityId   ID of the related entity
     * @return int        The new notification ID
     */
    public function notify(
        int     $tenantId,
        int     $userId,
        string  $type,
        string  $title,
        ?string $message = null,
        ?string $relatedEntityType = null,
        ?int    $relatedEntityId = null
    ): int {
        return $this->model->create([
            'tenant_id'           => $tenantId,
            'user_id'             => $userId,
            'type'                => $type,
            'title'               => $title,
            'message'             => $message,
            'related_entity_type' => $relatedEntityType,
            'related_entity_id'   => $relatedEntityId,
        ]);
    }

    /**
     * Create notifications for multiple users at once.
     *
     * @param int         $tenantId
     * @param array       $userIds           Array of user IDs
     * @param string      $type
     * @param string      $title
     * @param string|null $message
     * @param string|null $relatedEntityType
     * @param int|null    $relatedEntityId
     * @return array      Array of created notification IDs
     */
    public function notifyMany(
        int     $tenantId,
        array   $userIds,
        string  $type,
        string  $title,
        ?string $message = null,
        ?string $relatedEntityType = null,
        ?int    $relatedEntityId = null
    ): array {
        $ids = [];
        foreach ($userIds as $uid) {
            $ids[] = $this->notify($tenantId, (int) $uid, $type, $title, $message, $relatedEntityType, $relatedEntityId);
        }
        return $ids;
    }
}
