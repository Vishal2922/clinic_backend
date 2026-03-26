<?php

namespace App\Modules\ReportsDashboard\Models;

class DashboardStats
{
    private function db(): \App\Core\TenantDatabase
    {
        return tenant_db();
    }

    /**
     * Get dashboard statistics filtered by role and owner.
     * 
     * @param int $tenantId
     * @param string $role
     * @param int $ownerId (patient_id for Patient, user_id for Staff)
     */
    public function getCounts(int $tenantId, string $role, int $ownerId = 0): array
    {
        $db     = $this->db();
        $crypto = new \App\Core\Security\CryptoService();

        // Base parameters used by almost everyone
        $baseParams = ['tid' => $tenantId];
        
        // Counter Query parameters
        $counterParams = $baseParams;
        if ($role === 'Patient')  $counterParams['pid'] = $ownerId;
        if ($role === 'Provider') $counterParams['uid'] = $ownerId;

        // Role-based filter snippets
        $pFilter  = ($role === 'Patient') ? "AND id = :pid" : "";
        $paFilter = ($role === 'Patient') ? "AND patient_id = :pid" : "";
        $dFilter  = ($role === 'Provider') ? "AND doctor_id = :uid" : "";
        $uFilter  = ($role === 'Provider') ? "AND provider_id = :uid" : "";
        
        // Complex combining for clinical data
        $clPatientWhere = ($role === 'Patient') ? "AND patient_id = :pid" : "";
        $clProviderWhere = ($role === 'Provider') ? "AND provider_id = :uid" : "";
        $clFilter = $clPatientWhere . $clProviderWhere;

        // 1. Core counters
        $counts = $db->fetch(
            "SELECT 
                (SELECT COUNT(*) FROM patients WHERE tenant_id = :tid AND deleted_at IS NULL {$pFilter}) AS total_patients,
                (SELECT COUNT(*) FROM patients WHERE tenant_id = :tid AND deleted_at IS NULL AND status = 'active' {$pFilter}) AS active_patients,
                (SELECT COUNT(*) FROM appointments WHERE tenant_id = :tid AND deleted_at IS NULL {$paFilter}) AS total_appointments,
                (SELECT COUNT(*) FROM appointments WHERE tenant_id = :tid AND deleted_at IS NULL AND DATE(appointment_time) = CURDATE() {$paFilter}) AS appointments_today,
                (SELECT COUNT(*) FROM appointments WHERE tenant_id = :tid AND deleted_at IS NULL AND appointment_time >= NOW() " . ($role === 'Patient' ? "AND patient_id = :pid" : ($role === 'Provider' ? "AND doctor_id = :uid" : "")) . ") AS upcoming_appointments,
                (SELECT COUNT(*) FROM staff WHERE tenant_id = :tid AND status = 'active' AND deleted_at IS NULL) AS active_staff,
                (SELECT COUNT(*) FROM prescriptions WHERE tenant_id = :tid {$clFilter}) AS total_prescriptions,
                (SELECT COUNT(*) FROM prescriptions WHERE tenant_id = :tid AND status = 'pending' {$clFilter}) AS pending_prescriptions,
                (SELECT COUNT(*) FROM prescriptions WHERE tenant_id = :tid AND status = 'dispensed' {$clFilter}) AS dispensed_prescriptions,
                (SELECT COUNT(*) FROM invoices WHERE tenant_id = :tid AND deleted_at IS NULL {$paFilter} {$uFilter}) AS total_invoices,
                (SELECT COUNT(*) FROM invoices WHERE tenant_id = :tid AND deleted_at IS NULL AND status = 'paid' {$paFilter} {$uFilter}) AS paid_invoices,
                (SELECT COUNT(*) FROM invoices WHERE tenant_id = :tid AND deleted_at IS NULL AND status = 'overdue' {$paFilter} {$uFilter}) AS overdue_invoices,
                (SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE tenant_id = :tid AND deleted_at IS NULL AND status = 'paid' AND MONTH(paid_at) = MONTH(CURDATE()) AND YEAR(paid_at) = YEAR(CURDATE()) {$paFilter} {$uFilter}) AS revenue_this_month,
                (SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE tenant_id = :tid AND deleted_at IS NULL AND status = 'paid' {$paFilter} {$uFilter}) AS total_revenue",
            $counterParams
        ) ?: [];

        // 2. Trends (Last 6 Months)
        $startDate = date('Y-m-01 00:00:00', strtotime('-5 months'));
        $monthKeys = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthKeys[] = date('Y-m', strtotime("-$i months"));
        }

        // Revenue trend parameters
        $revTrendParams = ['tid' => $tenantId, 'start' => $startDate];
        $revWhere = "WHERE i.tenant_id = :tid AND i.deleted_at IS NULL";
        if ($role === 'Patient') {
            $revWhere .= " AND i.patient_id = :pid";
            $revTrendParams['pid'] = $ownerId;
        } elseif ($role === 'Provider') {
            $revWhere .= " AND i.provider_id = :uid";
            $revTrendParams['uid'] = $ownerId;
        }

        $revenueRows = $db->fetchAll(
            "SELECT DATE_FORMAT(COALESCE(i.paid_at, i.created_at), '%Y-%m') AS month, COALESCE(SUM(i.total_amount), 0) AS revenue
             FROM invoices i {$revWhere} AND i.status = 'paid' AND COALESCE(i.paid_at, i.created_at) >= :start
             GROUP BY month ORDER BY month DESC LIMIT 6",
            $revTrendParams
        );
        $revenueMap = array_column($revenueRows, 'revenue', 'month');

        $revenue_last_6_months = [];
        foreach ($monthKeys as $m) {
            $revenue_last_6_months[] = [
                'month'   => $m,
                'revenue' => (float)($revenueMap[$m] ?? 0),
            ];
        }

        // 3. Invoice status breakdown (for Pie chart)
        $invStatusParams = ['tid' => $tenantId];
        $invStatusWhere  = "WHERE tenant_id = :tid AND deleted_at IS NULL";
        if ($role === 'Patient') {
            $invStatusWhere .= " AND patient_id = :pid";
            $invStatusParams['pid'] = $ownerId;
        } elseif ($role === 'Provider') {
            $invStatusWhere .= " AND provider_id = :uid";
            $invStatusParams['uid'] = $ownerId;
        }

        $statusRows = $db->fetchAll(
            "SELECT status AS `key`, status AS label, COUNT(*) AS value 
             FROM invoices 
             {$invStatusWhere}
             GROUP BY status",
            $invStatusParams
        );

        // 4. Patient trend parameters (Admins/Clinic-wide)
        $patTrendParams = ['tid' => $tenantId, 'start' => $startDate];
        $patWhere = "WHERE p.tenant_id = :tid AND p.deleted_at IS NULL";
        if ($role === 'Patient') {
            $patWhere .= " AND p.id = :pid";
            $patTrendParams['pid'] = $ownerId;
        }

        $patientRows = $db->fetchAll(
            "SELECT DATE_FORMAT(p.created_at, '%Y-%m') AS month, COUNT(*) AS cnt
             FROM patients p {$patWhere} AND p.created_at >= :start
             GROUP BY month ORDER BY month DESC LIMIT 6",
            $patTrendParams
        );
        $patientMap = array_column($patientRows, 'cnt', 'month');

        $patients_created_last_6_months = [];
        foreach ($monthKeys as $m) {
            $patients_created_last_6_months[] = [
                'month' => $m,
                'count' => (int)($patientMap[$m] ?? 0),
            ];
        }

        // 3. Recent Activity Lists
        
        // Recent Appt Params
        $apptParams = ['tid' => $tenantId];
        $apptWhere  = "WHERE a.tenant_id = :tid AND a.deleted_at IS NULL";
        if ($role === 'Patient') {
            $apptWhere .= " AND a.patient_id = :pid";
            $apptParams['pid'] = $ownerId;
        } elseif ($role === 'Provider') {
            $apptWhere .= " AND a.doctor_id = :uid";
            $apptParams['uid'] = $ownerId;
        }

        $recentAppointments = $db->fetchAll(
            "SELECT a.*, pt.encrypted_name AS enc_patient_name, u.username AS doctor_name
             FROM appointments a
             LEFT JOIN patients pt ON pt.id = a.patient_id AND pt.tenant_id = a.tenant_id
             LEFT JOIN users u    ON a.doctor_id  = u.id
             {$apptWhere}
             ORDER BY a.appointment_time DESC LIMIT 5",
            $apptParams
        );
        $recentAppointments = array_map(function ($row) use ($crypto) {
            try {
                $row['patient_name'] = !empty($row['enc_patient_name']) ? $crypto->decrypt($row['enc_patient_name']) : "Patient #{$row['patient_id']}";
            } catch (\Exception $e) {
                $row['patient_name'] = "Patient #{$row['patient_id']}";
            }
            unset($row['enc_patient_name']);
            return $row;
        }, $recentAppointments);

        // Recent Invoice Params
        $invParams = ['tid' => $tenantId];
        $invWhere  = "WHERE i.tenant_id = :tid AND i.deleted_at IS NULL";
        if ($role === 'Patient') {
            $invWhere .= " AND i.patient_id = :pid";
            $invParams['pid'] = $ownerId;
        } elseif ($role === 'Provider') {
            $invWhere .= " AND i.provider_id = :uid";
            $invParams['uid'] = $ownerId;
        }

        $recentInvoices = $db->fetchAll(
            "SELECT i.*, pt.encrypted_name AS enc_patient_name 
             FROM invoices i
             LEFT JOIN patients pt ON pt.id = i.patient_id AND pt.tenant_id = i.tenant_id
             {$invWhere}
             ORDER BY i.created_at DESC LIMIT 5",
            $invParams
        );
        $recentInvoices = array_map(function ($row) use ($crypto) {
            try {
                $row['patient_name'] = !empty($row['enc_patient_name']) ? $crypto->decrypt($row['enc_patient_name']) : "Patient #{$row['patient_id']}";
            } catch (\Exception $e) {
                $row['patient_name'] = "Patient #{$row['patient_id']}";
            }
            unset($row['enc_patient_name']);
            return $row;
        }, $recentInvoices);

        // Recent Prescription Params
        $rxParams = ['tid' => $tenantId];
        $rxWhere  = "WHERE p.tenant_id = :tid";
        if ($role === 'Patient') {
            $rxWhere .= " AND p.patient_id = :pid";
            $rxParams['pid'] = $ownerId;
        } elseif ($role === 'Provider') {
            $rxWhere .= " AND p.provider_id = :uid";
            $rxParams['uid'] = $ownerId;
        }

        $recentPrescriptions = $db->fetchAll(
            "SELECT p.*, pt.encrypted_name AS enc_patient_name 
             FROM prescriptions p
             LEFT JOIN patients pt ON pt.id = p.patient_id AND pt.tenant_id = p.tenant_id
             {$rxWhere}
             ORDER BY p.created_at DESC LIMIT 5",
            $rxParams
        );
        $recentPrescriptions = array_map(function ($row) use ($crypto) {
            try {
                $row['patient_name']  = !empty($row['enc_patient_name']) ? $crypto->decrypt($row['enc_patient_name']) : "Patient #{$row['patient_id']}";
                $row['medicine_name'] = !empty($row['encrypted_medicine_name']) ? $crypto->decrypt($row['encrypted_medicine_name']) : '[encrypted]';
            } catch (\Exception $e) {
                $row['patient_name']  = "Patient #{$row['patient_id']}";
                $row['medicine_name'] = '[encrypted]';
            }
            unset($row['enc_patient_name'], $row['encrypted_medicine_name']);
            return $row;
        }, $recentPrescriptions);

        return array_merge($counts, [
            'revenue_last_6_months'          => $revenue_last_6_months,
            'patients_created_last_6_months' => $patients_created_last_6_months,
            'invoice_status_breakdown'       => $statusRows,
            'recent_appointments'            => $recentAppointments,
            'recent_invoices'                => $recentInvoices,
            'recent_prescriptions'           => $recentPrescriptions,
        ]);
    }
}