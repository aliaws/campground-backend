<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pure indexing migration — no columns/data change, no behavior change.
 *
 * On Postgres (production DB), a foreign key constraint does NOT implicitly
 * create an index the way MySQL's does — several hot-path filter/lookup
 * columns only ever got a FK constraint, never an explicit ->index(), and
 * were doing sequential scans under normal list/webhook traffic. Found via
 * a performance audit (2026-07-29); see CLAUDE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index('customer_id');
            $table->index('product_id');
            $table->index('ghl_invoice_id');
            $table->index('ghl_opportunity_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index('customer_id');
            $table->index('booking_id');
            $table->index('ghl_invoice_id');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->index('transaction_id');
            $table->index('product_id');
        });

        Schema::table('product_rentals', function (Blueprint $table) {
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['product_id']);
            $table->dropIndex(['ghl_invoice_id']);
            $table->dropIndex(['ghl_opportunity_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['booking_id']);
            $table->dropIndex(['ghl_invoice_id']);
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropIndex(['transaction_id']);
            $table->dropIndex(['product_id']);
        });

        Schema::table('product_rentals', function (Blueprint $table) {
            $table->dropIndex(['product_id']);
        });
    }
};
