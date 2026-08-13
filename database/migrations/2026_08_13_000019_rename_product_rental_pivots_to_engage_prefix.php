<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up to the engage_* / product_rental_* rename batch — the three
 * product_rental_* tables get the engage_ prefix too, for consistency with
 * the rest of the renamed table set (engage_product_rentals,
 * engage_products, etc.). product_rental_categories carries two explicitly
 * named constraints from its own earlier rename (service_categories ->
 * product_rental_categories) that get renamed again here too, same
 * best-effort pattern as that migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('product_rental_amenities', 'engage_product_rental_amenities');
        Schema::rename('product_rental_categories', 'engage_product_rental_categories');
        Schema::rename('product_rental_features', 'engage_product_rental_features');

        $this->renameConstraint('product_rental_categories_eol_fk', 'engage_product_rental_categories_eol_fk');
        $this->renameConstraint('product_rental_categories_eol_ghl_category_id_unique', 'engage_product_rental_categories_eol_ghl_category_id_unique');
    }

    public function down(): void
    {
        $this->renameConstraint('engage_product_rental_categories_eol_fk', 'product_rental_categories_eol_fk');
        $this->renameConstraint('engage_product_rental_categories_eol_ghl_category_id_unique', 'product_rental_categories_eol_ghl_category_id_unique');

        Schema::rename('engage_product_rental_features', 'product_rental_features');
        Schema::rename('engage_product_rental_categories', 'product_rental_categories');
        Schema::rename('engage_product_rental_amenities', 'product_rental_amenities');
    }

    private function renameConstraint(string $from, string $to): void
    {
        try {
            DB::statement("ALTER TABLE engage_product_rental_categories RENAME CONSTRAINT {$from} TO {$to}");
        } catch (Throwable) {
            // ignore — best effort, matches the sibling rename migrations' convention
        }
    }
};
