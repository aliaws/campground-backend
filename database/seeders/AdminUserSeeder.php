<?php

namespace Database\Seeders;

use App\Models\EngageSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates a login-ready admin and staff user. Uses the same tenant every
 * other real record in this deployment belongs to (the first EngageSetting's
 * tenant_id — same convention as GhlService::resolveTenantId()/
 * TenantResolver::resolveDefault()), so these users actually see the
 * existing products/customers/bookings instead of an empty tenant. Falls
 * back to a fresh tenant_id on a completely empty database.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = EngageSetting::first()?->tenant_id ?? (string) Str::ulid();

        User::updateOrCreate(
            ['email' => 'admin@campgorund.com'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'role' => 'admin',
                'tenant_id' => $tenantId,
            ]
        );

        User::updateOrCreate(
            ['email' => 'staff@campgorund.com'],
            [
                'name' => 'Staff',
                'password' => 'password',
                'role' => 'staff',
                'tenant_id' => $tenantId,
            ]
        );
    }
}
