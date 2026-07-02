<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'name'                 => $this->name,
            'product_type'         => $this->product_type,
            'description'          => $this->description,
            'sku'                  => $this->sku,
            'status'               => $this->status,
            'is_variable'          => $this->is_variable,
            'available_in_store'   => $this->available_in_store,
            'image'                => $this->image,
            'thumbnail'            => $this->thumbnail,
            'medias'               => $this->medias,
            'display_priority'     => $this->display_priority,
            'tax_inclusive'        => $this->tax_inclusive,
            'is_taxes_enabled'     => $this->is_taxes_enabled,
            // SERVICE-type (campsite) fields
            'site_type'            => $this->site_type,
            'capacity'             => $this->capacity,
            'available_quantity'   => $this->available_quantity,
            'hookups'              => $this->hookups,
            'map_position'         => $this->map_position,
            'map_polygon'          => $this->map_polygon,
            'pet_friendly'         => $this->pet_friendly,
            'ada_accessible'       => $this->ada_accessible,
            'campsite_status'      => $this->campsite_status,
            // GHL sync
            'engage_product_id'    => $this->engage_product_id,
            'engage_sync_status'   => $this->engage_sync_status,
            'engage_last_synced_at' => $this->engage_last_synced_at,
            // Relations
            'categories'           => CategoryResource::collection($this->whenLoaded('categories')),
            'prices'               => ProductPriceResource::collection($this->whenLoaded('prices')),
            'variants'             => ProductVariantResource::collection($this->whenLoaded('variants')),
            'amenities'            => AmenityResource::collection($this->whenLoaded('amenities')),
            'features'             => FeatureResource::collection($this->whenLoaded('features')),
            'tenant_id'            => $this->tenant_id,
            'created_at'           => $this->created_at,
            'updated_at'           => $this->updated_at,
        ];
    }
}
