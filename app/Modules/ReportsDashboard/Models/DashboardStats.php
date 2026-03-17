<?php

namespace App\Modules\ReportsDashboard\Models;

class DashboardStats
{
    private function db(): \App\Core\TenantDatabase { return tenant_db(); }

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

        return $this->db()->fetch($sql, [
            't1' => $tenantId,
            't2' => $tenantId,
            't3' => $tenantId,
        ]);
    }
}