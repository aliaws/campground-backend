<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * The Services-module counterpart to Category (Product Categories) —
 * mirrors its shape but is keyed to GHL by `ghl_category_id`
 * (calendars/service-categories' `_id`), not `engage_collection_id`
 * (products/collections), and relates to rentals rather than products.
 */
class EngageProductRentalCategory extends Model
{
    use HasUlids;

    protected $table = 'engage_product_rental_categories';

    protected $fillable = [
        'name',
        'is_active',
        'ghl_category_id',
        'engage_sync_status',
        'engage_last_synced_at',
        'engage_organization_location_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'engage_last_synced_at' => 'datetime',
        ];
    }

    /**
     * A rental only ever belongs to one category, so this is a straight
     * hasMany keyed on the raw GHL id ProductRental already stores — not a
     * pivot (unlike Category::products(), which is many-to-many).
     */
    public function rentals(): HasMany
    {
        return $this->hasMany(EngageProductRental::class, 'service_category_id', 'ghl_category_id');
    }

    /**
     * 2026-08-22 fix: `withCount('rentals')` (previously used by both
     * ServiceCategoryController::index()/show()/update()/syncToGhl() and
     * PublicServiceCategoryController::index()) counts every matching
     * `EngageProductRental` **row** — base listing AND every one of its
     * variants each carry their own `service_category_id` copy (see
     * GhlServiceSyncService::upsertRentalRow()) — not distinct services. A
     * real, user-reported bug: a listing with several variants sharing one
     * category was counted once per variant instead of once, inflating that
     * category's displayed count; the same per-row duplication is *also*
     * what let a just-recategorized service's stale variant rows keep it
     * showing under its old category's count too (see
     * ProductService::update()'s own doc comment for that half of the bug).
     * This overrides `rentals_count` on each category with a DISTINCT count
     * of `product_id` among its matching rentals instead, so a
     * multi-variant listing is only ever counted once no matter how many
     * variants it has.
     *
     * Deliberately takes the exact same relation-constraint closure the
     * caller already passed to `withCount`/`whereHas` (e.g. "the rental's
     * own product must be active") so the corrected count reflects the
     * identical eligibility rule, not a looser or stricter one.
     *
     * @param  Collection<int, self>  $categories  Already-fetched categories to correct in place (and returned for chaining).
     * @param  Closure|null  $constrainRentals  Same closure shape passed to `whereHas('rentals', ...)`/`withCount(['rentals' => ...])` elsewhere — receives the `EngageProductRental` query builder directly.
     * @return Collection<int, self>
     */
    public static function withDistinctServiceCounts(Collection $categories, ?Closure $constrainRentals = null): Collection
    {
        $ghlIds = $categories->pluck('ghl_category_id')->filter()->values();

        if ($ghlIds->isEmpty()) {
            foreach ($categories as $category) {
                $category->setAttribute('rentals_count', 0);
            }

            return $categories;
        }

        $query = EngageProductRental::whereIn('service_category_id', $ghlIds);

        if ($constrainRentals) {
            $constrainRentals($query);
        }

        $counts = $query->selectRaw('service_category_id, count(distinct product_id) as aggregate')
            ->groupBy('service_category_id')
            ->pluck('aggregate', 'service_category_id');

        foreach ($categories as $category) {
            $category->setAttribute('rentals_count', (int) ($counts[$category->ghl_category_id] ?? 0));
        }

        return $categories;
    }
}
