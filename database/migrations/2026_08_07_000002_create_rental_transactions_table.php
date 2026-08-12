<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Independent, GHL-sourced ledger of paid rental bookings/invoices —
     * deliberately NOT a reuse of `bookings`/`transactions` (whose
     * check_in_date/check_out_date and product_id columns are NOT NULL and
     * can't always be cleanly supplied from a pulled invoice). Kept
     * completely isolated from product_transactions per the Rentals vs
     * Products separation requirement.
     */
    public function up(): void
    {
        Schema::create('rental_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');

            $table->string('ghl_invoice_id')->nullable();
            $table->string('ghl_booking_id')->nullable();

            $table->ulid('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();

            $table->ulid('product_id')->nullable();
            $table->ulid('product_rental_id')->nullable();
            $table->string('rental_name')->nullable();

            $table->decimal('amount', 10, 2)->default(0);
            $table->string('status')->default('paid');

            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->string('notes')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('product_rental_id')->references('id')->on('product_rentals')->nullOnDelete();

            $table->unique(['tenant_id', 'ghl_invoice_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_transactions');
    }
};
