<?php

namespace App\Modules\SuperAdmin\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\MasterDatabase;

class SuperAdminDashboardController extends Controller
{
    private MasterDatabase $masterDb;

    public function __construct()
    {
        $this->masterDb = MasterDatabase::getInstance();
    }

    public function index(): void
    {
        Response::json([
            'message' => 'Platform dashboard data retrieved.',
            'data'    => [
                'tenants' => [
                    'total'         => (int) $this->masterDb->fetchColumn('SELECT COUNT(*) FROM tenants'),
                    'active'        => (int) $this->masterDb->fetchColumn("SELECT COUNT(*) FROM tenants WHERE status = 'active'"),
                    'suspended'     => (int) $this->masterDb->fetchColumn("SELECT COUNT(*) FROM tenants WHERE status = 'suspended'"),
                    'trial'         => (int) $this->masterDb->fetchColumn("SELECT COUNT(*) FROM tenants WHERE plan = 'trial'"),
                    'expiring_soon' => (int) $this->masterDb->fetchColumn(
                        "SELECT COUNT(*) FROM tenants WHERE status = 'active' AND subscription_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)"
                    ),
                ],
                'plans_distribution' => $this->masterDb->fetchAll(
                    'SELECT plan, COUNT(*) as count FROM tenants WHERE status != "terminated" GROUP BY plan ORDER BY count DESC'
                ),
                'recent_tenants' => $this->masterDb->fetchAll(
                    'SELECT tenant_code, name, plan, status, created_at FROM tenants ORDER BY created_at DESC LIMIT 5'
                ),
                'audit_recent' => $this->masterDb->fetchAll(
                    'SELECT al.action, al.resource_type, al.created_at, sa.name as admin_name
                     FROM master_audit_logs al
                     LEFT JOIN super_admins sa ON sa.id = al.super_admin_id
                     ORDER BY al.created_at DESC LIMIT 10'
                ),
            ],
        ]);
    }

    public function auditLog(): void
    {
        // FIX: use getQuery() which returns full $_GET array
        $query  = $this->request->getQuery();
        $page   = max(1, (int)($query['page']  ?? 1));
        $limit  = min(100, (int)($query['limit'] ?? 20));
        $offset = ($page - 1) * $limit;

        $logs = $this->masterDb->fetchAll(
            'SELECT al.id, al.action, al.resource_type, al.resource_id, al.details,
                    al.ip_address, al.created_at,
                    sa.name as admin_name, sa.email as admin_email
             FROM master_audit_logs al
             LEFT JOIN super_admins sa ON sa.id = al.super_admin_id
             ORDER BY al.created_at DESC
             LIMIT :limit OFFSET :offset',
            ['limit' => $limit, 'offset' => $offset]
        );

        $total = (int) $this->masterDb->fetchColumn('SELECT COUNT(*) FROM master_audit_logs');

        Response::json([
            'message' => 'Audit log retrieved.',
            'data'    => [
                'logs'       => $logs,
                'pagination' => [
                    'total'       => $total,
                    'page'        => $page,
                    'limit'       => $limit,
                    'total_pages' => (int) ceil($total / $limit),
                ],
            ],
        ]);
    }
}