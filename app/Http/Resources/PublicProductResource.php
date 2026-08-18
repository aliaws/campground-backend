<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public "Shop" page's product card — deliberately a distinct, slimmer
 * resource from ProductResource (the staff-facing one), which exposes
 * internal-only fields (engage_sync_status, ghl_product_id, sku) that have
 * no business being in an unauthenticated public response. Mirrors the
 * existing ServiceResource/LiveServiceResource split for the same reason.
 */
class PublicProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'slug' => $this->slug,
            'image' => $this->image,
            'price' => $this->price !== null ? (float) $this->price : null,
            'track_product_inventory' => $this->track_product_inventory,
            'quantity' => $this->quantity,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            // The Shop page now aggregates every organization's catalog
            // together (2026-08-19) — a guest needs to see which "store" a
            // product belongs to, since checkout can only ever settle items
            // from one organization at a time.
            'organization_id' => $this->engage_organization_location_id,
            'organization_name' => $this->whenLoaded('organizationLocation', fn () => $this->organizationLocation?->name),
        ];
    }
}
