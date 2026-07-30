<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Location / SaaS user seeders are intentionally NOT called here — run them
     * manually after filling .env:
     *   php artisan db:seed --class=EngageOrganizationLocationSeeder
     *   php artisan db:seed --class=SuperAdminSeeder
     *   php artisan db:seed --class=EngageLocationOwnerSeeder
     */
    public function run(): void
    {
        $this->call([
            EngageSettingSeeder::class,
            EngageTokenSeeder::class,
            EngageOrganizationLocationSeeder::class,
            EngageLocationOwnerSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
