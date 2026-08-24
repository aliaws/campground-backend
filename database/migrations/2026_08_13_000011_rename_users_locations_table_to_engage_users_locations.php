<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Part of the engage_* / product_rental_* rename batch (2026_08_13_0000{02..16}). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('users_locations', 'engage_users_locations');

        $this->renameConstraint('users_locations_eol_fk', 'engage_users_locations_eol_fk');
        $this->renameConstraint('users_locations_user_location_unique', 'engage_users_locations_user_location_unique');
    }

    public function down(): void
    {
        $this->renameConstraint('engage_users_locations_eol_fk', 'users_locations_eol_fk');
        $this->renameConstraint('engage_users_locations_user_location_unique', 'users_locations_user_location_unique');

        Schema::rename('engage_users_locations', 'users_locations');
    }

    private function renameConstraint(string $from, string $to): void
    {
        try {
            DB::statement("ALTER TABLE engage_users_locations RENAME CONSTRAINT {$from} TO {$to}");
        } catch (Throwable) {
            // ignore
        }
    }
};
