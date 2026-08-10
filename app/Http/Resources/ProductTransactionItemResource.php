<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductTransactionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_transaction_id' => $this->product_transaction_id,
            'product_id' => $this->product_id,
            'product_name' => ($this->relationLoaded('product') ? $this->product?->name : null) ?? $this->product_name_snapshot,
            'product_type' => $this->product_type,
            'quantity' => $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'total_price' => (float) ($this->unit_price * $this->quantity),
            'rental_start' => $this->rental_start?->format('Y-m-d'),
            'rental_end' => $this->rental_end?->format('Y-m-d'),
        ];
    }
}
