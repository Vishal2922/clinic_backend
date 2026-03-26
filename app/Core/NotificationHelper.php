<?php

namespace App\Core;

use App\Modules\Notifications\Models\Notification;

/**
 * NotificationHelper — Lightweight helper to create targeted notifications.
 *
 * Usage:
 *   NotificationHelper::notifyUser($tenantId, $userId, 'appointment', 'New Appointment', 'You have a new appointment', 'appointment', $apptId);
 *   NotificationHelper::notifyRole($tenantId, 'Pharmacist', 'prescription', 'New Prescription', '...', 'prescription', $rxId);
 */
class NotificationHelper
{
    /**
     * Send a notification to a single user.
     */
    public static function notifyUser(
        int     $tenantId,
        int     $userId,
        string  $type,
        string  $title,
        ?string $message = null,
        ?string $entityType = null,
        ?int    $entityId = null
    ): void {
        try {
            $model = new Notification();
            $model->create([
                'tenant_id'           => $tenantId,
                'user_id'             => $userId,
                'type'                => $type,
                'title'               => $title,
                'message'             => $message,
                'related_entity_type' => $entityType,
                'related_entity_id'   => $entityId,
            ]);
        } catch (\Exception $e) {
            if (function_exists('app_log')) {
                app_log("NotificationHelper::notifyUser failed: " . $e->getMessage(), 'ERROR');
            }
        }
    }

    /**
     * Send a notification to a specific patient by their patient_id.
     */
    public static function notifyPatient(
        int     $tenantId,
        int     $patientId,
        string  $type,
        string  $title,
        ?string $message = null,
        ?string $entityType = null,
        ?int    $entityId = null
    ): void {
        try {
            $userId = self::resolveUserIdByPatientId($patientId);
            if ($userId) {
                self::notifyUser($tenantId, $userId, $type, $title, $message, $entityType, $entityId);
            }
        } catch (\Exception $e) {
            if (function_exists('app_log')) {
                app_log("NotificationHelper::notifyPatient failed: " . $e->getMessage(), 'ERROR');
            }
        }
    }

    /**
     * Send a notification to ALL users with a specific role in the tenant.
     */
    public static function notifyRole(
        int     $tenantId,
        string  $roleName,
        string  $type,
        string  $title,
        ?string $message = null,
        ?string $entityType = null,
        ?int    $entityId = null
    ): void {
        try {
            $users = self::getUsersByRole($roleName);
            $model = new Notification();

            foreach ($users as $user) {
                $model->create([
                    'tenant_id'           => $tenantId,
                    'user_id'             => (int) $user['id'],
                    'type'                => $type,
                    'title'               => $title,
                    'message'             => $message,
                    'related_entity_type' => $entityType,
                    'related_entity_id'   => $entityId,
                ]);
            }
        } catch (\Exception $e) {
            if (function_exists('app_log')) {
                app_log("NotificationHelper::notifyRole failed: " . $e->getMessage(), 'ERROR');
            }
        }
    }

    /**
     * Fetch all active user IDs for a given role name.
     */
    private static function getUsersByRole(string $roleName): array
    {
        $db = tenant_db();
        return $db->fetchAll(
            "SELECT u.id
             FROM users u
             JOIN roles r ON u.role_id = r.id
             WHERE r.name = :role AND u.status = 'active' AND u.deleted_at IS NULL",
            ['role' => $roleName]
        );
    }

    /**
     * Resolve user_id from patient_id.
     */
    private static function resolveUserIdByPatientId(int $patientId): ?int
    {
        $db = tenant_db();
        $res = $db->fetch(
            "SELECT id FROM users WHERE patient_id = :pid AND deleted_at IS NULL LIMIT 1",
            ['pid' => $patientId]
        );
        return $res ? (int) $res['id'] : null;
    }
}
