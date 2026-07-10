<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per bookable rental variant. The base listing itself is stored as a
 * row here too (the product's "default" variant, referenced by
 * products.product_rental_id) — a product with N GHL variants has N+0 or N
 * rows depending on how GHL models it, but always at least the default row.
 * Only GhlServiceSyncService::pullServices() creates rows here, which is what
 * keeps the Services/guest listing scoped to real GHL-linked rentals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_rentals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->nullable(); // variant name, e.g. "Regular"
            $table->boolean('is_active')->default(true);
            $table->integer('service_duration')->nullable();
            $table->string('service_duration_unit')->nullable();
            $table->string('slug')->nullable();
            // Local-only field (campsite map placement) — never overwritten by GHL sync.
            $table->json('map_position')->nullable();
            // This variant's own GHL calendar service _id (live-detail fetch key).
            $table->string('ghl_id')->nullable();
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('service_category_id')->nullable();
            // The base/master listing's GHL service id (GHL "variantId" on variant
            // rows; equals ghl_id on the default row) — sent as masterListingId
            // when creating GHL bookings.
            $table->string('service_id')->nullable();
            $table->string('tenant_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'ghl_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_rentals');
    }
};
