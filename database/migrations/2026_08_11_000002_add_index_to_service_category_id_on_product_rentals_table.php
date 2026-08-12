<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_rentals.service_category_id has existed since 2026-07-10 but was
 * never indexed (like every other FK-shaped column in this app on Postgres,
 * see 2026_07_29_000001_add_performance_indexes.php — Postgres doesn't
 * auto-index FK columns the way MySQL does). It's now actively filtered on
 * (POS Booking / customer service-category filters), so this closes the
 * same gap that migration already closed for other columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_rentals', function (Blueprint $table) {
            $table->index('service_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_rentals', function (Blueprint $table) {
            $table->dropIndex(['service_category_id']);
        });
    }
};
