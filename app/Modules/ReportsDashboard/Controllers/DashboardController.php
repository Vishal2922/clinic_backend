<?php

namespace App\Modules\ReportsDashboard\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\ReportsDashboard\Models\DashboardStats;

/**
 * Dashboard Controller: Handles reporting and clinic statistics.
 * Access: Admin, Provider, and Pharmacist.
 */
class DashboardController extends Controller 
{
    private DashboardStats $model;

    public function __construct() 
    {
        // Dashboard statistics model initialization
        $this->model = new DashboardStats();
    }

    /**
     * AUDIT LOG HELPER: Clinic levels-la dashboard access-ah track panna.
     * Global helper function app_log-ai use pannuroam.
     */
    private function logActivity($userId, $tenantId, $action, $details): void 
    {
        if (function_exists('app_log')) {
            app_log("[AUDIT] user_id={$userId} tenant_id={$tenantId} action={$action} details={$details}");
        }
    }

    /**
     * GET /api/dashboard/stats
     * Merged Logic: Leverages Base Controller helpers for Auth and Response.
     */
    public function index(Request $request): void 
    {
        /**
         * 🔥 FIX: Merged Version
         * Manual getValidatedUser() logic badhula, Base Controller-la irukka
         * helpers use panni Request object-la irundhu data-vai edukkurhom.
         */
        $authUser = $this->getAuthUser(); 
        $tenantId = $this->getTenantId(); 

        // 1. Role Authorization Check
        if (!$this->checkRole(['Admin', 'Provider', 'Pharmacist'])) {
            Response::json([
                'status' => 'error',
                'message' => 'Access Denied: You do not have permission for this resource'
            ], 403);
            return;
        }

        try {
            // 2. Business Logic: Clinic statistics fetch panroam
            $stats = $this->model->getCounts($tenantId);

            // 3. Audit Logging: HIPAA compliance-kaaga access logs-ah save panrom
            $this->logActivity(
                $authUser['id'] ?? $authUser['user_id'],
                $tenantId,
                'VIEW_DASHBOARD',
                "Dashboard accessed by role: " . ($authUser['role_name'] ?? 'Unknown')
            );

            // 4. Merged Response helper: Success logic format-ah use panrom
            Response::json([
                'status' => 'success',
                'message' => 'Dashboard statistics retrieved',
                'data' => [
                    'stats' => $stats,
                    'accessed_by' => $authUser['role_name'] ?? 'Authorized User'
                ]
            ]);

        } catch (\Exception $e) {
            // 5. Unexpected errors-ah log panni, client-ku safe error message anuppuvom
            if (function_exists('app_log')) {
                app_log('Dashboard error: ' . $e->getMessage(), 'ERROR');
            }

            Response::json([
                'status' => 'error',
                'message' => 'Failed to load dashboard stats: ' . $e->getMessage()
            ], 500);
        }
    }
}