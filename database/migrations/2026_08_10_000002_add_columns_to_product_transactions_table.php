<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `product_transactions` stops being a GHL-pull-only read ledger and
     * becomes the sole source of truth for every product-sale transaction
     * in the app (POS sales, New Booking cart "extras", GHL pull/cron) —
     * see the transactions-refactor plan. Unlike rentals, a booking-less
     * product sale's GHL invoice metadata has always lived directly on the
     * `Transaction` row (`GhlBookingService::persistTransactionInvoiceMetadata()`),
     * so its replacement must own the same fields — confirmed by reading
     * that method directly, it is a genuinely different data-ownership
     * pattern than the rental side. `booking_id` (nullable) is for the
     * "extras" cart case: a product-sale transaction created alongside a
     * rental booking, linked for traceability but never GHL-invoiced
     * together with the rental (unchanged existing behavior).
     */
    public function up(): void
    {
        Schema::table('product_transactions', function (Blueprint $table) {
            $table->ulid('booking_id')->nullable()->after('ghl_invoice_id');
            $table->string('payment_method')->nullable()->after('booking_id');
            $table->string('ghl_invoice_number')->nullable()->after('ghl_invoice_id');
            $table->string('ghl_invoice_status')->nullable()->after('ghl_invoice_number');
            $table->string('ghl_invoice_url')->nullable()->after('ghl_invoice_status');

            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
            $table->index('booking_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_transactions', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropIndex(['booking_id']);
            $table->dropIndex(['customer_id']);
            $table->dropColumn(['booking_id', 'payment_method', 'ghl_invoice_number', 'ghl_invoice_status', 'ghl_invoice_url']);
        });
    }
};
