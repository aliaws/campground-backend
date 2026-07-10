<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductRentalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'service_duration' => $this->service_duration,
            'service_duration_unit' => $this->service_duration_unit,
            'slug' => $this->slug,
            'map_position' => $this->map_position,
            'ghl_id' => $this->ghl_id,
            'ghl_product_id' => $this->ghl_product_id,
            'product_id' => $this->product_id,
            'service_category_id' => $this->service_category_id,
            'service_id' => $this->service_id,
            'is_default' => $this->when(
                $this->relationLoaded('product') || $this->product,
                fn () => $this->product?->product_rental_id === $this->id
            ),
        ];
    }
}
