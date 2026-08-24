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
            // The always-in-sync mirror of Lead Connector's own isActive
            // flag for a rental (see the is_active migration's doc
            // comment) — `status` stays around for goods and every
            // pre-existing status-based query, but this is what Manage
            // Service's edit form actually reads/writes for a rental.
            'is_active' => $this->is_active,
            'available_in_store' => $this->available_in_store,
            'image' => $this->image,
            // Full gallery (position:0 == the `image` above) — see
            // EngageProduct::localImagesFallback(). Added so the Manage
            // Service edit form can load/display/manage every image a
            // service has, not just its single cover photo.
            'images' => $this->localImagesFallback(),
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
            // Manage Service's Booking Settings tab — same
            // resolveBaseRental() convenience-field pattern as
            // service_category_id/service_category_name above.
            'booking_period_type' => $this->when($this->isRental(), fn () => $this->resolveBaseRental()?->booking_period_type),
            'booking_settings' => $this->when($this->isRental(), fn () => $this->resolveBaseRental()?->booking_settings),
            // Inventory & Pricing tab — real Lead Connector `isVariantsEnabled`
            // flag (from the base listing's own detail response, never a
            // variant's), same resolveBaseRental() convenience-field pattern
            // as the two fields above. Drives whether the edit form shows the
            // Variants table or a single inline Pricing card.
            'is_variants_enabled' => $this->when($this->isRental(), fn () => (bool) $this->resolveBaseRental()?->is_variants_enabled),
            // Real Lead Connector `hasQuantityEnabled` flag — the single
            // source of truth for the edit form's shared "Inventory"
            // switch, seeded from the base rental's own copy (the form's
            // one shared toggle applies the same value to every row on
            // save — see ProductsManager.tsx's handleSubmit()).
            'has_quantity_enabled' => $this->when($this->isRental(), fn () => (bool) $this->resolveBaseRental()?->has_quantity_enabled),
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
