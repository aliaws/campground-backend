<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors `categories.engage_sync_status` (2026_06_29_000004) — service
 * categories gained outbound (local -> Lead Connector) sync this same day
 * (create/update/delete pushes, see GhlServiceSyncService::syncServiceCategoryToGhl()),
 * so they need the same visible not_synced/pending/synced/error tracking
 * Category has always had. Backfilled rather than left to default so
 * already-pulled rows (which do have a real ghl_category_id) don't read as
 * "not_synced" the moment this column appears.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->string('engage_sync_status')->default('not_synced')->after('ghl_category_id');
        });

        DB::table('service_categories')->whereNotNull('ghl_category_id')->update(['engage_sync_status' => 'synced']);
    }

    public function down(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropColumn('engage_sync_status');
        });
    }
};
