<?php

namespace App\Modules\SuperAdmin\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\MasterDatabase;
use App\Modules\SuperAdmin\Services\TenantProvisioningService;

class TenantController extends Controller
{
    private MasterDatabase            $masterDb;
    private TenantProvisioningService $provisionService;

    public function __construct()
    {
        $this->masterDb         = MasterDatabase::getInstance();
        $this->provisionService = new TenantProvisioningService();
    }

    // index() and store() have NO route params — no change needed
    public function index(): void
    {
        $query  = $this->request->getQuery();
        $page   = max(1, (int)($query['page']   ?? 1));
        $limit  = min(100, max(10, (int)($query['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where  = ['1=1'];
        $params = [];

        if (!empty($query['status'])) { $where[] = 'status = :status'; $params['status'] = $query['status']; }
        if (!empty($query['plan']))   { $where[] = 'plan = :plan';     $params['plan']   = $query['plan']; }
        if (!empty($query['search'])) {
            $where[]          = '(name LIKE :search OR tenant_code LIKE :search OR email LIKE :search)';
            $params['search'] = "%{$query['search']}%";
        }

        $whereStr = implode(' AND ', $where);
        $total    = (int) $this->masterDb->fetchColumn(
            "SELECT COUNT(*) FROM tenants WHERE {$whereStr}", $params
        );

        $params['limit']  = $limit;
        $params['offset'] = $offset;

        $tenants = $this->masterDb->fetchAll(
            "SELECT id, tenant_code, name, email, phone, city, state, country,
                    plan, status, max_users, max_patients, max_doctors,
                    subscription_starts_at, subscription_expires_at,
                    db_name, created_at, updated_at
             FROM tenants WHERE {$whereStr}
             ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
            $params
        );

        Response::json([
            'message' => 'Tenants retrieved successfully.',
            'data'    => [
                'tenants'    => $tenants,
                'pagination' => [
                    'total'       => $total,
                    'page'        => $page,
                    'limit'       => $limit,
                    'total_pages' => (int) ceil($total / $limit),
                ],
            ],
        ]);
    }

    public function store(): void
    {
        $data = $this->request->getBody();

        $errors = $this->validate($data, [
            'tenant_code' => 'required|min:3|max:30',
            'name'        => 'required|min:2|max:150',
            'email'       => 'required|email',
            'plan'        => 'required',
        ]);

        if (!empty($errors)) Response::error('Validation failed', 422, $errors);

        if (!in_array($data['plan'], ['trial','basic','standard','professional','enterprise'])) {
            Response::error('Invalid plan. Allowed: trial, basic, standard, professional, enterprise', 422);
        }

        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,29}$/', $data['tenant_code'])) {
            Response::error('Tenant code must start with a letter, contain only letters/numbers/underscores, 3-30 chars.', 422);
        }

        try {
            $result = $this->provisionService->provision($data, [
                'super_admin_id' => $this->request->getAttribute('super_admin_id'),
                'ip'             => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);

            Response::json([
                'message' => 'Tenant provisioned successfully. Share admin credentials securely.',
                'data'    => [
                    'tenant_id'   => $result['tenant_id'],
                    'tenant_code' => $result['tenant_code'],
                    'database'    => $result['database'],
                    'plan'        => $result['plan'],
                    'expires_at'  => $result['expires_at'],
                    'admin_credentials' => [
                        'email'    => $result['admin_email'],
                        'password' => $result['admin_password_plain'],
                        'note'     => 'Admin must change password on first login.',
                    ],
                ],
            ], 201);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            app_log('Tenant provisioning error: ' . $e->getMessage(), 'ERROR');
            Response::error('Tenant provisioning failed. Please check server logs.', 500);
        }
    }

    // ── All methods below have {code} route param ──
    // ── Router passes ($request, $code) — so $request MUST be first ──

    public function show(Request $request, string $code): void
    {
        $tenant = $this->masterDb->fetch(
            'SELECT id, tenant_code, name, email, phone, address, city, state, country,
                    plan, status, max_users, max_patients, max_doctors,
                    subscription_starts_at, subscription_expires_at,
                    db_host, db_port, db_name,
                    created_by_super_admin_id, created_at, updated_at
             FROM tenants WHERE tenant_code = :code',
            ['code' => $code]
        );

        if (!$tenant) Response::error('Tenant not found.', 404);

        unset($tenant['db_username'], $tenant['db_password']);

        if ($tenant['created_by_super_admin_id']) {
            $tenant['created_by'] = $this->masterDb->fetch(
                'SELECT name, email FROM super_admins WHERE id = :id',
                ['id' => $tenant['created_by_super_admin_id']]
            );
        }

        Response::json(['message' => 'Tenant details retrieved.', 'data' => $tenant]);
    }

    public function update(Request $request, string $code): void
    {
        $tenant = $this->masterDb->fetch(
            'SELECT id FROM tenants WHERE tenant_code = :code',
            ['code' => $code]
        );

        if (!$tenant) Response::error('Tenant not found.', 404);

        $data    = $this->request->getBody();
        $allowed = ['name', 'email', 'phone', 'address', 'city', 'state', 'country'];
        $updates = [];
        $params  = ['code' => $code];

        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $updates[]      = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($updates)) Response::error('No valid fields to update.', 422);

        $this->masterDb->execute(
            'UPDATE tenants SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE tenant_code = :code',
            $params
        );

        Response::json(['message' => 'Tenant updated successfully.']);
    }

    public function suspend(Request $request, string $code): void
    {
        $data    = $this->request->getBody();
        $success = $this->provisionService->suspendTenant(
            $code,
            $this->request->getAttribute('super_admin_id'),
            $data['reason'] ?? ''
        );

        if (!$success) Response::error('Tenant not found or already suspended.', 404);

        Response::json(['message' => "Tenant '{$code}' has been suspended."]);
    }

    public function reactivate(Request $request, string $code): void
    {
        $success = $this->provisionService->reactivateTenant(
            $code,
            $this->request->getAttribute('super_admin_id')
        );

        if (!$success) Response::error('Tenant not found or already active.', 404);

        Response::json(['message' => "Tenant '{$code}' has been reactivated."]);
    }

    public function changePlan(Request $request, string $code): void
    {
        $data = $this->request->getBody();

        if (empty($data['plan']) || !in_array($data['plan'], ['trial','basic','standard','professional','enterprise'])) {
            Response::error('Invalid plan. Allowed: trial, basic, standard, professional, enterprise', 422);
        }

        $result = $this->provisionService->changePlan(
            $code,
            $data['plan'],
            $this->request->getAttribute('super_admin_id')
        );

        Response::json([
            'message' => "Tenant plan updated to '{$data['plan']}'.",
            'data'    => $result,
        ]);
    }

    public function stats(Request $request, string $code): void
    {
        $tenant = $this->masterDb->fetch(
            'SELECT plan, status, max_users, max_patients, max_doctors,
                    db_host, db_port, db_name, db_username, db_password
             FROM tenants WHERE tenant_code = :code',
            ['code' => $code]
        );

        if (!$tenant) Response::error('Tenant not found.', 404);

        try {
            $tenantDb = \App\Core\TenantDatabase::getInstance($code, $tenant);
            $stats = [
                'users'              => (int) $tenantDb->fetchColumn('SELECT COUNT(*) FROM users WHERE is_active = 1'),
                'patients'           => (int) $tenantDb->fetchColumn('SELECT COUNT(*) FROM patients WHERE is_active = 1'),
                'doctors'            => (int) $tenantDb->fetchColumn('SELECT COUNT(*) FROM staff WHERE is_active = 1'),
                'appointments_today' => (int) $tenantDb->fetchColumn("SELECT COUNT(*) FROM appointments WHERE DATE(scheduled_at) = CURDATE()"),
                'appointments_total' => (int) $tenantDb->fetchColumn('SELECT COUNT(*) FROM appointments'),
                'invoices_total'     => (int) $tenantDb->fetchColumn('SELECT COUNT(*) FROM invoices'),
                'revenue_total'      => (float) $tenantDb->fetchColumn("SELECT COALESCE(SUM(paid_amount),0) FROM invoices WHERE status = 'paid'"),
                'plan_limits'        => [
                    'max_users'    => (int) $tenant['max_users'],
                    'max_patients' => (int) $tenant['max_patients'],
                    'max_doctors'  => (int) $tenant['max_doctors'],
                ],
            ];
        } catch (\Exception $e) {
            $stats = ['error' => 'Could not fetch tenant stats: ' . $e->getMessage()];
        }

        Response::json([
            'message' => 'Tenant stats retrieved.',
            'data'    => [
                'tenant_code' => $code,
                'plan'        => $tenant['plan'],
                'status'      => $tenant['status'],
                'stats'       => $stats,
            ],
        ]);
    }

    public function destroy(Request $request, string $code): void
    {
        if ($this->request->getAttribute('super_admin_role') !== 'superadmin') {
            Response::error('Only superadmin role can terminate tenants.', 403);
        }

        $tenant = $this->masterDb->fetch(
            'SELECT id FROM tenants WHERE tenant_code = :code',
            ['code' => $code]
        );

        if (!$tenant) Response::error('Tenant not found.', 404);

        $this->masterDb->execute(
            "UPDATE tenants SET status = 'terminated', updated_at = NOW() WHERE tenant_code = :code",
            ['code' => $code]
        );

        $this->masterDb->insert(
            'INSERT INTO master_audit_logs (super_admin_id, action, resource_type, details, ip_address, created_at)
             VALUES (:sa_id, "tenant_terminated", "tenant", :details, :ip, NOW())',
            [
                'sa_id'   => $this->request->getAttribute('super_admin_id'),
                'details' => json_encode(['tenant_code' => $code]),
                'ip'      => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]
        );

        Response::json(['message' => "Tenant '{$code}' has been terminated."]);
    }
}