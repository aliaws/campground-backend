<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->string('name');
            $table->string('applies_to')->default('rental');
            $table->decimal('base_price', 10, 2)->default(0);
            $table->string('base_price_strategy')->default('per_day');
            // Ordered rule list, mirroring GHL pricingRule.rules:
            // [{ type: date_range|day_of_week|duration_discount|quantity_discount,
            //    match: {...}, value: number, valueType: flat|percentage, sequence: int }]
            $table->json('rules')->nullable();
            $table->decimal('security_deposit_amount', 10, 2)->nullable();
            $table->boolean('security_deposit_refundable')->default(true);
            $table->json('payment_terms')->nullable(); // { type: full|partial, ... }
            $table->integer('priority')->default(1);
            $table->string('ghl_pricing_rule_id')->nullable();
            $table->ulid('tenant_id');
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->index('tenant_id');
            $table->index(['product_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
