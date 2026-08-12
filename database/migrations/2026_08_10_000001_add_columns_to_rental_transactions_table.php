<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `rental_transactions` stops being a GHL-pull-only read ledger and
     * becomes the sole source of truth for every rental/booking transaction
     * in the app (POS bookings, customer-portal bookings, GHL pull/cron) —
     * see the transactions-refactor plan. These columns are what the live
     * payment lifecycle needs that a pure "already-paid" ledger didn't:
     * `booking_id` links a row back to the `Booking` it belongs to (the
     * relation `Booking::transactions()` now resolves here instead of the
     * dropped `transactions` table); `payment_method`/`quantity`/
     * `unit_price` replace what `transaction_items` used to carry for the
     * always-exactly-one-item rental case (`amount` remains the line total,
     * `amount = unit_price * quantity`). `ghl_invoice_number/status/url`
     * are deliberately NOT added here — a rental booking's GHL invoice
     * metadata already lives on `Booking` itself
     * (`GhlBookingService::persistInvoiceMetadata()`), confirmed by reading
     * that method directly; duplicating it here would have no consumer.
     */
    public function up(): void
    {
        Schema::table('rental_transactions', function (Blueprint $table) {
            $table->ulid('booking_id')->nullable()->after('ghl_booking_id');
            $table->string('payment_method')->nullable()->after('status');
            $table->integer('quantity')->nullable()->default(1)->after('rental_name');
            $table->decimal('unit_price', 10, 2)->nullable()->after('quantity');

            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
            // Postgres does not implicitly index FK columns — same gotcha
            // already documented in 2026_07_29_000001_add_performance_indexes.php.
            $table->index('booking_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_transactions', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropIndex(['booking_id']);
            $table->dropIndex(['customer_id']);
            $table->dropColumn(['booking_id', 'payment_method', 'quantity', 'unit_price']);
        });
    }
};
