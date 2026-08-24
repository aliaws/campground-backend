<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Part of the engage_* / product_rental_* rename batch (2026_08_13_0000{02..16}). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('customers_locations', 'engage_customers_locations');

        $this->renameConstraint('customers_locations_eol_fk', 'engage_customers_locations_eol_fk');
        $this->renameConstraint('customers_locations_customer_location_unique', 'engage_customers_locations_customer_location_unique');
    }

    public function down(): void
    {
        $this->renameConstraint('engage_customers_locations_eol_fk', 'customers_locations_eol_fk');
        $this->renameConstraint('engage_customers_locations_customer_location_unique', 'customers_locations_customer_location_unique');

        Schema::rename('engage_customers_locations', 'customers_locations');
    }

    /** Best-effort — no-ops silently if the name doesn't match (e.g. already renamed, or Postgres truncated it). */
    private function renameConstraint(string $from, string $to): void
    {
        try {
            DB::statement("ALTER TABLE engage_customers_locations RENAME CONSTRAINT {$from} TO {$to}");
        } catch (Throwable) {
            // ignore
        }
    }
};
