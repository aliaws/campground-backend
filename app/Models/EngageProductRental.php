<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bookable rental/service variant. The base listing is stored here too as
 * the product's "default" row (products.product_rental_id). Only the GHL
 * pull creates rows. listing_price/service_duration_unit/
 * security_deposit_amount are staff-editable locally (Manage Service's
 * Pricing section, 2026-08-18) — display/reference values only, e.g. the
 * product list's "From Price" column. They do NOT feed the live GHL quote
 * a guest actually pays for at booking time — that's still entirely
 * GhlRentalGateway/BookingPriceCalculator, unchanged, GHL-sourced, and not
 * persisted anywhere.
 *
 * booking_period_type/booking_settings (2026-08-21, Manage Service's
 * "Booking Settings" tab) are a deliberate, narrow exception to "booking
 * windows are never persisted" above — they mirror GHL's own booking-period
 * *configuration* (min/max duration, buffers, scheduling notice, fixed
 * intervals), not live availability/booking-time data, which is still
 * fetched live via GhlRentalGateway/BookingPriceCalculator exactly as
 * before and never touches this column.
 *
 * quantity/pricing_rules (2026-08-21, "Inventory & Pricing" tab) are the
 * same kind of narrow exception, one level further: each rental row's own
 * Stock (quantity, null = unlimited) and Advanced Pricing discount-rule set
 * (pricing_rules — the raw Lead Connector `pricingRule.rules[]` array,
 * stored verbatim per row), refreshed on every pull. Per-variant only —
 * quantity is unrelated to `EngageProduct.quantity` (a separate, pre-existing
 * listing-wide field). pricing_rules is read/displayed only; nothing in this
 * app currently edits or pushes it back to Lead Connector.
 *
 * has_quantity_enabled (2026-08-21) is the real Lead Connector
 * `hasQuantityEnabled` field — the single source of truth for whether a
 * service/variant tracks stock at all, replacing an earlier, purely local
 * (unpersisted) frontend toggle that had no real backing data. Per-row, same
 * as quantity/pricing_rules above — every service/variant is its own full
 * Lead Connector record with its own copy of this flag.
 */
class EngageProductRental extends Model
{
    use HasUlids;

    protected $table = 'engage_product_rentals';

    protected $fillable = [
        'name',
        'is_active',
        'service_duration',
        'service_duration_unit',
        'slug',
        'map_position',
        'ghl_id',
        'ghl_product_id',
        'listing_price',
        'security_deposit_amount',
        'product_id',
        'service_category_id',
        'service_id',
        'booking_period_type',
        'booking_settings',
        'quantity',
        'pricing_rules',
        'is_variants_enabled',
        'has_quantity_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'service_duration' => 'integer',
            'map_position' => 'json',
            'listing_price' => 'decimal:2',
            'security_deposit_amount' => 'decimal:2',
            'booking_settings' => 'array',
            'quantity' => 'integer',
            'pricing_rules' => 'array',
            'is_variants_enabled' => 'boolean',
            'has_quantity_enabled' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(EngageProduct::class);
    }

    /** Keyed on the raw GHL category id stored here, not a local FK column — see EngageProductRentalCategory::rentals(). */
    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(EngageProductRentalCategory::class, 'service_category_id', 'ghl_category_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(EngageBooking::class, 'product_rental_id');
    }

    /** True when this row is its product's default (base listing) variant. */
    public function isDefault(): bool
    {
        return $this->product?->product_rental_id === $this->id;
    }

    /** GHL base listing row: calendar service id equals master listing id (variantId was null). */
    public function isBaseListing(): bool
    {
        return $this->ghl_id !== null
            && $this->service_id !== null
            && $this->ghl_id === $this->service_id;
    }
}
