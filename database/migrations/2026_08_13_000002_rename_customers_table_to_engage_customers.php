<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part of the engage_* / product_rental_* rename batch (2026_08_13_0000{02..16})
 * — pure renames, no data changes. Postgres preserves foreign keys pointing
 * at a renamed table automatically (tracked by object id, not name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('customers', 'engage_customers');

        DB::statement('ALTER INDEX IF EXISTS customers_email_unique RENAME TO engage_customers_email_unique');
    }

    public function down(): void
    {
        DB::statement('ALTER INDEX IF EXISTS engage_customers_email_unique RENAME TO customers_email_unique');

        Schema::rename('engage_customers', 'customers');
    }
};
