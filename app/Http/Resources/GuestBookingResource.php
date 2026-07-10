<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public-safe booking payload for guest checkout — no internal tenant/GHL ids. */
class GuestBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product->name),
            'booking_start_time' => $this->booking_start_time,
            'booking_end_time' => $this->booking_end_time,
            'check_in_date' => $this->check_in_date,
            'check_out_date' => $this->check_out_date,
            'quantity' => $this->quantity,
            'total_amount' => (float) $this->total_amount,
            'security_deposit_amount' => (float) $this->security_deposit_amount,
            'status' => $this->status,
            'payment_url' => $this->ghl_invoice_url,
            'payment_status' => $this->ghl_invoice_status,
            'created_at' => $this->created_at,
        ];
    }
}
