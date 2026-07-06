<?php

namespace App\Services;

use App\Models\EngageSetting;

/** Resolves the tenant for unauthenticated/public requests (single-tenant-per-deployment today). */
class TenantResolver
{
    public static function resolveDefault(): string
    {
        return EngageSetting::first()?->tenant_id ?? 'default';
    }
}
