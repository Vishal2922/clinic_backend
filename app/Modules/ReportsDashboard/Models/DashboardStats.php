<?php
namespace App\Modules\ReportsDashboard\Models;

use App\Core\Database;
use PDO;

class DashboardStats {
    private $db;

    public function __construct() {
        $this->db = (new Database())->getConnection();
    }

    public function getCounts($tenant_id) {
        $sql = "SELECT 
                (SELECT COUNT(*) FROM patients WHERE tenant_id = :t1) as total_patients,
                (SELECT COUNT(*) FROM prescriptions WHERE tenant_id = :t2 AND status = 'pending') as pending_prescriptions";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['t1' => $tenant_id, 't2' => $tenant_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}