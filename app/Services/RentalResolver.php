<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductRental;

/**
 * Resolves the id the frontend sends for quotes/bookings. Accepts either a
 * base listing Product id or any ProductRental / variant Product id (shared
 * PK), and normalizes to (base listing Product, selected ProductRental).
 */
class RentalResolver
{
    /** @return array{0: Product, 1: ProductRental}|null */
    public function resolve(string $id, string $locationId): ?array
    {
        $rental = ProductRental::query()
            ->where('id', $id)
            ->whereHas('product', fn ($q) => $q->where('engage_organization_location_id', $locationId))
            ->first();

        if ($rental) {
            $listingId = $rental->listingProductId() ?? $rental->id;
            $listing = Product::byLocation($locationId)->find($listingId);

            return ($listing && $rental) ? [$listing, $rental] : null;
        }

        $product = Product::byLocation($locationId)->baseRentalListing()->find($id);
        if ($product) {
            $base = $product->resolveBaseRental();

            return $base ? [$product, $base] : null;
        }

        return null;
    }
}
