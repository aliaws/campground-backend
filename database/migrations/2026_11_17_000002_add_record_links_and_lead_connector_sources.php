<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - webhook_logs gets a polymorphic link (loggable_type/loggable_id) to the
 *   record a webhook or outbound call created/updated.
 * - Bookings: `lead_connector` splits into lead_connector_webhook /
 *   lead_connector_pull. Every existing one came from a pull (no booking has
 *   ever been created by a webhook), so they all become lead_connector_pull.
 * - Customers get the same `source` column, backfilled from created_by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_logs', function (Blueprint $table) {
            // String id: linked records use ULIDs, users use integers.
            $table->string('loggable_type', 50)->nullable()->after('status');
            $table->string('loggable_id', 36)->nullable()->after('loggable_type');
            $table->index(['loggable_type', 'loggable_id']);
        });

        // 'lead_connector_webhook' is 22 characters; the column was 20.
        Schema::table('engage_bookings', function (Blueprint $table) {
            $table->string('source', 30)->default('pos')->change();
        });

        DB::table('engage_bookings')->where('source', 'lead_connector')->update(['source' => 'lead_connector_pull']);

        Schema::table('engage_customers', function (Blueprint $table) {
            $table->string('source', 30)->default('pos')->index();
        });

        DB::table('engage_customers')->where('created_by', 'Lead Connector Sync')->update(['source' => 'lead_connector_pull']);
        DB::table('engage_customers')->where('created_by', 'like', 'Customer - %')->update(['source' => 'website']);
    }

    public function down(): void
    {
        Schema::table('engage_customers', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });

        DB::table('engage_bookings')
            ->whereIn('source', ['lead_connector_pull', 'lead_connector_webhook'])
            ->update(['source' => 'lead_connector']);

        Schema::table('engage_bookings', function (Blueprint $table) {
            $table->string('source', 20)->default('pos')->change();
        });

        Schema::table('webhook_logs', function (Blueprint $table) {
            $table->dropIndex(['loggable_type', 'loggable_id']);
            $table->dropColumn(['loggable_type', 'loggable_id']);
        });
    }
};
