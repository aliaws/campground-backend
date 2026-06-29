<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'customer_id' => $this->customer_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'product_id' => $this->product_id,
            'check_in_date' => $this->check_in_date,
            'check_out_date' => $this->check_out_date,
            'total_amount' => (float) $this->total_amount,
            'status' => $this->status,
            'ghl_opportunity_id' => $this->ghl_opportunity_id,
            'transactions' => TransactionResource::collection($this->whenLoaded('transactions')),
            'tenant_id' => $this->tenant_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
