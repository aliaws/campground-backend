<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Collapses the old one-Product-row-per-variant structure into product_rentals:
 *  - every GHL-linked base rental becomes its product's default product_rentals row
 *  - every GHL-linked variant rental becomes a product_rentals row under the PARENT product
 *  - reservations/transaction_items pointing at variant product rows are remapped to
 *    (parent product_id, product_rental_id), then the variant product rows are deleted
 *  - products.price is set to the cheapest base_price across the listing's pricing rules
 *
 * Uses DB::table() only (Eloquent models no longer match the legacy schema).
 * Irreversible — restore from a DB backup to roll back. No-op on fresh databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rentals')) {
            return;
        }

        DB::transaction(function () {
            $now = now();

            $ghlRentals = DB::table('rentals')->whereNotNull('ghl_service_id')->get();
            $bases = $ghlRentals->whereNull('parent_product_id')->values();
            $variants = $ghlRentals->whereNotNull('parent_product_id')->values();

            // ghl_service_id of each base, keyed by its product id — variants resolve
            // their service_id (master listing GHL id) through their parent product.
            $baseServiceIdByProduct = $bases->pluck('ghl_service_id', 'product_id');

            // ── a. Base listings → default product_rentals rows ─────────────────
            foreach ($bases as $rental) {
                $product = DB::table('products')->where('id', $rental->product_id)->first();
                if (! $product) {
                    continue;
                }

                $rentalRowId = (string) Str::ulid();

                DB::table('product_rentals')->insert([
                    'id' => $rentalRowId,
                    'name' => $rental->variant_name ?? 'Regular',
                    'is_active' => $product->status === 'active',
                    'service_duration' => $rental->min_duration,
                    'service_duration_unit' => $rental->duration_unit,
                    'slug' => null,
                    'map_position' => $rental->map_position,
                    'ghl_id' => $rental->ghl_service_id,
                    'product_id' => $rental->product_id,
                    'service_category_id' => $rental->ghl_service_category_id,
                    'service_id' => $rental->ghl_service_id,
                    'tenant_id' => $rental->tenant_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('products')->where('id', $rental->product_id)->update([
                    'product_rental_id' => $rentalRowId,
                    'quantity' => $rental->available_quantity,
                ]);

                // Reservations already on the base product: attach the default
                // variant + snapshot the booking times.
                DB::table('reservations')
                    ->where('product_id', $rental->product_id)
                    ->whereNull('product_rental_id')
                    ->update([
                        'product_rental_id' => $rentalRowId,
                        'booking_start_time' => $rental->booking_start_time,
                        'booking_end_time' => $rental->booking_end_time,
                    ]);
            }

            // ── b/c. Variant rentals → rows under the parent product + remap ────
            foreach ($variants as $rental) {
                $parentProductId = $rental->parent_product_id;

                $rentalRowId = (string) Str::ulid();

                DB::table('product_rentals')->insert([
                    'id' => $rentalRowId,
                    'name' => $rental->variant_name ?? 'Variant',
                    'is_active' => true,
                    'service_duration' => $rental->min_duration,
                    'service_duration_unit' => $rental->duration_unit,
                    'slug' => null,
                    'map_position' => $rental->map_position,
                    'ghl_id' => $rental->ghl_service_id,
                    'product_id' => $parentProductId,
                    'service_category_id' => $rental->ghl_service_category_id,
                    'service_id' => $baseServiceIdByProduct[$parentProductId] ?? null,
                    'tenant_id' => $rental->tenant_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Reservations/transaction items on the variant's own product row
                // move to the parent product; reservations also learn which variant.
                DB::table('reservations')
                    ->where('product_id', $rental->product_id)
                    ->update([
                        'product_id' => $parentProductId,
                        'product_rental_id' => $rentalRowId,
                        'booking_start_time' => $rental->booking_start_time,
                        'booking_end_time' => $rental->booking_end_time,
                    ]);

                DB::table('transaction_items')
                    ->where('product_id', $rental->product_id)
                    ->update(['product_id' => $parentProductId]);
            }

            // ── d. Starting price per listing = cheapest rule base_price ────────
            if (Schema::hasTable('rental_pricing_rules')) {
                foreach ($bases as $rental) {
                    $listingRentalIds = $ghlRentals
                        ->filter(fn ($r) => $r->product_id === $rental->product_id
                            || $r->parent_product_id === $rental->product_id)
                        ->pluck('id');

                    $minPrice = DB::table('rental_pricing_rules')
                        ->whereIn('rental_id', $listingRentalIds)
                        ->min('base_price');

                    if ($minPrice !== null) {
                        DB::table('products')
                            ->where('id', $rental->product_id)
                            ->update(['price' => $minPrice]);
                    }
                }
            }

            // ── e. Delete the now-empty variant product rows ────────────────────
            $variantProductIds = $variants->pluck('product_id')->all();

            if ($variantProductIds !== []) {
                // Clear pivots/children without cascade guarantees first.
                foreach (['product_categories', 'product_amenities', 'product_features', 'product_prices'] as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->whereIn('product_id', $variantProductIds)->delete();
                    }
                }

                DB::table('rentals')->whereIn('product_id', $variantProductIds)->delete();
                DB::table('products')->whereIn('id', $variantProductIds)->delete();
            }
        });
    }

    public function down(): void
    {
        // Irreversible data migration — restore from the pre-refactor DB backup.
    }
};
