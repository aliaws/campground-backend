<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductRental;

/**
 * Resolves the `product_id` the frontend sends for quotes/bookings. The
 * guest UI's variant dropdown emits the base listing's PRODUCT id for the
 * default variant and the PRODUCT_RENTALS id for every other variant — this
 * accepts either (ULIDs never collide across tables) and normalizes to the
 * (base product, rental variant) pair everything downstream works with.
 */
class RentalResolver
{
    /** @return array{0: Product, 1: ProductRental}|null */
    public function resolve(string $id, string $tenantId): ?array
    {
        $product = Product::byTenant($tenantId)->find($id);

        if ($product) {
            $rental = $product->resolveBaseRental();

            return $rental ? [$product, $rental] : null;
        }

        $rental = ProductRental::where('tenant_id', $tenantId)->find($id);

        if ($rental && $rental->product) {
            return [$rental->product, $rental];
        }

        return null;
    }
}
