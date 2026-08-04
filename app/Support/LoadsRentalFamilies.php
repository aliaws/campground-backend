<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductRental;
use Illuminate\Support\Collection;

/**
 * Eager-loads the GHL listing family onto each Product as the `rentals`
 * relation (grouped by product_rentals.service_id — no product_id column).
 */
trait LoadsRentalFamilies
{
    /** @param  Collection<int, Product>|iterable<Product>  $products */
    protected function loadRentalFamilies(iterable $products): void
    {
        $products = Collection::make($products)->filter();
        if ($products->isEmpty()) {
            return;
        }

        $products->loadMissing('productRental');

        $serviceIds = $products
            ->map(fn (Product $p) => $p->productRental?->service_id)
            ->filter()
            ->unique()
            ->values();

        $byService = $serviceIds->isEmpty()
            ? collect()
            : ProductRental::query()
                ->whereIn('service_id', $serviceIds)
                ->get()
                ->groupBy('service_id');

        foreach ($products as $product) {
            $serviceId = $product->productRental?->service_id;
            $product->setRelation(
                'rentals',
                $serviceId ? ($byService->get($serviceId) ?? collect()) : collect()
            );
        }
    }
}
