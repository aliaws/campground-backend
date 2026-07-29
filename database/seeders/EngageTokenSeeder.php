<?php

namespace Database\Seeders;

use App\Models\EngageSetting;
use App\Models\EngageToken;
use Illuminate\Database\Seeder;

class EngageTokenSeeder extends Seeder
{
    public function run(): void
    {
        if (EngageToken::query()->exists()) {
            $this->command?->info('engage_tokens already has a record — skipping.');

            return;
        }

        $setting = EngageSetting::query()->first();

        if (! $setting) {
            $this->command?->warn('No engage_settings row found — run EngageSettingSeeder first. Skipping engage_tokens.');

            return;
        }

        EngageToken::create([
            'tenant_id' => $setting->tenant_id,
            'engage_setting_id' => $setting->id,
            'location_id' => config('engage.location_id'),
            'company_id' => config('engage.company_id'),
            'user_id' => config('engage.user_id'),
            'authorization_code' => config('engage.authorization_code'),
            'access_token' => config('engage.access_token'),
            'refresh_token' => config('engage.refresh_token'),
        ]);

        $this->command?->info("engage_tokens seeded for tenant {$setting->tenant_id}.");
    }
}
