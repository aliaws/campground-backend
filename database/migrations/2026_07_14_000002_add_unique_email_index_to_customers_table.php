<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Partial unique index — Laravel's schema builder cannot express
        // WHERE / expression indexes. Driver-aware so PHPUnit (sqlite) and
        // production (pgsql) both work.
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('
                CREATE UNIQUE INDEX customers_tenant_email_unique
                ON customers (tenant_id, LOWER(email))
                WHERE email IS NOT NULL AND deleted_at IS NULL
            ');
        } elseif ($driver === 'sqlite') {
            DB::statement('
                CREATE UNIQUE INDEX customers_tenant_email_unique
                ON customers (tenant_id, LOWER(email))
                WHERE email IS NOT NULL AND deleted_at IS NULL
            ');
        } else {
            // Fallback: expression index may not be supported; unique on raw columns.
            DB::statement('
                CREATE UNIQUE INDEX customers_tenant_email_unique
                ON customers (tenant_id, email)
            ');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_tenant_email_unique');
    }
};
