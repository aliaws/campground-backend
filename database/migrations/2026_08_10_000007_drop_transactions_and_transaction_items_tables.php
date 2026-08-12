<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final step of the 2026-08-10 transactions refactor's "soak period" — see
 * "Transaction system consolidation" under Key Business Logic (item 20).
 * `rental_transactions`/`product_transactions` have been the sole source of
 * truth for every transaction since that refactor; `transactions`/
 * `transaction_items` were deliberately left in place afterward purely as a
 * rollback safety net, per the explicit sequencing note "a separate final
 * migration drops transaction_items/transactions — only after soak +
 * explicit go-ahead, never bundled with step 1."
 *
 * Confirmed safe to drop now, not just "probably unused":
 * - Zero code references anywhere (`App\Models\Transaction`, the
 *   `TransactionController`/`TransactionService`/`TransactionResource`
 *   classes, and every frontend `lib/api/transactions.ts`/`useTransactions`
 *   consumer were already deleted as part of that same refactor).
 * - Both tables are confirmed **empty** (0 rows each) via a direct `psql`
 *   query bypassing Eloquent entirely — there was real legacy data here
 *   immediately after the refactor (the `transactions.` migrate step
 *   deliberately left it behind when `legacy-transactions:backup`/
 *   `:migrate` were removed at the user's request), but nothing remains to
 *   lose by dropping the empty tables now.
 *
 * `transaction_items.transaction_id` FK's `transactions.id`, so
 * `transaction_items` is dropped first; `down()` recreates in reverse
 * order. Schema captured from the live database's `information_schema`/
 * `pg_indexes` immediately before dropping, including `transactions`'
 * `is_ghl_import` column — present in the real database despite CLAUDE.md's
 * own "Custom Invoice Generation" correction note describing that exact
 * column as part of a never-actually-built feature; real schema drift from
 * documentation, not something this migration needs to resolve, just
 * faithfully reproduce in down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('transaction_items');
        Schema::dropIfExists('transactions');
    }

    public function down(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('customer_id');
            $table->ulid('booking_id')->nullable();
            $table->decimal('total_amount', 10, 2);
            $table->string('payment_method');
            $table->string('payment_status');
            $table->string('invoice_status');
            $table->timestamp('transaction_date');
            $table->uuid('engage_organization_location_id');
            $table->timestamps();
            $table->softDeletes();
            $table->string('ghl_invoice_id')->nullable();
            $table->string('ghl_invoice_number')->nullable();
            $table->string('ghl_invoice_status')->nullable();
            $table->string('ghl_invoice_url')->nullable();
            $table->boolean('is_ghl_import')->default(false);

            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('booking_id')->references('id')->on('bookings');
            $table->index('invoice_status');
            $table->index('payment_status');
            $table->index('engage_organization_location_id');
            $table->index('customer_id');
            $table->index('booking_id');
            $table->index('ghl_invoice_id');
        });

        Schema::create('transaction_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('transaction_id');
            $table->ulid('product_id');
            $table->string('product_type');
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->date('rental_start')->nullable();
            $table->date('rental_end')->nullable();
            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('transactions');
            $table->foreign('product_id')->references('id')->on('products');
            $table->index('transaction_id');
            $table->index('product_id');
        });
    }
};
