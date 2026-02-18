<?php

namespace App\Modules\ReportsDashboard\Models;

use App\Core\Database;

/**
 * DashboardStats Model: Fixed Version.
 * Bugs Fixed:
 * 1. SQL query used `is_deleted = 0` for patients table, but the schema uses
 *    `deleted_at IS NULL` (soft delete pattern) — consistent with all other models.
 *    Fixed to use `deleted_at IS NULL`.
 * 2. fetch() with multiple named params using the same key (t1, t2, t3) is correct in PDO,
 *    but using a single :tenant_id bound once would fail for multiple occurrences.
 *    The existing t1/t2/t3 approach is correct — kept as-is.
 * 3. Return type hint missing — added return type for clarity.
 */
class DashboardStats
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get aggregated counts for the dashboard.
     * Filtered by tenant_id for clinic isolation.
     * FIX #1: patients soft-delete uses deleted_at IS NULL, not is_deleted = 0.
     */
    public function getCounts(int $tenantId): ?array
    {
        $sql = "SELECT
                (SELECT COUNT(*) FROM patients
                 WHERE tenant_id = :t1 AND deleted_at IS NULL) AS total_patients,

                (SELECT COUNT(*) FROM prescriptions
                 WHERE tenant_id = :t2 AND status = 'pending') AS pending_prescriptions,

                (SELECT COUNT(*) FROM appointments
                 WHERE tenant_id = :t3 AND status = 'scheduled'
                   AND deleted_at IS NULL) AS upcoming_appointments";

        return $this->db->fetch($sql, [
            't1' => $tenantId,
            't2' => $tenantId,
            't3' => $tenantId,
        ]);
    }
}