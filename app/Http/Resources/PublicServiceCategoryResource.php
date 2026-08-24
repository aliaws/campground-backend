<?php

namespace App\Http\Resources;

use App\Models\EngageProductRentalCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Public service-category filter chip — groups every organization's
 * EngageProductRentalCategory rows sharing the same (case-insensitive,
 * trimmed) name into one entry, so a customer sees e.g. "Tent Sites" once
 * even when two organizations both have a category by that name. The
 * underlying per-org rows are never merged or written to — `ids` carries
 * every real category id in the group so the services list can still be
 * filtered against all of them at once (see
 * ProductService::listServices()'s `service_category_ids` filter).
 * Deliberately a new resource rather than editing the shared
 * ServiceCategoryResource, which the authenticated staff
 * ServiceCategoryController (POS) also uses.
 */
class PublicServiceCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }

    /**
     * @param  Collection<int, EngageProductRentalCategory>  $categories  Must already have `rentals_count` loaded via withCount.
     * @return array<int, array{name: string, ids: array<int, string>, rentals_count: int, organization_count: int}>
     */
    public static function groupByName(Collection $categories): array
    {
        return $categories
            ->groupBy(fn (EngageProductRentalCategory $category) => strtolower(trim($category->name)))
            ->map(fn (Collection $group) => [
                'name' => $group->first()->name,
                'ids' => $group->pluck('id')->values()->all(),
                'rentals_count' => (int) $group->sum('rentals_count'),
                'organization_count' => $group->pluck('engage_organization_location_id')->unique()->count(),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }
}
