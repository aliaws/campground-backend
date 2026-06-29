<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'variation_id' => $this->variation_id,
            'track_inventory' => $this->track_inventory,
            'available_quantity' => $this->available_quantity,
            'recurring_interval' => $this->recurring_interval,
            'recurring_interval_count' => $this->recurring_interval_count,
            'sku' => $this->sku,
            'deleted' => $this->deleted,
            'engage_price_id' => $this->engage_price_id,
            'engage_sync_status' => $this->engage_sync_status,
            'sync_error_message' => $this->sync_error_message,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
