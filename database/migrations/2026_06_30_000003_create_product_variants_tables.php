<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // Drop FK from product_prices to product_variations (PostgreSQL needs explicit drop)
        try {
            Schema::table('product_prices', function (Blueprint $table) {
                $table->dropForeign(['variation_id']);
            });
        } catch (\Exception) {
            // SQLite does not support dropping foreign key constraints
        }

        Schema::dropIfExists('product_variations');

        Schema::enableForeignKeyConstraints();

        // Variant groups (e.g. "Size", "Color")
        Schema::create('product_variants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->string('name');
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });

        // Individual options within a variant (e.g. "Small", "Red")
        Schema::create('product_variant_options', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('variant_id');
            $table->ulid('product_id');
            $table->string('name');
            $table->decimal('custom_price', 10, 2)->nullable();
            $table->string('ghl_option_id')->nullable();
            $table->string('engage_price_id')->nullable();
            $table->string('engage_sync_status')->default('not_synced');
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->foreign('variant_id')->references('id')->on('product_variants')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_options');
        Schema::dropIfExists('product_variants');

        Schema::create('product_variations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->string('option_name');
            $table->string('option_value');
            $table->ulid('price_id')->nullable();
            $table->string('ghl_price_id')->nullable();
            $table->string('ghl_variation_option_id')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });

        Schema::table('product_prices', function (Blueprint $table) {
            $table->foreign('variation_id')->references('id')->on('product_variations')->onDelete('set null');
        });
    }
};
