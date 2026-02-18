<?php
namespace App\Modules\ReportsDashboard\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Modules\ReportsDashboard\Models\DashboardStats;

class DashboardController extends Controller {
    private $model;

    public function __construct() {
        $this->model = new DashboardStats();
    }

    /**
     * AUDIT LOG HELPER
     */
    private function logActivity($userId, $tenantId, $action, $details) {
        app_log("[AUDIT] user_id={$userId} tenant_id={$tenantId} action={$action} details={$details}");
    }

    /**
     * GET /api/dashboard/stats
     * Accessible by Admin, Provider, Pharmacist.
     * AuthJWT + AuthorizeRole middleware already ran — no manual JWT needed.
     */
    public function index(Request $request) {
        $authUser = $this->getAuthUser(); // FIX: was manual JWT getValidatedUser()
        $tenantId = $this->getTenantId(); // FIX: was $request->tenant_id ?? 1

        try {
            $stats = $this->model->getCounts($tenantId);

            $this->logActivity(
                $authUser['user_id'],
                $tenantId,
                'VIEW_DASHBOARD',
                "Dashboard accessed by " . $authUser['role_name']
            );

            $this->response->success([
                'stats'       => $stats,
                'accessed_by' => $authUser['role_name'],
            ], 'Dashboard statistics retrieved');

        } catch (\Exception $e) {
            app_log('Dashboard error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to load dashboard stats.', 500);
        }
    }
}