<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a booking was made: 'pos' (staff, in this app), 'website' (the
 * customer, through the public booking pages) or 'lead_connector' (made in
 * Lead Connector itself and brought in by Pull Data / the scheduled sync).
 *
 * Existing rows are filled from created_by, which every creation path has
 * always written in a fixed shape: "Lead Connector Sync", "Customer - {name}"
 * (website), or "{Admin|Staff|Superadmin} - {name}" (POS). Anything else
 * keeps the column default, 'pos'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_bookings', function (Blueprint $table) {
            $table->string('source', 20)->default('pos')->after('created_by');
            $table->index('source');
        });

        DB::table('engage_bookings')
            ->where('created_by', 'Lead Connector Sync')
            ->update(['source' => 'lead_connector']);

        DB::table('engage_bookings')
            ->where('created_by', 'like', 'Customer - %')
            ->update(['source' => 'website']);
    }

    public function down(): void
    {
        Schema::table('engage_bookings', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
