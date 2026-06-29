<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            $table->dropColumn(['name', 'sku', 'price_modifier']);
        });

        Schema::table('product_variations', function (Blueprint $table) {
            $table->string('option_name')->after('product_id');
            $table->string('option_value')->after('option_name');
            $table->ulid('price_id')->nullable()->after('option_value');
            $table->string('ghl_price_id')->nullable()->after('price_id');
            $table->string('ghl_variation_option_id')->nullable()->after('ghl_price_id');

            $table->foreign('price_id')->references('id')->on('product_prices')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            $table->dropForeign(['price_id']);
            $table->dropColumn(['option_name', 'option_value', 'price_id', 'ghl_price_id', 'ghl_variation_option_id']);
        });

        Schema::table('product_variations', function (Blueprint $table) {
            $table->string('name')->after('product_id');
            $table->string('sku')->nullable()->after('name');
            $table->decimal('price_modifier', 10, 2)->default(0)->after('sku');
        });
    }
};
