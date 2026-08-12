<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductRental;

/**
 * Resolves the `product_id` the frontend sends for quotes/bookings. The
 * customer-facing UI's variant dropdown emits the base listing's PRODUCT id for the
 * default variant and the PRODUCT_RENTALS id for every other variant — this
 * accepts either (ULIDs never collide across tables) and normalizes to the
 * (base product, rental variant) pair everything downstream works with.
 */
class RentalResolver
{
    /** @return array{0: Product, 1: ProductRental}|null */
    public function resolve(string $id, string $locationId): ?array
    {
        $product = Product::byLocation($locationId)->find($id);

        if ($product) {
            $rental = $product->resolveBaseRental();

            return $rental ? [$product, $rental] : null;
        }

        // product_rentals has no location column of its own — scoped via
        // its product relationship instead (see GhlServiceSyncService's
        // identical pattern).
        $rental = ProductRental::whereHas(
            'product',
            fn ($q) => $q->where('engage_organization_location_id', $locationId)
        )->find($id);

        if ($rental && $rental->product) {
            return [$rental->product, $rental];
        }

        return null;
    }
}
