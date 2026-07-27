<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-introduces amenity/feature associations, scoped to services only this
 * time — the original product_amenities/product_features pivots (dropped in
 * 2026_07_10_000005_drop_legacy_rental_and_pricing_structures.php) attached
 * to any Product; these attach only to service listings (Product rows with
 * product_rental_id set), matching the 2026-07-27 decision that amenities
 * and features are a Services-module concept, not a general Products one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_amenities', function (Blueprint $table) {
            $table->ulid('product_id');
            $table->ulid('amenity_id');
            $table->primary(['product_id', 'amenity_id']);

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('amenity_id')->references('id')->on('amenities')->onDelete('cascade');
        });

        Schema::create('service_features', function (Blueprint $table) {
            $table->ulid('product_id');
            $table->ulid('feature_id');
            $table->primary(['product_id', 'feature_id']);

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('feature_id')->references('id')->on('features')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_features');
        Schema::dropIfExists('service_amenities');
    }
};
