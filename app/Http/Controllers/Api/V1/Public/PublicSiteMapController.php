<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicSiteMapResource;
use App\Models\EngageOrganizationLocation;
use App\Models\SiteMap;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 2026-08-19: aggregated across every (or a caller-selected subset of)
 * active organization, mirroring PublicServiceController/
 * PublicCategoryController's existing "no single default org" pattern —
 * previously this hardcoded OrganizationLocationResolver::
 * resolveDefaultLocationId() and only ever showed one organization's map.
 */
class PublicSiteMapController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeIds = EngageOrganizationLocation::active()->pluck('id');

        $requestedIds = $request->input('organization_ids', []);
        if (! empty($requestedIds) && is_array($requestedIds)) {
            $activeIds = $activeIds->intersect($requestedIds)->values();
        }

        // One map per organization — whichever the staff builder has
        // marked default there, falling back to the oldest for an org that
        // hasn't explicitly picked one yet. An org with no map at all
        // simply contributes nothing to the list.
        $maps = $activeIds
            ->map(fn (string $orgId) => SiteMap::where('engage_organization_location_id', $orgId)->where('is_default', true)->first()
                ?? SiteMap::where('engage_organization_location_id', $orgId)->oldest()->first())
            ->filter()
            ->values();

        // ->map()/->filter() above yield a plain Support Collection, not an
        // Eloquent one — ::load() is Eloquent-collection-only.
        $maps = EloquentCollection::make($maps->all())->load('organizationLocation');

        return response()->json([
            'success' => true,
            'data' => PublicSiteMapResource::collection($maps),
            'message' => 'Maps retrieved.',
        ]);
    }

    public function show(SiteMap $siteMap): JsonResponse
    {
        $organization = $siteMap->organizationLocation;

        if (! $organization || $organization->isBlocked() || $organization->isUninstalled()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Map not found.'], 404);
        }

        $siteMap->load([
            'organizationLocation',
            'elements' => function ($query) {
                // A 'rental' pin is only included if its underlying listing
                // is still bookable (active + isRental()) — i.e. has an
                // accessible Service Details page to link to. Decorative
                // 'icon' pins are unaffected. The map's own organization is
                // already confirmed active above, so no further per-element
                // org check is needed here.
                $query->where('is_visible', true)->where(function ($q) {
                    $q->where('type', '!=', 'rental')
                        ->orWhereHas(
                            'productRental.product',
                            fn ($p) => $p->where('status', 'active')->whereNotNull('product_rental_id')
                        );
                });
            },
            'elements.productRental.product',
            'elements.iconType',
        ]);

        return response()->json([
            'success' => true,
            'data' => new PublicSiteMapResource($siteMap),
            'message' => 'Map retrieved.',
        ]);
    }
}
