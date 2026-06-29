<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('customer_id');
            $table->ulid('reservation_id')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('payment_method');
            $table->string('payment_status')->default('draft');
            $table->string('invoice_status')->default('invoicing');
            $table->timestamp('transaction_date')->useCurrent();
            $table->ulid('tenant_id');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('reservation_id')->references('id')->on('reservations')->onDelete('set null');
            $table->index('tenant_id');
            $table->index('payment_status');
            $table->index('invoice_status');
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

            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
        Schema::dropIfExists('transactions');
    }
};
