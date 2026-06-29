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
            'type' => $this->type,
            'sub_type' => $this->sub_type,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'category_id' => $this->category_id,
            'base_price' => (float) $this->base_price,
            'stock_qty' => $this->stock_qty,
            'capacity' => $this->capacity,
            'location' => $this->location,
            'rental_duration_unit' => $this->rental_duration_unit,
            'min_rental_duration' => $this->min_rental_duration,
            'max_rental_duration' => $this->max_rental_duration,
            'status' => $this->status,
            'image_url' => $this->image_url,
            'prices' => ProductPriceResource::collection($this->whenLoaded('prices')),
            'variations' => ProductVariationResource::collection($this->whenLoaded('variations')),
            'amenities' => AmenityResource::collection($this->whenLoaded('amenities')),
            'features' => FeatureResource::collection($this->whenLoaded('features')),
            'tenant_id' => $this->tenant_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
