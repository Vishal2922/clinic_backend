<?php

namespace App\Modules\ReportsDashboard\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\ReportsDashboard\Models\DashboardStats;

/**
 * Dashboard Controller: Handles reporting and clinic statistics.
 * Access: All authenticated staff (route middleware: $allAuthenticated).
 * Data is filtered by role — each role only sees stats relevant to them.
 */
class DashboardController extends Controller 
{
    private DashboardStats $model;

    public function __construct() 
    {
        $this->model = new DashboardStats();
    }

    private function logActivity($userId, $tenantId, $action, $details): void 
    {
        if (function_exists('app_log')) {
            app_log("[AUDIT] user_id={$userId} tenant_id={$tenantId} action={$action} details={$details}");
        }
    }

    /**
     * GET /api/dashboard/stats
     * Returns role-filtered stats so each role sees only what they need.
     *
     * Admin:        everything
     * Provider:     patients, appointments, prescriptions (no billing/staff)
     * Nurse:        patients, appointments (no billing/prescriptions/staff)
     * Receptionist: patients, appointments (no billing/prescriptions/staff)
     * Pharmacist:   prescriptions (no patients/appointments/billing/staff)
     */
    public function index(Request $request): void 
    {
        $authUser = $this->getAuthUser(); 
        $tenantId = $this->getTenantId(); 

        if (!$authUser || !$tenantId) {
            Response::json([
                'status' => 'error',
                'message' => 'Unauthorized: Missing session or tenant context'
            ], 401);
            return;
        }

        $role     = $authUser['role_name'] ?? $authUser['role'] ?? '';

        // Determine owner context for filtering
        $ownerId = (int) ($authUser['id'] ?? $authUser['user_id'] ?? 0);
        
        // Validation: If non-admin doesn't have an ID, we might have a problem, 
        // but we'll let the model handle ownerId=0 as "no results" rather than crashing.

        try {
            // Fetch filtered stats from model
            $allStats = $this->model->getCounts((int)$tenantId, (string)$role, $ownerId);

            // Filter by role (strip disallowed domains)
            $stats = $this->filterByRole($allStats, $role);

            $this->logActivity(
                $authUser['id'] ?? $authUser['user_id'],
                $tenantId,
                'VIEW_DASHBOARD',
                "Dashboard accessed by role: {$role} (owner_id: " . ($ownerId ?: 'none') . ")"
            );

            Response::json([
                'status' => 'success',
                'message' => 'Dashboard statistics retrieved',
                'data' => [
                    'stats'       => $stats,
                    'role'        => $role,
                    'accessed_by' => $role ?: 'Authorized User',
                ]
            ]);

        } catch (\Exception $e) {
            if (function_exists('app_log')) {
                app_log('Dashboard error: ' . $e->getMessage(), 'ERROR');
            }

            Response::json([
                'status' => 'error',
                'message' => 'Failed to load dashboard stats: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Strip stats fields that the given role should not see.
     */
    private function filterByRole(array $stats, string $role): array
    {
        // Admin sees everything
        if ($role === 'Admin') {
            return $stats;
        }

        // Fields grouped by domain
        $billingFields = [
            'total_invoices', 'paid_invoices', 'overdue_invoices',
            'revenue_this_month', 'total_revenue', 'recent_invoices',
            'invoice_status_breakdown', 'revenue_last_6_months',
        ];
        $prescriptionFields = [
            'total_prescriptions', 'pending_prescriptions',
            'dispensed_prescriptions', 'recent_prescriptions',
        ];
        $staffFields = ['active_staff'];
        $patientFields = ['total_patients', 'active_patients'];
        $appointmentFields = [
            'upcoming_appointments', 'appointments_today',
            'active_appointments', 'recent_appointments',
        ];
        $patientTrendFields = ['patients_created_last_6_months'];

        // Define what each role CAN see
        $allowed = match ($role) {
            'Provider' => array_merge(
                $patientFields, $patientTrendFields, $appointmentFields, $prescriptionFields
            ),
            'Nurse' => array_merge(
                $patientFields, $patientTrendFields, $appointmentFields
            ),
            'Receptionist' => array_merge(
                $patientFields, $patientTrendFields, $appointmentFields
            ),
            'Pharmacist' => $prescriptionFields,
            'Patient' => array_merge(
                $appointmentFields, $prescriptionFields, $billingFields
            ),
            default => [], // Unknown role: no stats
        };

        // Keep only allowed keys
        $filtered = [];
        foreach ($stats as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}