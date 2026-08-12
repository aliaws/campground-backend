<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors `categories` (Product Categories) but scoped to services/rentals —
 * a service is associated by `product_rentals.service_category_id` (the raw
 * GHL category id, already captured since 2026-07-27, see that column's own
 * comment) matching `service_categories.ghl_category_id` here, resolved live
 * via ProductRental::serviceCategory() rather than a pivot, since a rental
 * only ever belongs to one category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->uuid('engage_organization_location_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            // Nullable + unique-per-tenant: a locally-created category (never
            // pulled from/pushed to GHL) has no ghl_category_id, matching
            // Category.engage_collection_id's own nullable-until-synced
            // convention. Unique here is what makes repeated pulls
            // idempotent — updateOrCreate() keys off this pair.
            $table->string('ghl_category_id')->nullable();
            $table->timestamp('engage_last_synced_at')->nullable();
            $table->timestamps();

            $table->index('engage_organization_location_id');
            $table->unique(['engage_organization_location_id', 'ghl_category_id']);
            $table->foreign('engage_organization_location_id', 'service_categories_eol_fk')
                ->references('id')->on('engage_organization_locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_categories');
    }
};
