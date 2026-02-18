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
class DashboardController extends Controller {
    private $model;

    public function __construct() {
        // Dashboard statistics model
        $this->model = new DashboardStats();
    }

    /**
     * AUDIT LOG HELPER: Clinic levels-la dashboard access-ah track panna.
     */
    private function logActivity($userId, $tenantId, $action, $details) {
        // Global helper function app_log (functions.php-la irukkum)
        if (function_exists('app_log')) {
            app_log("[AUDIT] user_id={$userId} tenant_id={$tenantId} action={$action} details={$details}");
        }
    }

    /**
     * GET /api/dashboard/stats
     * Merged Logic: Uses AuthJWT Middleware for validation.
     */
    public function index(Request $request) {
        /**
         * 🔥 FIX: Merged Version
         * Manual getValidatedUser() logic badhula, Base Controller-la irukka
         * helpers use panni Request object-la irundhu data-vai edukkurhom.
         */
        $authUser = $this->getAuthUser(); 
        $tenantId = $this->getTenantId(); 

        try {
            // Clinic data (counts of patients, appointments, etc.)-ah get panrom
            $stats = $this->model->getCounts($tenantId);

            // Audit logging: HIPAA compliance-kaaga access logs-ah save panrom
            $this->logActivity(
                $authUser['user_id'],
                $tenantId,
                'VIEW_DASHBOARD',
                "Dashboard accessed by role: " . $authUser['role_name']
            );

            // Merged Response helper: Success logic format-ah use panrom
            Response::json([
                'status' => 'success',
                'message' => 'Dashboard statistics retrieved',
                'data' => [
                    'stats' => $stats,
                    'accessed_by' => $authUser['role_name']
                ]
            ]);

        } catch (\Exception $e) {
            // Unexpected errors-ah log panni, client-ku safe error message anuppuvom
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