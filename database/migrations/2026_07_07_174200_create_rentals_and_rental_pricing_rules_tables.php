<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only — no reads/writes point here yet. Splits rental-specific
     * data (booking windows, campsite fields, GHL scheduling ids, pricing)
     * off the `products` table, which stays identity + product_type only.
     *
     * Design note: parent/variant linkage lives here as `parent_product_id`
     * (FK straight to products.id, not a self-FK on rentals) so that
     * Product::parent()/serviceVariants() can be implemented as native
     * Eloquent hasOneThrough/hasManyThrough relations (real ->load()/
     * whenLoaded() support) instead of needing a third-party
     * belongsToThrough package or losing eager-loading semantics.
     */
    public function up(): void
    {
        Schema::create('rentals', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('product_id');
            $table->ulid('parent_product_id')->nullable();
            $table->string('variant_name')->nullable();

            // Booking configuration
            $table->string('booking_unit')->nullable();
            $table->integer('min_duration')->nullable();
            $table->integer('max_duration')->nullable();
            $table->string('duration_unit')->nullable();
            $table->string('booking_start_time')->nullable();
            $table->string('booking_end_time')->nullable();
            $table->integer('max_quantity')->nullable();

            // Campsite/site fields
            $table->string('campsite_status')->nullable()->default('available');
            $table->string('site_type')->nullable();
            $table->integer('capacity')->nullable();
            $table->integer('available_quantity')->nullable();
            $table->json('hookups')->nullable();
            $table->json('map_position')->nullable();
            $table->json('map_polygon')->nullable();
            $table->boolean('pet_friendly')->default(false);
            $table->boolean('ada_accessible')->default(false);

            $table->string('industry_type')->nullable();

            // GHL scheduling-layer ids (payments-layer engage_product_id stays on products)
            $table->string('ghl_service_id')->nullable();
            $table->string('ghl_service_category_id')->nullable();
            $table->json('ghl_metadata')->nullable();

            $table->ulid('tenant_id');
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('parent_product_id')->references('id')->on('products')->nullOnDelete();
            $table->unique('product_id');
            $table->index('parent_product_id');
            $table->index('ghl_service_id');
            $table->index('tenant_id');
        });

        Schema::create('rental_pricing_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('rental_id');
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

            $table->foreign('rental_id')->references('id')->on('rentals')->onDelete('cascade');
            $table->index('tenant_id');
            $table->index(['rental_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_pricing_rules');
        Schema::dropIfExists('rentals');
    }
};
