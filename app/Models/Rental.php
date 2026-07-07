<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Rental-specific data for a SERVICE product: booking window, campsite fields, GHL scheduling ids, pricing. */
class Rental extends Model
{
    use HasUlids;

    protected $fillable = [
        'product_id',
        'parent_product_id',
        'variant_name',
        'booking_unit',
        'min_duration',
        'max_duration',
        'duration_unit',
        'booking_start_time',
        'booking_end_time',
        'max_quantity',
        'campsite_status',
        'site_type',
        'capacity',
        'available_quantity',
        'hookups',
        'map_position',
        'map_polygon',
        'pet_friendly',
        'ada_accessible',
        'industry_type',
        'ghl_service_id',
        'ghl_service_category_id',
        'ghl_metadata',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'hookups' => 'json',
            'map_position' => 'json',
            'map_polygon' => 'json',
            'ghl_metadata' => 'json',
            'pet_friendly' => 'boolean',
            'ada_accessible' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The base listing's Product row (null when this rental IS the base). */
    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    /** Other rentals whose parent_product_id points at this rental's own product. */
    public function variantRentals(): HasMany
    {
        return $this->hasMany(Rental::class, 'parent_product_id', 'product_id');
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(RentalPricingRule::class)->orderBy('priority');
    }

    /**
     * The single rule callers should use for calculation/display — lowest
     * `priority` value wins. Deliberately single-rule selection for now
     * (matches pre-split behavior); no admin UI creates >1 rule per rental yet.
     */
    public function activePricingRule(): ?RentalPricingRule
    {
        return $this->relationLoaded('pricingRules')
            ? $this->pricingRules->first()
            : $this->pricingRules()->first();
    }

    /** Reassembles the old flat pricing_rule JSON shape from the active rule row. */
    public function getPricingRuleAttribute(): ?array
    {
        $rule = $this->activePricingRule();

        if (! $rule) {
            return null;
        }

        return [
            'name' => $rule->name,
            'applies_to' => $rule->applies_to,
            'base_price' => (float) $rule->base_price,
            'base_price_strategy' => $rule->base_price_strategy,
            'rules' => $rule->rules,
            'security_deposit_amount' => $rule->security_deposit_amount !== null ? (float) $rule->security_deposit_amount : null,
            'security_deposit_refundable' => $rule->security_deposit_refundable,
            'payment_terms' => $rule->payment_terms,
            'ghl_pricing_rule_id' => $rule->ghl_pricing_rule_id,
        ];
    }
}
