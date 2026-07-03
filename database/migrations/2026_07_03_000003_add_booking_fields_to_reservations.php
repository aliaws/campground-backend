<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->integer('quantity')->default(1)->after('check_out_date');
            $table->decimal('base_amount', 10, 2)->default(0)->after('quantity');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('base_amount');
            $table->decimal('security_deposit_amount', 10, 2)->default(0)->after('total_amount');
            // Per-night computed prices + applied rules/discounts audit trail
            $table->json('price_breakdown')->nullable()->after('security_deposit_amount');
            $table->ulid('pricing_rule_id')->nullable()->after('price_breakdown');

            $table->foreign('pricing_rule_id')->references('id')->on('pricing_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['pricing_rule_id']);
            $table->dropColumn([
                'quantity', 'base_amount', 'discount_amount',
                'security_deposit_amount', 'price_breakdown', 'pricing_rule_id',
            ]);
        });
    }
};
