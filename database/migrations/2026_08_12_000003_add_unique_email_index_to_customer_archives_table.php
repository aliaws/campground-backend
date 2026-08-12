<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same driver-aware partial unique index technique as
        // 2026_07_14_000002_add_unique_email_index_to_customers_table.php —
        // at most one archive record per location+email, so re-archiving an
        // already-archived customer (e.g. a repeated archive/restore cycle)
        // can never create a second archive row.
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('
                CREATE UNIQUE INDEX customer_archives_location_email_unique
                ON customer_archives (engage_organization_location_id, LOWER(email))
                WHERE email IS NOT NULL
            ');
        } else {
            DB::statement('
                CREATE UNIQUE INDEX customer_archives_location_email_unique
                ON customer_archives (engage_organization_location_id, email)
            ');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customer_archives_location_email_unique');
    }
};
