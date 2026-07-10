<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Removes everything the minimal product/product_rentals schema replaces:
 * the rentals + rental_pricing_rules tables, the generic price/variant system,
 * the amenity/feature pivots, and every GHL-duplicated or local-only campsite
 * column on products. Detail data now comes live from GHL (GhlRentalGateway);
 * amenities/features/campsite fields were dropped by product decision.
 */
return new class extends Migration
{
    private const LEGACY_TABLES = [
        'rental_pricing_rules',
        'rentals',
        'product_prices',
        'product_variant_options',
        'product_variants',
        'product_variations',
        'product_amenities',
        'product_features',
    ];

    private const LEGACY_PRODUCT_COLUMNS = [
        'sku',
        'is_variable',
        'thumbnail',
        'medias',
        'display_priority',
        'capacity',
        'site_type',
        'available_quantity',
        'hookups',
        'map_position',
        'map_polygon',
        'pet_friendly',
        'ada_accessible',
        'campsite_status',
        'parent_product_id',
        'variant_name',
        'ghl_service_id',
        'booking_unit',
        'min_duration',
        'max_duration',
        'duration_unit',
        'booking_start_time',
        'booking_end_time',
        'max_quantity',
        'pricing_rule',
        'ghl_service_category_id',
        'ghl_service_location_id',
        'ghl_metadata',
        'industry_type',
    ];

    public function up(): void
    {
        foreach (self::LEGACY_TABLES as $table) {
            Schema::dropIfExists($table);
        }

        // parent_product_id carries a self-referencing FK — drop the constraint
        // before the column on drivers that track it (PostgreSQL).
        if (Schema::hasColumn('products', 'parent_product_id') && Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('products', function ($table) {
                $table->dropForeign(['parent_product_id']);
            });
        }

        $columns = array_values(array_filter(
            self::LEGACY_PRODUCT_COLUMNS,
            fn (string $column) => Schema::hasColumn('products', $column)
        ));

        if ($columns !== []) {
            Schema::table('products', function ($table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        // Structural rollback only (data is gone) — restore from backup instead.
    }
};
