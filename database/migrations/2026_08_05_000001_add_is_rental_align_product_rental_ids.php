<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. products.is_rental (default false) replaces the old "product_rental_id IS NOT NULL"
 *    bookable-listing check; products.product_rental_id is dropped after backfill.
 * 2. Base product_rentals rows are remapped so id === product_id (shared PK with the
 *    listing Product). Variant rows keep their own ids and still point at product_id.
 * 3. product_rentals gains quantity / max_quantity (from GHL service detail).
 * 4. categories gains is_rental (default false) + nullable unique association_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_rental')->default(false)->after('product_rental_id');
        });

        DB::table('products')
            ->whereNotNull('product_rental_id')
            ->update(['is_rental' => true]);

        Schema::table('product_rentals', function (Blueprint $table) {
            $table->integer('quantity')->nullable()->after('listing_price');
            $table->integer('max_quantity')->nullable()->after('quantity');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_rental')->default(false)->after('is_active');
            $table->string('association_id')->nullable()->unique()->after('is_rental');
        });

        // site_map_elements is the only real FK onto product_rentals.id;
        // bookings.product_rental_id is indexed but unconstrained.
        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->dropForeign(['product_rental_id']);
        });

        $this->alignBaseRentalIdsWithProducts();

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['product_rental_id']);
            $table->dropColumn('product_rental_id');
        });

        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->foreign('product_rental_id')
                ->references('id')
                ->on('product_rentals')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->ulid('product_rental_id')->nullable()->index()->after('price');
        });

        // Best-effort restore: point product_rental_id at the shared-id base row
        // (id === product id) when present, else any rental under the product.
        $products = DB::table('products')->where('is_rental', true)->pluck('id');
        foreach ($products as $productId) {
            $baseId = DB::table('product_rentals')
                ->where('id', $productId)
                ->value('id')
                ?? DB::table('product_rentals')
                    ->where('product_id', $productId)
                    ->whereColumn('ghl_id', 'service_id')
                    ->value('id')
                ?? DB::table('product_rentals')
                    ->where('product_id', $productId)
                    ->value('id');

            if ($baseId) {
                DB::table('products')->where('id', $productId)->update(['product_rental_id' => $baseId]);
            }
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['association_id']);
            $table->dropColumn(['is_rental', 'association_id']);
        });

        Schema::table('product_rentals', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'max_quantity']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_rental');
        });
    }

    /**
     * Remap each listing's base ProductRental so its primary key equals the
     * Product id. Updates bookings + site_map_elements FKs that pointed at the
     * old base id. Variant rows are left alone.
     */
    private function alignBaseRentalIdsWithProducts(): void
    {
        $products = DB::table('products')
            ->where('is_rental', true)
            ->select('id', 'product_rental_id')
            ->get();

        foreach ($products as $product) {
            $base = DB::table('product_rentals')
                ->where('product_id', $product->id)
                ->whereColumn('ghl_id', 'service_id')
                ->first();

            if (! $base && $product->product_rental_id) {
                $base = DB::table('product_rentals')
                    ->where('id', $product->product_rental_id)
                    ->first();
            }

            if (! $base) {
                $base = DB::table('product_rentals')
                    ->where('product_id', $product->id)
                    ->orderBy('created_at')
                    ->first();
            }

            if (! $base || $base->id === $product->id) {
                continue;
            }

            // A non-base row must never already occupy the product id.
            $collision = DB::table('product_rentals')->where('id', $product->id)->exists();
            if ($collision) {
                throw new RuntimeException(
                    "Cannot align product_rentals.id to product {$product->id}: id already in use."
                );
            }

            $oldId = $base->id;
            $newId = $product->id;

            DB::table('bookings')
                ->where('product_rental_id', $oldId)
                ->update(['product_rental_id' => $newId]);

            DB::table('site_map_elements')
                ->where('product_rental_id', $oldId)
                ->update(['product_rental_id' => $newId]);

            DB::table('products')
                ->where('id', $product->id)
                ->update(['product_rental_id' => $newId]);

            DB::table('product_rentals')
                ->where('id', $oldId)
                ->update(['id' => $newId]);
        }
    }
};
