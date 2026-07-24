<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product?->name),
            'product_type' => $this->product_type,
            'quantity' => $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'total_price' => (float) ($this->unit_price * $this->quantity),
            // Date-only fields — see BookingResource for why explicit formatting
            // is needed (the 'date' cast otherwise serializes a full timestamp).
            'rental_start' => $this->rental_start?->format('Y-m-d'),
            'rental_end' => $this->rental_end?->format('Y-m-d'),
        ];
    }
}
