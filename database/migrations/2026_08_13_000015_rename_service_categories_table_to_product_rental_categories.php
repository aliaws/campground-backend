<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part of the engage_* / product_rental_* rename batch (2026_08_13_0000{02..16}).
 * Same dynamic-lookup approach as the transactions tables for the single
 * (auto-named, truncated) unique constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('service_categories', 'product_rental_categories');

        $this->renameConstraint('service_categories_eol_fk', 'product_rental_categories_eol_fk');
        $this->renameUniqueConstraint('product_rental_categories_eol_ghl_category_id_unique');
    }

    public function down(): void
    {
        $this->renameConstraint('product_rental_categories_eol_fk', 'service_categories_eol_fk');

        try {
            DB::statement('ALTER TABLE product_rental_categories RENAME CONSTRAINT product_rental_categories_eol_ghl_category_id_unique TO service_categories_eol_ghl_category_id_unique');
        } catch (Throwable) {
            // ignore
        }

        Schema::rename('product_rental_categories', 'service_categories');
    }

    private function renameConstraint(string $from, string $to): void
    {
        try {
            DB::statement("ALTER TABLE product_rental_categories RENAME CONSTRAINT {$from} TO {$to}");
        } catch (Throwable) {
            // ignore
        }
    }

    private function renameUniqueConstraint(string $to): void
    {
        $row = DB::selectOne(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'product_rental_categories'::regclass AND contype = 'u' LIMIT 1"
        );

        if ($row) {
            DB::statement("ALTER TABLE product_rental_categories RENAME CONSTRAINT {$row->conname} TO {$to}");
        }
    }
};
