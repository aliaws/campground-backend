<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per refund recorded by staff, against a paid campsite (rental)
 * transaction or a paid product transaction.
 *
 * Lead Connector's API can't issue refunds, so the money is sent back from
 * Lead Connector itself (Payments > Transactions > Refund). This row is our
 * record of it: `recorded` when staff log it, `confirmed` once someone has
 * checked the refund went through in Lead Connector. Several partial refunds
 * may exist for one transaction, never more than its paid amount in total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engage_refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->uuid('engage_organization_location_id')->index();
            $table->ulid('rental_transaction_id')->nullable()->index();
            $table->ulid('product_transaction_id')->nullable()->index();
            $table->ulid('booking_id')->nullable()->index();
            $table->ulid('customer_id')->nullable()->index();
            $table->decimal('amount', 10, 2);
            $table->text('reason')->nullable();
            $table->string('status')->default('recorded')->index();
            $table->string('ghl_invoice_id')->nullable();
            $table->boolean('booking_cancelled')->default(false);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_by')->nullable();
            $table->timestamps();

            $table->foreign('rental_transaction_id')->references('id')->on('engage_rental_transactions')->nullOnDelete();
            $table->foreign('product_transaction_id')->references('id')->on('engage_product_transactions')->nullOnDelete();
            $table->foreign('booking_id')->references('id')->on('engage_bookings')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('engage_customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engage_refunds');
    }
};
