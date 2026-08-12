<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Independent, GHL-sourced ledger of paid product-sale invoices — the
     * Products-module sibling of rental_transactions. See that migration's
     * docblock for why this isn't a reuse of `transactions`/`transaction_items`.
     */
    public function up(): void
    {
        Schema::create('product_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');

            $table->string('ghl_invoice_id')->nullable();

            $table->ulid('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();

            $table->decimal('amount', 10, 2)->default(0);
            $table->string('status')->default('paid');
            $table->json('items')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();

            $table->unique(['tenant_id', 'ghl_invoice_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_transactions');
    }
};
