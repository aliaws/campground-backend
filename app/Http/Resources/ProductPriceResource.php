<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'label' => $this->label,
            'price' => (float) $this->price,
            'valid_from' => $this->valid_from,
            'valid_until' => $this->valid_until,
            'created_at' => $this->created_at,
        ];
    }
}
