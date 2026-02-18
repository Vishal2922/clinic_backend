<?php

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;

/**
 * ResolveTenant Middleware: Fixed Version.
 * Bug Fixed:
 * 1. $response->error() called as instance method — changed to Response::error() static call.
 */
class ResolveTenant
{
    public function handle(Request $request, Response $response, array $params = []): void
    {
        $tenantCode = $request->getHeader('x-tenant-id');

        if (!$tenantCode) {
            Response::error('Missing X-Tenant-ID header', 400);
            return;
        }

        $db     = Database::getInstance();
        $tenant = $db->fetch(
            'SELECT id, tenant_code, name, status FROM tenants WHERE tenant_code = :code',
            ['code' => $tenantCode]
        );

        if (!$tenant) {
            Response::error('Invalid tenant', 401);
            return;
        }

        if ($tenant['status'] !== 'active') {
            Response::error('Tenant is inactive', 403);
            return;
        }

        $request->setAttribute('tenant_id',   (int) $tenant['id']);
        $request->setAttribute('tenant_code', $tenant['tenant_code']);
        $request->setAttribute('tenant',      $tenant);
    }
}