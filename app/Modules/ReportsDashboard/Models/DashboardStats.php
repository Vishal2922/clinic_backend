<?php

namespace App\Modules\ReportsDashboard\Models;

class DashboardStats
{
    private function db(): \App\Core\TenantDatabase
    {
        return tenant_db();
    }

    public function getCounts(int $tenantId): array
    {
        $crypto = new \App\Core\Security\CryptoService();

        // ── Core counters ─────────────────────────────────────────────────────
        $counters = $this->db()->fetch(
            "SELECT
                /* Patients */
                (SELECT COUNT(*)
                 FROM patients
                 WHERE tenant_id = :t1 AND deleted_at IS NULL
                ) AS total_patients,

                (SELECT COUNT(*)
                 FROM patients
                 WHERE tenant_id = :t1b AND deleted_at IS NULL AND status = 'active'
                ) AS active_patients,

                /* Appointments */
                (SELECT COUNT(*)
                 FROM appointments
                 WHERE tenant_id = :t2 AND status = 'scheduled' AND deleted_at IS NULL
                ) AS upcoming_appointments,

                (SELECT COUNT(*)
                 FROM appointments
                 WHERE tenant_id = :t2b AND deleted_at IS NULL
                   AND DATE(appointment_time) = CURDATE()
                ) AS appointments_today,

                (SELECT COUNT(*)
                 FROM appointments
                 WHERE tenant_id = :t2c AND deleted_at IS NULL
                   AND status IN ('scheduled','arrived','in-consultation')
                ) AS active_appointments,

                /* Prescriptions */
                (SELECT COUNT(*)
                 FROM prescriptions
                 WHERE tenant_id = :t3 AND status = 'pending'
                ) AS pending_prescriptions,

                (SELECT COUNT(*)
                 FROM prescriptions
                 WHERE tenant_id = :t3b AND status = 'dispensed'
                ) AS dispensed_prescriptions,

                (SELECT COUNT(*)
                 FROM prescriptions
                 WHERE tenant_id = :t3c
                ) AS total_prescriptions,

                /* Staff */
                (SELECT COUNT(*)
                 FROM staff
                 WHERE tenant_id = :t4 AND status = 'active' AND deleted_at IS NULL
                ) AS active_staff,

                /* Billing */
                (SELECT COUNT(*)
                 FROM invoices
                 WHERE tenant_id = :t5 AND deleted_at IS NULL
                ) AS total_invoices,

                (SELECT COUNT(*)
                 FROM invoices
                 WHERE tenant_id = :t5b AND deleted_at IS NULL AND status = 'paid'
                ) AS paid_invoices,

                (SELECT COUNT(*)
                 FROM invoices
                 WHERE tenant_id = :t5c AND deleted_at IS NULL
                   AND status IN ('unpaid','pending')
                   AND due_date < CURDATE()
                ) AS overdue_invoices,

                (SELECT COUNT(*)
                 FROM invoices
                 WHERE tenant_id = :t5d AND deleted_at IS NULL
                   AND status IN ('unpaid','pending')
                   AND (due_date >= CURDATE() OR due_date IS NULL)
                ) AS open_invoices,

                (SELECT COUNT(*)
                 FROM invoices
                 WHERE tenant_id = :t5e AND deleted_at IS NULL AND status = 'cancelled'
                ) AS cancelled_invoices,

                (SELECT COUNT(*)
                 FROM invoices
                 WHERE tenant_id = :t5f AND deleted_at IS NULL AND status = 'partially_paid'
                ) AS partially_paid_invoices,

                (SELECT COUNT(*)
                 FROM invoices
                 WHERE tenant_id = :t5g AND deleted_at IS NULL AND status = 'refunded'
                ) AS refunded_invoices,

                (SELECT COALESCE(SUM(total_amount), 0)
                 FROM invoices
                 WHERE tenant_id = :t6 AND deleted_at IS NULL AND status = 'paid'
                   AND MONTH(paid_at) = MONTH(CURDATE())
                   AND YEAR(paid_at) = YEAR(CURDATE())
                ) AS revenue_this_month,

                (SELECT COALESCE(SUM(total_amount), 0)
                 FROM invoices
                 WHERE tenant_id = :t6b AND deleted_at IS NULL AND status = 'paid'
                ) AS total_revenue",
            [
                't1'  => $tenantId, 't1b' => $tenantId,
                't2'  => $tenantId, 't2b' => $tenantId, 't2c' => $tenantId,
                't3'  => $tenantId, 't3b' => $tenantId, 't3c' => $tenantId,
                't4'  => $tenantId,
                't5'  => $tenantId, 't5b' => $tenantId, 't5c' => $tenantId,
                't5d' => $tenantId, 't5e' => $tenantId, 't5f' => $tenantId, 't5g' => $tenantId,
                't6'  => $tenantId, 't6b' => $tenantId,
            ]
        );

        // ── Dashboard chart datasets (last 6 months) ─────────────────────────
        $invoiceStatusBreakdown = [
            [ 'key' => 'paid',            'label' => 'Paid',      'value' => (int) ($counters['paid_invoices'] ?? 0) ],
            [ 'key' => 'open',            'label' => 'On Time',  'value' => (int) ($counters['open_invoices'] ?? 0) ],
            [ 'key' => 'overdue',         'label' => 'Overdue',  'value' => (int) ($counters['overdue_invoices'] ?? 0) ],
            [ 'key' => 'partially_paid', 'label' => 'Partial',   'value' => (int) ($counters['partially_paid_invoices'] ?? 0) ],
            [ 'key' => 'refunded',        'label' => 'Refunded',  'value' => (int) ($counters['refunded_invoices'] ?? 0) ],
            [ 'key' => 'cancelled',       'label' => 'Cancelled', 'value' => (int) ($counters['cancelled_invoices'] ?? 0) ],
        ];

        $startDate = (new \DateTime('first day of this month'))->modify('-5 months')->format('Y-m-d');

        // Revenue trend uses received payments (paid_at) for better accuracy.
        $revenueRows = $this->db()->fetchAll(
            "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month,
                    COALESCE(SUM(total_amount), 0) AS revenue
             FROM invoices
             WHERE tenant_id = :tid AND deleted_at IS NULL
               AND status = 'paid'
               AND paid_at IS NOT NULL
               AND paid_at >= :start
             GROUP BY month
             ORDER BY month",
            ['tid' => $tenantId, 'start' => $startDate]
        );

        $revenueMap = [];
        foreach ($revenueRows as $row) {
            $revenueMap[$row['month']] = (float) ($row['revenue'] ?? 0);
        }

        $monthKeys = [];
        $dt = new \DateTime('first day of this month');
        for ($i = 5; $i >= 0; $i--) {
            $monthKeys[] = (clone $dt)->modify("-{$i} months")->format('Y-m');
        }

        $revenue_last_6_months = [];
        foreach ($monthKeys as $m) {
            $revenue_last_6_months[] = [
                'month'   => $m,
                'revenue' => $revenueMap[$m] ?? 0,
            ];
        }

        // Patients created trend uses patients.created_at.
        $patientRows = $this->db()->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
                    COUNT(*) AS cnt
             FROM patients
             WHERE tenant_id = :tid AND deleted_at IS NULL
               AND created_at >= :start
             GROUP BY month
             ORDER BY month",
            ['tid' => $tenantId, 'start' => $startDate]
        );

        $patientMap = [];
        foreach ($patientRows as $row) {
            $patientMap[$row['month']] = (int) ($row['cnt'] ?? 0);
        }

        $patients_created_last_6_months = [];
        foreach ($monthKeys as $m) {
            $patients_created_last_6_months[] = [
                'month' => $m,
                'count' => $patientMap[$m] ?? 0,
            ];
        }

        // ── Recent appointments (last 6) ──────────────────────────────────────
        $recentAppointments = $this->db()->fetchAll(
            "SELECT a.id, a.appointment_time, a.status,
                    a.patient_id, a.doctor_id,
                    p.encrypted_name AS enc_patient_name,
                    u.username        AS doctor_name
             FROM appointments a
             LEFT JOIN patients p ON a.patient_id = p.id
             LEFT JOIN users u    ON a.doctor_id  = u.id
             WHERE a.tenant_id = :tid AND a.deleted_at IS NULL
             ORDER BY a.appointment_time DESC
             LIMIT 6",
            ['tid' => $tenantId]
        );

        $recentAppointments = array_map(function ($row) use ($crypto) {
            try {
                if (!empty($row['enc_patient_name'])) {
                    $row['patient_name'] = $crypto->decrypt($row['enc_patient_name']);
                }
            } catch (\Exception $e) {
                $row['patient_name'] = "Patient #{$row['patient_id']}";
            }
            unset($row['enc_patient_name']);
            return $row;
        }, $recentAppointments);

        // ── Recent invoices (last 6) ──────────────────────────────────────────
        $recentInvoices = $this->db()->fetchAll(
            "SELECT i.id, i.invoice_number, i.amount, i.total_amount,
                    i.status, i.created_at, i.due_date,
                    p.encrypted_name AS enc_patient_name
             FROM invoices i
             LEFT JOIN patients p ON i.patient_id = p.id
             WHERE i.tenant_id = :tid AND i.deleted_at IS NULL
             ORDER BY i.created_at DESC
             LIMIT 6",
            ['tid' => $tenantId]
        );

        $recentInvoices = array_map(function ($row) use ($crypto) {
            try {
                if (!empty($row['enc_patient_name'])) {
                    $row['patient_name'] = $crypto->decrypt($row['enc_patient_name']);
                }
            } catch (\Exception $e) {
                $row['patient_name'] = '—';
            }
            unset($row['enc_patient_name']);
            return $row;
        }, $recentInvoices);

        // ── Recent prescriptions (last 5) ─────────────────────────────────────
        $recentPrescriptions = $this->db()->fetchAll(
            "SELECT p.id, p.status, p.created_at, p.duration_days,
                    p.patient_id, p.provider_id,
                    pt.encrypted_name     AS enc_patient_name,
                    u.username            AS provider_name,
                    p.encrypted_medicine_name
             FROM prescriptions p
             LEFT JOIN patients pt ON pt.id = p.patient_id AND pt.tenant_id = p.tenant_id
             LEFT JOIN users u     ON u.id  = p.provider_id
             WHERE p.tenant_id = :tid
             ORDER BY p.created_at DESC
             LIMIT 5",
            ['tid' => $tenantId]
        );

        $recentPrescriptions = array_map(function ($row) use ($crypto) {
            try {
                if (!empty($row['enc_patient_name'])) {
                    $row['patient_name'] = $crypto->decrypt($row['enc_patient_name']);
                }
                if (!empty($row['encrypted_medicine_name'])) {
                    $row['medicine_name'] = $crypto->decrypt($row['encrypted_medicine_name']);
                }
            } catch (\Exception $e) {
                $row['patient_name']  = "Patient #{$row['patient_id']}";
                $row['medicine_name'] = '[encrypted]';
            }
            unset($row['enc_patient_name'], $row['encrypted_medicine_name']);
            return $row;
        }, $recentPrescriptions);

        return array_merge($counters ?? [], [
            'invoice_status_breakdown'        => $invoiceStatusBreakdown,
            'revenue_last_6_months'           => $revenue_last_6_months,
            'patients_created_last_6_months' => $patients_created_last_6_months,
            'recent_appointments'  => $recentAppointments,
            'recent_invoices'      => $recentInvoices,
            'recent_prescriptions' => $recentPrescriptions,
        ]);
    }
}