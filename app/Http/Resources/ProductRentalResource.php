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
            // Inventory & Pricing tab — the raw Advanced Pricing discount
            // rules pulled from Lead Connector for this specific rental row
            // (base listing or variant), stored verbatim — see
            // GhlServiceDetail::pricingRulesForPersistence(). Read-only
            // display data; nothing in this app edits or pushes it back.
            'pricing_rules' => $this->pricing_rules ?? [],
            // Real Lead Connector flag from the BASE listing's own detail
            // response — see GhlServiceSyncService::finalizeListing(). Only
            // meaningful on the base/default row; a variant's own copy of
            // this column is never written to true and shouldn't be read.
            'is_variants_enabled' => (bool) $this->is_variants_enabled,
            // Real Lead Connector `hasQuantityEnabled` flag for this exact
            // row — the single source of truth for whether it tracks stock
            // at all. Unlike is_variants_enabled above, this one IS
            // meaningful per-row (each variant is its own full service
            // record with its own copy) — see EngageProductRental's own
            // doc comment.
            'has_quantity_enabled' => (bool) $this->has_quantity_enabled,
            'is_default' => $this->isBaseListing(),
        ];
    }
}
