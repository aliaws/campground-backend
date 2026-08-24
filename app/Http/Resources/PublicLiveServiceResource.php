<?php

namespace App\Http\Resources;

use App\Models\EngageProduct;
use Illuminate\Http\Request;

/**
 * Live-GHL-enriched public service detail — extends LiveServiceResource
 * (same key set, no duplicated logic) with organization attribution, same
 * as PublicServiceResource does for ServiceResource. `PublicServiceController
 * ::show()` uses this live-detail path for every request where the GHL
 * fetch succeeds (the common case) — without this, `organizationId` was
 * only ever present on the rare local-fallback response, which is why the
 * Service Details page's organization-scoped map never had an id to key
 * off (found live, 2026-08-19). Deliberately a subclass rather than
 * editing LiveServiceResource directly — the authenticated staff
 * ServiceController (POS) also uses that class and must stay unaffected.
 */
class PublicLiveServiceResource extends LiveServiceResource
{
    public function toArray(Request $request): array
    {
        /** @var EngageProduct $product */
        $product = $this->resource;

        return array_merge(parent::toArray($request), [
            'organizationId' => $product->engage_organization_location_id,
            'organizationName' => $this->whenLoaded('organizationLocation', fn () => $product->organizationLocation?->name),
        ]);
    }
}
