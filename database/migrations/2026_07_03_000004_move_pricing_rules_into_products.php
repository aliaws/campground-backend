<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // One GHL-style pricingRule object per product:
            // { name, applies_to, base_price, base_price_strategy, rules: [...],
            //   security_deposit_amount, security_deposit_refundable, payment_terms,
            //   ghl_pricing_rule_id }
            $table->json('pricing_rule')->nullable()->after('max_quantity');
        });

        // Copy each product's highest-priority rule into the new column
        DB::table('pricing_rules')
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get()
            ->unique('product_id')
            ->each(function ($rule) {
                DB::table('products')->where('id', $rule->product_id)->update([
                    'pricing_rule' => json_encode([
                        'name' => $rule->name,
                        'applies_to' => $rule->applies_to,
                        'base_price' => (float) $rule->base_price,
                        'base_price_strategy' => $rule->base_price_strategy,
                        'rules' => json_decode($rule->rules ?? '[]', true),
                        'security_deposit_amount' => $rule->security_deposit_amount !== null ? (float) $rule->security_deposit_amount : null,
                        'security_deposit_refundable' => (bool) $rule->security_deposit_refundable,
                        'payment_terms' => json_decode($rule->payment_terms ?? 'null', true),
                        'ghl_pricing_rule_id' => $rule->ghl_pricing_rule_id,
                    ]),
                ]);
            });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['pricing_rule_id']);
            $table->dropColumn('pricing_rule_id');
        });

        Schema::dropIfExists('pricing_rules');
    }

    public function down(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->string('name');
            $table->string('applies_to')->default('rental');
            $table->decimal('base_price', 10, 2)->default(0);
            $table->string('base_price_strategy')->default('per_day');
            $table->json('rules')->nullable();
            $table->decimal('security_deposit_amount', 10, 2)->nullable();
            $table->boolean('security_deposit_refundable')->default(true);
            $table->json('payment_terms')->nullable();
            $table->integer('priority')->default(1);
            $table->string('ghl_pricing_rule_id')->nullable();
            $table->ulid('tenant_id');
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->index('tenant_id');
            $table->index(['product_id', 'priority']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->ulid('pricing_rule_id')->nullable()->after('price_breakdown');
            $table->foreign('pricing_rule_id')->references('id')->on('pricing_rules')->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('pricing_rule');
        });
    }
};
