<?php

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\MasterDatabase;
use App\Core\TenantDatabase;
use App\Core\TenantRegistry;

class ResolveTenant
{
    /**
     * Resolve tenant from subdomain or X-Tenant-ID header.
     *
     * Priority:
     *  1. Subdomain:  apollo_clinic.example.com  →  tenant_code = "apollo_clinic"
     *  2. Header:     X-Tenant-ID: apollo_clinic  →  fallback for API clients
     *
     * Set APP_DOMAIN in .env to the root domain (e.g., "example.com").
     * Requests to the bare root domain (no subdomain) MUST include the header.
     */
    public function handle(Request $request, Response $response, array $params = []): void
    {
        $tenantCode = $this->resolveFromSubdomain() ?? $request->getHeader('x-tenant-id');

        if (!$tenantCode) {
            Response::error('Missing tenant identifier. Provide a subdomain or X-Tenant-ID header.', 400);
            return;
        }

        $masterDb = MasterDatabase::getInstance();
        $tenant   = $masterDb->fetch(
            'SELECT id, tenant_code, name, status, plan, subscription_expires_at,
                    db_host, db_port, db_name, db_username, db_password
             FROM tenants
             WHERE tenant_code = :code',
            ['code' => $tenantCode]
        );

        if (!$tenant) {
            Response::error('Invalid tenant', 401);
            return;
        }

        if ($tenant['status'] !== 'active') {
            $messages = [
                'suspended'  => 'Tenant account has been suspended. Please contact support.',
                'inactive'   => 'Tenant account is inactive.',
                'terminated' => 'Tenant account has been terminated.',
                'pending'    => 'Tenant account setup is pending. Please check your email.',
            ];
            Response::error($messages[$tenant['status']] ?? 'Tenant is not active.', 403);
            return;
        }

        if (!empty($tenant['subscription_expires_at'])) {
            $expires = strtotime($tenant['subscription_expires_at']);
            if ($expires && $expires < time()) {
                Response::error('Tenant subscription has expired. Please renew to continue.', 402);
                return;
            }
        }

        try {
            TenantDatabase::getInstance($tenantCode, [
                'db_host'     => $tenant['db_host'],
                'db_port'     => $tenant['db_port'],
                'db_name'     => $tenant['db_name'],
                'db_username' => $tenant['db_username'],
                'db_password' => $tenant['db_password'],
            ]);
        } catch (\RuntimeException $e) {
            error_log("[ResolveTenant] DB connection failed for {$tenantCode}: " . $e->getMessage());
            Response::error('Tenant database unavailable. Please try again or contact support.', 503);
            return;
        }

        // Register the active tenant code globally so tenant_db() can resolve it
        TenantRegistry::set($tenantCode);

        $request->setAttribute('tenant_id',   (int) $tenant['id']);
        $request->setAttribute('tenant_code', $tenant['tenant_code']);
        $request->setAttribute('tenant_plan', $tenant['plan']);
        $request->setAttribute('tenant', [
            'id'          => (int) $tenant['id'],
            'name'        => $tenant['name'],
            'tenant_code' => $tenant['tenant_code'],
            'status'      => $tenant['status'],
            'plan'        => $tenant['plan'],
        ]);
    }

    /**
     * Extract tenant code from the request's subdomain.
     *
     * Example: host = "apollo_clinic.clinicapp.com", APP_DOMAIN = "clinicapp.com"
     *          → returns "apollo_clinic"
     *
     * Returns null when no subdomain is present or when running on
     * bare localhost / IP addresses (so the header fallback is used).
     */
    private function resolveFromSubdomain(): ?string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';

        // Strip port number if present (e.g., "apollo.localhost:8080")
        $host = strtolower(explode(':', $host)[0]);

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null; // IP address — can't extract subdomain
        }

        // APP_DOMAIN should be set in .env (e.g., "clinicapp.com" or "localhost")
        $rootDomain = strtolower(env('APP_DOMAIN', 'localhost'));

        // If host equals the root domain exactly, there is no subdomain
        if ($host === $rootDomain) {
            return null;
        }

        // Check if host ends with ".{rootDomain}"
        $suffix = '.' . $rootDomain;
        if (str_ends_with($host, $suffix)) {
            $subdomain = substr($host, 0, -strlen($suffix));
            // Only accept simple alphanumeric + underscore codes (matches tenant_code format)
            if ($subdomain !== '' && preg_match('/^[a-z0-9_]+$/', $subdomain)) {
                return $subdomain;
            }
        }

        return null;
    }
}