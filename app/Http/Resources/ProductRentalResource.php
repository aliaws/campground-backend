<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductRentalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'service_duration' => $this->service_duration,
            'service_duration_unit' => $this->service_duration_unit,
            'slug' => $this->slug,
            'map_position' => $this->map_position,
            'ghl_id' => $this->ghl_id,
            'ghl_product_id' => $this->ghl_product_id,
            'listing_price' => $this->listing_price !== null ? (float) $this->listing_price : null,
            'security_deposit_amount' => $this->security_deposit_amount !== null ? (float) $this->security_deposit_amount : null,
            'quantity' => $this->quantity,
            'max_quantity' => $this->max_quantity,
            'service_category_id' => $this->service_category_id,
            'service_id' => $this->service_id,
            'booking_period_type' => $this->booking_period_type,
            'booking_settings' => $this->booking_settings,
            'is_default' => $this->isBaseListing(),
        ];
    }
}
