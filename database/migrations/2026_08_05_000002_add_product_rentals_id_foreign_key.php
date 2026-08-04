<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Physical shared-PK link: product_rentals.id is PK + FK → products.id.
 *
 * Base listings already share ids. Variant rentals (id ≠ product_id) get a
 * lightweight shell Product row with the same id so the FK can be applied
 * without dropping bookings that reference those variant rental ids.
 * Shells are is_rental=false so they never appear as bookable listings;
 * the parent listing remains product_rentals.product_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensureProductRowsForEveryRental();

        Schema::table('product_rentals', function (Blueprint $table) {
            $table->foreign('id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_rentals', function (Blueprint $table) {
            $table->dropForeign(['id']);
        });

        // Remove only shell products created for former orphan variant ids
        // (is_rental=false products that still have a product_rentals row
        // whose product_id points at a different listing).
        $shellIds = DB::table('product_rentals')
            ->whereColumn('id', '!=', 'product_id')
            ->pluck('id');

        if ($shellIds->isNotEmpty()) {
            // Drop FK first already done; temporarily remove rental rows? No —
            // down() only drops the FK. Leave shell products in place so
            // re-running up() stays idempotent; they are harmless anchors.
        }
    }

    private function ensureProductRowsForEveryRental(): void
    {
        $orphans = DB::table('product_rentals as pr')
            ->leftJoin('products as p', 'p.id', '=', 'pr.id')
            ->whereNull('p.id')
            ->select('pr.*')
            ->get();

        $now = now();

        foreach ($orphans as $rental) {
            $parent = DB::table('products')->where('id', $rental->product_id)->first();

            if (! $parent) {
                throw new RuntimeException(
                    "Cannot create shell product for rental {$rental->id}: parent product {$rental->product_id} missing."
                );
            }

            DB::table('products')->insert([
                'id' => $rental->id,
                'name' => $rental->name
                    ? ($parent->name.' — '.$rental->name)
                    : $parent->name,
                'product_type' => $parent->product_type ?? 'SERVICE',
                'description' => $parent->description,
                'status' => $parent->status ?? 'active',
                'available_in_store' => $parent->available_in_store ?? true,
                'image' => $parent->image,
                'tax_inclusive' => $parent->tax_inclusive ?? false,
                'is_taxes_enabled' => $parent->is_taxes_enabled ?? false,
                'slug' => $rental->slug ?: (string) Str::slug(($parent->name ?? 'rental').'-'.$rental->id),
                'sku' => null,
                'quantity' => $rental->quantity ?? $parent->quantity,
                'price' => $rental->listing_price ?? $parent->price,
                'is_rental' => false,
                'track_product_inventory' => false,
                'ghl_image_url' => $parent->ghl_image_url ?? null,
                'ghl_product_id' => $rental->ghl_product_id ?? $parent->ghl_product_id,
                'engage_sync_status' => $parent->engage_sync_status ?? 'synced',
                'engage_last_synced_at' => $parent->engage_last_synced_at,
                'engage_organization_location_id' => $parent->engage_organization_location_id,
                'created_at' => $rental->created_at ?? $now,
                'updated_at' => $now,
            ]);
        }
    }
};
