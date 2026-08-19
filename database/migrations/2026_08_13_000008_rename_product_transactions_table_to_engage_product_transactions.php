<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part of the engage_* / product_rental_* rename batch (2026_08_13_0000{02..16}).
 * The table's single unique constraint (engage_organization_location_id +
 * ghl_invoice_id, added unnamed by 2026_08_13_000001) got an
 * auto-generated, Postgres-truncated name — looked up dynamically via
 * pg_constraint rather than guessed, since hand-computing the exact
 * 63-byte-truncated identifier isn't reliable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('product_transactions', 'engage_product_transactions');

        $this->renameConstraint('product_transactions_eol_fk', 'engage_product_transactions_eol_fk');
        $this->renameUniqueConstraint('engage_product_transactions_eol_ghl_invoice_id_unique');
    }

    public function down(): void
    {
        $this->renameConstraint('engage_product_transactions_eol_fk', 'product_transactions_eol_fk');

        try {
            DB::statement('ALTER TABLE engage_product_transactions RENAME CONSTRAINT engage_product_transactions_eol_ghl_invoice_id_unique TO product_transactions_eol_ghl_invoice_id_unique');
        } catch (Throwable) {
            // ignore
        }

        Schema::rename('engage_product_transactions', 'product_transactions');
    }

    private function renameConstraint(string $from, string $to): void
    {
        try {
            DB::statement("ALTER TABLE engage_product_transactions RENAME CONSTRAINT {$from} TO {$to}");
        } catch (Throwable) {
            // ignore
        }
    }

    private function renameUniqueConstraint(string $to): void
    {
        $row = DB::selectOne(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'engage_product_transactions'::regclass AND contype = 'u' LIMIT 1"
        );

        if ($row) {
            DB::statement("ALTER TABLE engage_product_transactions RENAME CONSTRAINT {$row->conname} TO {$to}");
        }
    }
};
