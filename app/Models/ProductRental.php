<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 1:1 extension of Product. id is PK and FK → products.id (no product_id column).
 * Variants in the same GHL listing share service_id (= master listing ghl id).
 */
class ProductRental extends Model
{
    use HasUlids;

    protected $fillable = [
        'id',
        'name',
        'is_active',
        'service_duration',
        'service_duration_unit',
        'slug',
        'map_position',
        'ghl_id',
        'ghl_product_id',
        'listing_price',
        'quantity',
        'max_quantity',
        'service_category_id',
        'service_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'service_duration' => 'integer',
            'map_position' => 'json',
            'listing_price' => 'decimal:2',
            'quantity' => 'integer',
            'max_quantity' => 'integer',
        ];
    }

    /** Owning Product — shared primary key (id = products.id). */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'id', 'id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'product_rental_amenities');
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'product_rental_features');
    }

    /** GHL base listing row (variantId was null): ghl_id === service_id. */
    public function isBaseListing(): bool
    {
        return $this->ghl_id !== null
            && $this->service_id !== null
            && $this->ghl_id === $this->service_id;
    }

    public function isDefault(): bool
    {
        return $this->isBaseListing();
    }

    /** All variants in this GHL listing family (base included). */
    public function family()
    {
        return static::query()->where('service_id', $this->service_id);
    }

    /** Product id of the base listing for this family. */
    public function listingProductId(): ?string
    {
        if ($this->isBaseListing()) {
            return $this->id;
        }

        return static::query()
            ->where('service_id', $this->service_id)
            ->whereColumn('ghl_id', 'service_id')
            ->value('id');
    }
}
