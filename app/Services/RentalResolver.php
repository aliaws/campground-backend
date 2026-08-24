<?php

namespace App\Services;

use App\Models\EngageProduct;
use App\Models\EngageProductRental;

/**
 * Resolves the `product_id` the frontend sends for quotes/bookings. The
 * customer-facing UI's variant dropdown emits the base listing's PRODUCT id for the
 * default variant and the PRODUCT_RENTALS id for every other variant — this
 * accepts either (ULIDs never collide across tables) and normalizes to the
 * (base product, rental variant) pair everything downstream works with.
 */
class RentalResolver
{
    /**
     * $locationId is optional — every staff caller (authenticated, scoped
     * to their own org) still passes a real one and gets the exact
     * pre-existing behavior. Public callers omit it to resolve globally
     * (the public storefront aggregates every organization's rentals, see
     * ProductService::scopeToLocationOrAllActiveOrgs()'s doc comment) —
     * ULIDs never collide across organizations, so an unscoped find() is
     * just as unambiguous, only less defensively scoped.
     *
     * @return array{0: EngageProduct, 1: EngageProductRental}|null
     */
    public function resolve(string $id, ?string $locationId = null): ?array
    {
        $productQuery = EngageProduct::query();
        if ($locationId) {
            $productQuery->byLocation($locationId);
        }

        $product = $productQuery->find($id);

        if ($product) {
            $rental = $product->resolveBaseRental();

            return $rental ? [$product, $rental] : null;
        }

        // product_rentals has no location column of its own — scoped via
        // its product relationship instead (see GhlServiceSyncService's
        // identical pattern).
        $rentalQuery = EngageProductRental::query();
        if ($locationId) {
            $rentalQuery->whereHas('product', fn ($q) => $q->where('engage_organization_location_id', $locationId));
        }

        $rental = $rentalQuery->find($id);

        if ($rental && $rental->product) {
            return [$rental->product, $rental];
        }

        return null;
    }
}
