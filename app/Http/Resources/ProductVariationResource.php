<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'option_name' => $this->option_name,
            'option_value' => $this->option_value,
            'price_id' => $this->price_id,
            'price' => new ProductPriceResource($this->whenLoaded('price')),
            'ghl_price_id' => $this->ghl_price_id,
            'ghl_variation_option_id' => $this->ghl_variation_option_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
