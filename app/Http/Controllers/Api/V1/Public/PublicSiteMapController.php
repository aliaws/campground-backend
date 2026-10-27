<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicSiteMapResource;
use App\Models\EngageBooking;
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

    /**
     * Which rental sites on this map are fully booked for a date range, in
     * one request for the whole map. Uses our own bookings and each site's
     * stored stock (engage_product_rentals.quantity, refreshed on every Lead
     * Connector pull), not a live Lead Connector call per site, so the map
     * still turns booked sites red when Lead Connector is slow or down.
     * Same overlap rule as BookingService::remainingStock() (the check-out
     * day is free); a site with no stock limit (quantity null) is never
     * reported as booked. Checkout still re-quotes live before booking.
     */
    public function availability(Request $request, SiteMap $siteMap): JsonResponse
    {
        $organization = $siteMap->organizationLocation;

        if (! $organization || $organization->isBlocked() || $organization->isUninstalled()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Map not found.'], 404);
        }

        $data = $request->validate([
            'check_in_date' => ['required', 'date_format:Y-m-d'],
            'check_out_date' => ['required', 'date_format:Y-m-d', 'after:check_in_date'],
        ]);

        $rentals = $siteMap->elements()
            ->where('type', 'rental')
            ->where('is_visible', true)
            ->whereNotNull('product_rental_id')
            ->with('productRental:id,quantity')
            ->get()
            ->pluck('productRental')
            ->filter(fn ($rental) => $rental && $rental->quantity !== null)
            ->unique('id');

        $booked = $rentals->isEmpty() ? collect() : EngageBooking::whereIn('product_rental_id', $rentals->pluck('id'))
            ->where('status', '!=', 'cancelled')
            ->where('check_in_date', '<', $data['check_out_date'])
            ->where('check_out_date', '>', $data['check_in_date'])
            ->groupBy('product_rental_id')
            ->selectRaw('product_rental_id, SUM(quantity) as booked')
            ->pluck('booked', 'product_rental_id');

        $unavailable = $rentals
            ->filter(fn ($rental) => (int) ($booked[$rental->id] ?? 0) >= (int) $rental->quantity)
            ->pluck('id')
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'check_in_date' => $data['check_in_date'],
                'check_out_date' => $data['check_out_date'],
                'unavailable_product_rental_ids' => $unavailable,
            ],
            'message' => 'Availability retrieved.',
        ]);
    }
}
