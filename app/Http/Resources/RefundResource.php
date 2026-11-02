<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $booking = $this->relationLoaded('booking') ? $this->booking : null;
        $product = $this->relationLoaded('productTransaction') ? $this->productTransaction : null;
        $rental = $this->relationLoaded('rentalTransaction') ? $this->rentalTransaction : null;

        return [
            'id' => $this->id,
            // campsite | product
            'type' => $this->type(),
            'amount' => (float) $this->amount,
            'reason' => $this->reason,
            // recorded | confirmed
            'status' => $this->status,
            'booking_cancelled' => (bool) $this->booking_cancelled,
            'booking_id' => $this->booking_id,
            'rental_transaction_id' => $this->rental_transaction_id,
            'product_transaction_id' => $this->product_transaction_id,
            // What was refunded: the campsite for a booking, otherwise the product sale.
            'item_name' => $booking?->product?->name
                ?? $rental?->rental_name
                ?? ($product ? 'Product sale' : null),
            'paid_amount' => (float) ($rental?->amount ?? $product?->amount ?? 0),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
            ] : null),
            'ghl_invoice_id' => $this->ghl_invoice_id,
            'ghl_invoice_number' => $booking?->ghl_invoice_number ?? $product?->ghl_invoice_number,
            'created_by' => $this->created_by,
            'confirmed_at' => $this->confirmed_at,
            'confirmed_by' => $this->confirmed_by,
            'created_at' => $this->created_at,
        ];
    }
}
