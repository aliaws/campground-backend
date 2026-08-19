<?php

namespace App\Http\Resources;

use App\Models\EngageProduct;
use Illuminate\Http\Request;

/**
 * Public browse/search card for a rental listing — extends ServiceResource
 * (same key set, no duplicated logic) with organization attribution. Once
 * the public storefront aggregates every organization's rentals together
 * (2026-08-19), a customer needs a way to tell which property a given
 * listing actually belongs to, especially once two organizations' listings
 * share a category. Deliberately a subclass rather than editing
 * ServiceResource directly — the authenticated staff ServiceController
 * (POS) also uses that class and must stay completely unaffected.
 */
class PublicServiceResource extends ServiceResource
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
