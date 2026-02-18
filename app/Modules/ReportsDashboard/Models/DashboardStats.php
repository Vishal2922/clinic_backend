<?php

namespace App\Modules\ReportsDashboard\Models;

use App\Core\Database;
use PDO;

/**
 * DashboardStats Model: Handles aggregated data for reports.
 * Uses Singleton Database instance for performance.
 */
class DashboardStats {
    private $db;

    public function __construct() {
        /**
         * 🔥 FIX: Merged Version
         * 'new' keyword-ah remove panni, Singleton getInstance() use pannittaen.
         * Ippo project full-ah ore oru DB connection thaan maintain aagum.
         */
        $this->db = Database::getInstance();
    }

    /**
     * Get aggregated counts for the dashboard.
     * Filtered by tenant_id for clinic isolation.
     */
    public function getCounts($tenant_id) {
        /**
         * SQL Logic:
         * Multiple sub-queries use panni ore row-la counts-ah edukkurhom.
         * Ithu performance-ku romba nallathu.
         */
        $sql = "SELECT 
                (SELECT COUNT(*) FROM patients WHERE tenant_id = :t1 AND is_deleted = 0) as total_patients,
                (SELECT COUNT(*) FROM prescriptions WHERE tenant_id = :t2 AND status = 'pending') as pending_prescriptions,
                (SELECT COUNT(*) FROM appointments WHERE tenant_id = :t3 AND status = 'scheduled') as upcoming_appointments
                ";
        
        /**
         * Namma merge panna Database::fetch() helper-ah use panroam.
         * Ithu automatic-ah prepare matum execute-ah handle pannum.
         */
        return $this->db->fetch($sql, [
            't1' => $tenant_id, 
            't2' => $tenant_id, 
            't3' => $tenant_id
        ]);
    }
}