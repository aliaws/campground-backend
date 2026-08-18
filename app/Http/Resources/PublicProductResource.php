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
        ];
    }
}
