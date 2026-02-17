<?php

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;

class ResolveTenant
{
    public function handle(Request $request, Response $response, array $params = []): void
    {
        // Get tenant ID from header
        $tenantCode = $request->getHeader('x-tenant-id');

        if (!$tenantCode) {
            $response->error('Missing X-Tenant-ID header', 400);
        }

        $db = Database::getInstance();
        $tenant = $db->fetch(
            'SELECT id, tenant_code, name, status FROM tenants WHERE tenant_code = :code',
            ['code' => $tenantCode]
        );

        if (!$tenant) {
            $response->error('Invalid tenant', 401);
        }

        if ($tenant['status'] !== 'active') {
            $response->error('Tenant is inactive', 403);
        }

        // Store tenant info in request
        $request->setAttribute('tenant_id', (int) $tenant['id']);
        $request->setAttribute('tenant_code', $tenant['tenant_code']);
        $request->setAttribute('tenant', $tenant);
    }
}