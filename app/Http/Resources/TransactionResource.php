<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'customer_id' => $this->customer_id,
            'booking' => new BookingResource($this->whenLoaded('booking')),
            'booking_id' => $this->booking_id,
            'total_amount' => (float) $this->total_amount,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'invoice_status' => $this->invoice_status,
            'transaction_date' => $this->transaction_date,
            'items' => TransactionItemResource::collection($this->whenLoaded('items')),
            'ghl_invoice' => $this->when(
                $this->relationLoaded('booking') && $this->booking?->ghl_invoice_id,
                fn () => [
                    'id' => $this->booking->ghl_invoice_id,
                    'number' => $this->booking->ghl_invoice_number,
                    'status' => $this->booking->ghl_invoice_status,
                    'ghl_booking_id' => $this->booking->ghl_booking_id,
                ],
            ),
            'tenant_id' => $this->tenant_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
