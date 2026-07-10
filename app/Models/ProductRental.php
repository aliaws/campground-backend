<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bookable rental/service variant. The base listing is stored here too as
 * the product's "default" row (products.product_rental_id). Only the GHL pull
 * creates rows — everything beyond these identifiers (durations, quantity,
 * pricing rules, booking times) is fetched live from GHL, never stored.
 */
class ProductRental extends Model
{
    use HasUlids;

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
        'product_id',
        'service_category_id',
        'service_id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'service_duration' => 'integer',
            'map_position' => 'json',
            'listing_price' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
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
