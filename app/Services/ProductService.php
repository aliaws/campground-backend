<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\Rental;
use App\Models\RentalPricingRule;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class ProductService
{
    private const EAGER = ['categories', 'prices', 'variants.options', 'amenities', 'features', 'serviceVariants', 'rental'];

    /**
     * Splits rental-specific fields (and pricing_rule) out of an incoming
     * create/update payload — they belong on Rental/RentalPricingRule now,
     * not Product. Returns [$productData, $rentalData, $pricingRule].
     */
    private function splitRentalData(array $data): array
    {
        $rentalData = array_intersect_key($data, array_flip(Product::RENTAL_PROXIED_ATTRIBUTES));
        $pricingRule = $rentalData['pricing_rule'] ?? null;
        unset($rentalData['pricing_rule']);

        $productData = array_diff_key($data, array_flip(Product::RENTAL_PROXIED_ATTRIBUTES));

        return [$productData, $rentalData, $pricingRule];
    }

    /** Upserts the Rental row (and its active pricing rule) for a SERVICE product. */
    private function syncRentalData(Product $product, array $rentalData, ?array $pricingRule): void
    {
        if (empty($rentalData) && $pricingRule === null) {
            return;
        }

        $rental = Rental::updateOrCreate(
            ['product_id' => $product->id],
            $rentalData + ['tenant_id' => $product->tenant_id]
        );

        if ($pricingRule !== null) {
            RentalPricingRule::updateOrCreate(
                ['rental_id' => $rental->id, 'priority' => 1],
                [
                    'name' => $pricingRule['name'] ?? 'Default',
                    'applies_to' => $pricingRule['applies_to'] ?? 'rental',
                    'base_price' => $pricingRule['base_price'] ?? 0,
                    'base_price_strategy' => $pricingRule['base_price_strategy'] ?? 'per_day',
                    'rules' => $pricingRule['rules'] ?? null,
                    'security_deposit_amount' => $pricingRule['security_deposit_amount'] ?? null,
                    'security_deposit_refundable' => $pricingRule['security_deposit_refundable'] ?? true,
                    'payment_terms' => $pricingRule['payment_terms'] ?? null,
                    'ghl_pricing_rule_id' => $pricingRule['ghl_pricing_rule_id'] ?? null,
                    'tenant_id' => $product->tenant_id,
                ]
            );
        }
    }

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Product::query();

        if (! empty($filters['tenant_id'])) {
            $query->byTenant($filters['tenant_id']);
        }

        if (! empty($filters['product_type'])) {
            $query->where('product_type', $filters['product_type']);
        }

        if (! empty($filters['exclude_type'])) {
            $query->where('product_type', '!=', $filters['exclude_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['engage_sync_status'])) {
            $query->where('engage_sync_status', $filters['engage_sync_status']);
        }

        if (! empty($filters['search'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('sku', 'like', "%{$filters['search']}%")
                    ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        return $query->with(self::EAGER)
            ->orderBy('display_priority', 'asc')
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): Product
    {
        $categoryIds = $data['category_ids'] ?? [];
        $amenityIds = $data['amenity_ids'] ?? [];
        $featureIds = $data['feature_ids'] ?? [];
        $variants = $data['variants'] ?? null;

        unset($data['category_ids'], $data['amenity_ids'], $data['feature_ids'], $data['variants']);

        [$productData, $rentalData, $pricingRule] = $this->splitRentalData($data);

        $product = Product::create($productData);
        $this->syncRentalData($product, $rentalData, $pricingRule);

        if (! empty($categoryIds)) {
            $product->categories()->sync($categoryIds);
        }
        if (! empty($amenityIds)) {
            $product->amenities()->sync($amenityIds);
        }
        if (! empty($featureIds)) {
            $product->features()->sync($featureIds);
        }
        if ($variants !== null) {
            $this->syncVariants($product, $variants);
        }

        return $product->load(self::EAGER);
    }

    public function update(Product $product, array $data): Product
    {
        $categoryIds = $data['category_ids'] ?? null;
        $amenityIds = $data['amenity_ids'] ?? null;
        $featureIds = $data['feature_ids'] ?? null;
        $variants = $data['variants'] ?? null;

        unset($data['category_ids'], $data['amenity_ids'], $data['feature_ids'], $data['variants']);

        [$productData, $rentalData, $pricingRule] = $this->splitRentalData($data);

        $product->update($productData);
        $this->syncRentalData($product, $rentalData, $pricingRule);

        if ($categoryIds !== null) {
            $product->categories()->sync($categoryIds);
        }
        if ($amenityIds !== null) {
            $product->amenities()->sync($amenityIds);
        }
        if ($featureIds !== null) {
            $product->features()->sync($featureIds);
        }
        if ($variants !== null) {
            $this->syncVariants($product, $variants);
        }

        return $product->fresh()->load(self::EAGER);
    }

    public function delete(Product $product): bool
    {
        return $product->delete();
    }

    public function uploadImage(Product $product, UploadedFile $image): Product
    {
        $path = $image->store('products', 'public');
        // Clear ghl_image_url so the next GHL sync re-uploads the new file
        $product->update(['image' => Storage::url($path), 'ghl_image_url' => null]);

        return $product->fresh();
    }

    // ── Prices ────────────────────────────────────────────────────────────────

    public function addPrice(Product $product, array $data): ProductPrice
    {
        return $product->prices()->create($data);
    }

    public function updatePrice(ProductPrice $price, array $data): ProductPrice
    {
        $price->update($data);

        return $price->fresh();
    }

    // ── Variants ──────────────────────────────────────────────────────────────

    /**
     * Full upsert-and-prune sync of all variant groups and their options.
     * Incoming payload:
     * [
     *   { id?, name, position?, options: [{ id?, name, position? }] }
     * ]
     */
    public function syncVariants(Product $product, array $variants): void
    {
        $incomingVariantIds = collect($variants)
            ->pluck('id')
            ->filter()
            ->values()
            ->toArray();

        // Remove variant groups that were deleted in the UI
        $product->variants()
            ->whereNotIn('id', $incomingVariantIds)
            ->each(fn (ProductVariant $v) => $v->options()->delete() || $v->delete());

        foreach ($variants as $position => $variantData) {
            $variantId = $variantData['id'] ?? null;

            $variant = $variantId
                ? $product->variants()->find($variantId)
                : null;

            if ($variant) {
                $variant->update([
                    'name' => $variantData['name'],
                    'position' => $variantData['position'] ?? $position,
                ]);
            } else {
                $variant = $product->variants()->create([
                    'name' => $variantData['name'],
                    'position' => $variantData['position'] ?? $position,
                ]);
            }

            $options = $variantData['options'] ?? [];
            $incomingOptionIds = collect($options)->pluck('id')->filter()->values()->toArray();

            // Remove options deleted in the UI
            $variant->options()->whereNotIn('id', $incomingOptionIds)->delete();

            foreach ($options as $optPosition => $optionData) {
                $optionId = $optionData['id'] ?? null;
                $option = $optionId ? $variant->options()->find($optionId) : null;

                $fields = [
                    'name' => $optionData['name'],
                    'position' => $optionData['position'] ?? $optPosition,
                ];

                if ($option) {
                    $option->update($fields);
                } else {
                    $variant->options()->create(array_merge($fields, [
                        'product_id' => $product->id,
                    ]));
                }
            }
        }
    }

    // ── Services storefront ───────────────────────────────────────────────────

    /**
     * Bookable services for the storefront: base SERVICE products (variants come
     * nested), filtered by search/category/availability/price and sorted by the
     * computed "from" price. Small per-tenant counts, so price filtering/sorting
     * happens on the collection.
     */
    public function listServices(array $filters = []): LengthAwarePaginator
    {
        $query = Product::service()
            ->byTenant($filters['tenant_id'])
            ->whereHas('rental', fn (Builder $q) => $q->whereNull('parent_product_id')->whereNotNull('ghl_service_id'))
            ->where('status', 'active')
            ->with(['rental', 'serviceVariants', 'categories', 'amenities', 'features', 'prices']);

        if (! empty($filters['search'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        if (! empty($filters['category_id'])) {
            $query->whereHas('categories', fn (Builder $q) => $q->where('categories.id', $filters['category_id']));
        }

        $services = $query->get();

        if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
            $services = $services->filter(
                fn (Product $p) => $this->hasStockInWindow($p, $filters['date_from'], $filters['date_to'])
            );
        }

        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $services = $services->filter(fn (Product $p) => ($p->fromPrice() ?? 0) >= (float) $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $services = $services->filter(fn (Product $p) => ($p->fromPrice() ?? 0) <= (float) $filters['max_price']);
        }

        $services = match ($filters['sort'] ?? null) {
            'price_asc' => $services->sortBy(fn (Product $p) => $p->fromPrice() ?? INF),
            'price_desc' => $services->sortByDesc(fn (Product $p) => $p->fromPrice() ?? -INF),
            default => $services->sortBy([['display_priority', 'asc'], ['created_at', 'desc']]),
        };

        $perPage = (int) ($filters['per_page'] ?? 15);
        $page = max((int) ($filters['page'] ?? 1), 1);

        return new LengthAwarePaginator(
            $services->forPage($page, $perPage)->values(),
            $services->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    /** True when the product (or any of its variants) still has stock in the window. */
    private function hasStockInWindow(Product $product, string $from, string $to): bool
    {
        $candidates = collect([$product])->concat($product->serviceVariants);

        return $candidates->contains(function (Product $p) use ($from, $to) {
            if ($p->available_quantity === null) {
                return true;
            }

            $booked = (int) Reservation::where('product_id', $p->id)
                ->where('status', '!=', 'cancelled')
                ->where('check_in_date', '<', $to)
                ->where('check_out_date', '>', $from)
                ->sum('quantity');

            return $booked < $p->available_quantity;
        });
    }

    // ── Available campsites ───────────────────────────────────────────────────

    public function getAvailableCampsites(string $tenantId, ?string $checkIn = null, ?string $checkOut = null): LengthAwarePaginator
    {
        $query = Product::service()->byTenant($tenantId)->where('campsite_status', 'available');

        if ($checkIn && $checkOut) {
            $bookedIds = Reservation::where('status', '!=', 'cancelled')
                ->where(function ($q) use ($checkIn, $checkOut) {
                    $q->whereBetween('check_in_date', [$checkIn, $checkOut])
                        ->orWhereBetween('check_out_date', [$checkIn, $checkOut])
                        ->orWhere(function ($qq) use ($checkIn, $checkOut) {
                            $qq->where('check_in_date', '<=', $checkIn)
                                ->where('check_out_date', '>=', $checkOut);
                        });
                })
                ->pluck('product_id');

            $query->whereNotIn('id', $bookedIds);
        }

        return $query->with(['categories', 'amenities', 'features', 'prices'])
            ->paginate(50);
    }
}
