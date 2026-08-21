<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A real, product-level mirror of Lead Connector's own `isActive` flag for
 * rental services — previously this concept only lived on the base rental
 * row (`engage_product_rentals.is_active`), while the product's own
 * `status` enum (active/draft/archived) was a separate, independently
 * editable column that could silently drift out of sync with it. `status`
 * is NOT dropped here — goods products still need the full
 * active/draft/archived distinction, and a large number of existing
 * queries/filters already depend on it — but for a rental, `is_active` is
 * now the single, always-in-sync source of truth
 * (see ProductService::update()/GhlServiceSyncService's pull logic).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_products', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('status');
        });

        // Backfill from the existing status column so pre-existing rows
        // start correctly in sync rather than defaulting every row to true.
        DB::table('engage_products')->where('status', '!=', 'active')->update(['is_active' => false]);
    }

    public function down(): void
    {
        Schema::table('engage_products', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
