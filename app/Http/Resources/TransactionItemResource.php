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
            'product_type' => $this->product_type,
            'quantity' => $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'total_price' => (float) ($this->unit_price * $this->quantity),
            'rental_start' => $this->rental_start,
            'rental_end' => $this->rental_end,
        ];
    }
}
