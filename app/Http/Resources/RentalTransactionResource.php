<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RentalTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ghl_invoice_id' => $this->ghl_invoice_id,
            'ghl_booking_id' => $this->ghl_booking_id,
            'booking_id' => $this->booking_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'product' => new ProductResource($this->whenLoaded('product')),
            'product_rental_id' => $this->product_rental_id,
            'rental_name' => $this->rental_name,
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price !== null ? (float) $this->unit_price : null,
            'check_in_date' => $this->check_in_date,
            'check_out_date' => $this->check_out_date,
            'notes' => $this->notes,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
