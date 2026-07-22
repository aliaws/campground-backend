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
            // A booking-less product sale carries its own ghl_invoice_*
            // fields; prefer those, falling back to the linked booking's
            // (existing rental/booking transactions have their own fields
            // null, so this preserves prior behavior for them exactly).
            'ghl_invoice' => $this->when(
                $this->ghl_invoice_id || ($this->relationLoaded('booking') && $this->booking?->ghl_invoice_id),
                fn () => $this->ghl_invoice_id
                    ? [
                        'id' => $this->ghl_invoice_id,
                        'number' => $this->ghl_invoice_number,
                        'status' => $this->ghl_invoice_status,
                        'ghl_booking_id' => $this->booking?->ghl_booking_id,
                    ]
                    : [
                        'id' => $this->booking->ghl_invoice_id,
                        'number' => $this->booking->ghl_invoice_number,
                        'status' => $this->booking->ghl_invoice_status,
                        'ghl_booking_id' => $this->booking->ghl_booking_id,
                    ],
            ),
            // The pay-link URL itself (Text2Pay) — only ever set on a
            // booking-less "card" product sale (BookingResource already
            // exposes its own ghl_invoice_url the same way for bookings).
            'ghl_invoice_url' => $this->ghl_invoice_url,
            'tenant_id' => $this->tenant_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
