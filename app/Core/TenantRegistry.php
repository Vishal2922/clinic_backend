<?php

namespace App\Core;

/**
 * TenantRegistry
 *
 * A simple static registry that holds the currently active tenant code
 * for the duration of a single request. Set by ResolveTenant middleware.
 * Read by tenant_db() helper and all tenant-scoped models.
 */
class TenantRegistry
{
    private static ?string $tenantCode = null;

    public static function set(string $tenantCode): void
    {
        self::$tenantCode = $tenantCode;
    }

    public static function getCode(): ?string
    {
        return self::$tenantCode;
    }

    public static function clear(): void
    {
        self::$tenantCode = null;
    }
}