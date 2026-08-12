<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'product_type' => $this->product_type,
            'description' => $this->description,
            'status' => $this->status,
            'available_in_store' => $this->available_in_store,
            'image' => $this->image,
            'tax_inclusive' => $this->tax_inclusive,
            'is_taxes_enabled' => $this->is_taxes_enabled,
            'track_product_inventory' => $this->track_product_inventory,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'price' => $this->price !== null ? (float) $this->price : null,
            'product_rental_id' => $this->product_rental_id,
            'from_price' => $this->when($this->isRental(), fn () => $this->fromPrice()),
            // Services-module concept (Key Business Logic item 29) — resolved
            // from the base rental's serviceCategory, same pattern as
            // ServiceResource's serviceCategoryName. Only meaningful for a
            // rental (isRental()); null otherwise since resolveBaseRental()
            // simply has nothing to find on a non-rental product.
            'service_category_id' => $this->when($this->isRental(), fn () => $this->resolveBaseRental()?->service_category_id),
            'service_category_name' => $this->when($this->isRental(), fn () => $this->resolveBaseRental()?->serviceCategory?->name),
            'ghl_product_id' => $this->ghl_product_id,
            'ghl_image_url' => $this->ghl_image_url,
            'engage_sync_status' => $this->engage_sync_status,
            'engage_last_synced_at' => $this->engage_last_synced_at,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'amenities' => AmenityResource::collection($this->whenLoaded('amenities')),
            'features' => FeatureResource::collection($this->whenLoaded('features')),
            'rentals' => ProductRentalResource::collection($this->whenLoaded('rentals')),
            'default_rental' => new ProductRentalResource($this->whenLoaded('defaultRental')),
            'engage_organization_location_id' => $this->engage_organization_location_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
