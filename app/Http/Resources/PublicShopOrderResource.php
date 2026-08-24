<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public-safe Shop order payload for guest checkout/confirmation — mirrors CustomerBookingResource's shape for the same reasons (no internal location/GHL ids). */
class PublicShopOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'items' => $this->relationLoaded('items')
                ? $this->getRelation('items')->map(fn ($item) => [
                    'product_name' => $item->product_name_snapshot,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                ])
                : [],
            'payment_url' => $this->ghl_invoice_url,
            'payment_status' => $this->ghl_invoice_status,
            'created_at' => $this->created_at,
        ];
    }
}
