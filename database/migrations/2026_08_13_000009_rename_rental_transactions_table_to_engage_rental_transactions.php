<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part of the engage_* / product_rental_* rename batch (2026_08_13_0000{02..16}).
 * Same dynamic-lookup approach as the product_transactions rename for its
 * single (auto-named, truncated) unique constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('rental_transactions', 'engage_rental_transactions');

        $this->renameConstraint('rental_transactions_eol_fk', 'engage_rental_transactions_eol_fk');
        $this->renameUniqueConstraint('engage_rental_transactions_eol_ghl_invoice_id_unique');
    }

    public function down(): void
    {
        $this->renameConstraint('engage_rental_transactions_eol_fk', 'rental_transactions_eol_fk');

        try {
            DB::statement('ALTER TABLE engage_rental_transactions RENAME CONSTRAINT engage_rental_transactions_eol_ghl_invoice_id_unique TO rental_transactions_eol_ghl_invoice_id_unique');
        } catch (Throwable) {
            // ignore
        }

        Schema::rename('engage_rental_transactions', 'rental_transactions');
    }

    private function renameConstraint(string $from, string $to): void
    {
        try {
            DB::statement("ALTER TABLE engage_rental_transactions RENAME CONSTRAINT {$from} TO {$to}");
        } catch (Throwable) {
            // ignore
        }
    }

    private function renameUniqueConstraint(string $to): void
    {
        $row = DB::selectOne(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'engage_rental_transactions'::regclass AND contype = 'u' LIMIT 1"
        );

        if ($row) {
            DB::statement("ALTER TABLE engage_rental_transactions RENAME CONSTRAINT {$row->conname} TO {$to}");
        }
    }
};
