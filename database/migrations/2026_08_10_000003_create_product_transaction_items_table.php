<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relational line-items for product_transactions, replacing its
     * unstructured `items` JSON blob as the write target going forward
     * (the JSON column is kept, frozen, no longer written — cheap to leave
     * in place, no reason to risk a drop). Needed so relational report
     * queries (ReportService::revenueByCategory()-style `whereHas`/`with`
     * joins) keep working the way they did against `transaction_items`.
     * `product_id` stays nullable (mirroring `rental_transactions`'
     * pattern) even though every current creation path always resolves
     * one — defensive, matches this app's existing "don't assume a GHL
     * line always maps to a local product" caution elsewhere.
     */
    public function up(): void
    {
        Schema::create('product_transaction_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_transaction_id');
            $table->ulid('product_id')->nullable();
            $table->string('product_name_snapshot')->nullable();
            $table->string('product_type')->default('physical');
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->date('rental_start')->nullable();
            $table->date('rental_end')->nullable();
            $table->timestamps();

            $table->foreign('product_transaction_id')->references('id')->on('product_transactions')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->index('product_transaction_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_transaction_items');
    }
};
