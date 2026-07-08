<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public-safe reservation payload for guest checkout — no internal tenant/GHL ids. */
class GuestReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product->name),
            'booking_start_time' => $this->whenLoaded('product', fn () => $this->product->booking_start_time),
            'booking_end_time' => $this->whenLoaded('product', fn () => $this->product->booking_end_time),
            'check_in_date' => $this->check_in_date,
            'check_out_date' => $this->check_out_date,
            'quantity' => $this->quantity,
            'total_amount' => (float) $this->total_amount,
            'security_deposit_amount' => (float) $this->security_deposit_amount,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
