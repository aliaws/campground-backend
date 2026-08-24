<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a Category row (regular product-catalog taxonomy) also represent
     * a rental-catalog category pulled from GHL's calendars/service-categories
     * response, which returns each category's `industryType` ('pos' or
     * 'rental') and — when it's the GHL-side counterpart of a category
     * already known here — an `associationId` matching that category's
     * engage_collection_id. See GhlServiceSyncService::pullServiceCategories()
     * for where these two new columns get populated.
     */
    public function up(): void
    {
        Schema::table('engage_categories', function (Blueprint $table) {
            // default('pos') both sets the value for every new row going
            // forward AND backfills every pre-existing row at the DB level
            // as part of adding the column — no separate UPDATE needed.
            $table->string('industry_type')->default('pos')->after('engage_collection_id');
            $table->ulid('rental_category_id')->nullable()->after('industry_type');

            $table->foreign('rental_category_id')
                ->references('id')->on('engage_product_rental_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('engage_categories', function (Blueprint $table) {
            $table->dropForeign(['rental_category_id']);
            $table->dropColumn(['industry_type', 'rental_category_id']);
        });
    }
};
