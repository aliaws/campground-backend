<?php

namespace Database\Seeders;

use App\Models\EngageSetting;
use App\Models\User;
use App\Services\GhlAuthService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EngageSettingSeeder extends Seeder
{
    public function run(): void
    {
        if (EngageSetting::query()->exists()) {
            $this->command?->info('engage_settings already has a record — skipping.');

            return;
        }

        $tenantId = config('engage.tenant_id')
            ?: User::query()->whereNotNull('tenant_id')->value('tenant_id')
            ?: (string) Str::ulid();

        $scopes = config('engage.scopes', []);
        if (! is_array($scopes) || $scopes === []) {
            $scopes = app(GhlAuthService::class)->getScopes();
        }

        EngageSetting::create([
            'tenant_id' => $tenantId,
            'client_id' => config('engage.client_id'),
            'client_secret' => config('engage.client_secret'),
            'api_version' => config('engage.api_version', '2021-07-28'),
            'api_base_url' => config('engage.api_base_url', 'https://services.leadconnectorhq.com/'),
            'timezone' => config('engage.timezone', 'America/New_York'),
            'scopes' => $scopes,
        ]);

        $this->command?->info("engage_settings seeded for tenant {$tenantId}.");
    }
}
