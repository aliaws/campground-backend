<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Product extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'id', // set explicitly for shared-PK product_rentals
        'name',
        'product_type',
        'description',
        'status',
        'available_in_store',
        'image',
        'tax_inclusive',
        'is_taxes_enabled',
        'engage_organization_location_id',
        'slug',
        'sku',
        'quantity',
        'price',
        'is_rental',
        'track_product_inventory',
        'ghl_image_url',
        'ghl_product_id',
        'engage_sync_status',
        'engage_last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'available_in_store' => 'boolean',
            'tax_inclusive' => 'boolean',
            'is_taxes_enabled' => 'boolean',
            'track_product_inventory' => 'boolean',
            'is_rental' => 'boolean',
            'engage_last_synced_at' => 'datetime',
            'quantity' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeByLocation($query, string $locationId)
    {
        return $query->where('engage_organization_location_id', $locationId);
    }

    public function scopeService($query)
    {
        return $query->where('product_type', 'SERVICE');
    }

    public function scopePhysical($query)
    {
        return $query->where('product_type', 'PHYSICAL');
    }

    public function scopeDigital($query)
    {
        return $query->where('product_type', 'DIGITAL');
    }

    /** Any product with a 1:1 product_rentals row. */
    public function scopeRental($query)
    {
        return $query->where('is_rental', true);
    }

    /**
     * Catalog listings only (GHL base / master). Variant products stay 1:1
     * with their rental row but are not separate storefront cards.
     */
    public function scopeBaseRentalListing($query)
    {
        return $query->where('is_rental', true)
            ->whereHas('productRental', fn (Builder $q) => $q->whereColumn('ghl_id', 'service_id'));
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories');
    }

    /**
     * Amenities for this rental listing. Pivot is product_rental_amenities;
     * works because rental products share id with product_rentals.
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'product_rental_amenities', 'product_rental_id', 'amenity_id');
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'product_rental_features', 'product_rental_id', 'feature_id');
    }

    /**
     * 1:1 rental extension — shared PK + FK (product_rentals.id = products.id).
     */
    public function productRental(): HasOne
    {
        return $this->hasOne(ProductRental::class, 'id', 'id');
    }

    /** @see productRental() */
    public function defaultRental(): HasOne
    {
        return $this->productRental();
    }

    /**
     * Lazy-load the GHL listing family into the `rentals` relation
     * (siblings share product_rentals.service_id).
     */
    public function loadRentalFamily(): static
    {
        if ($this->relationLoaded('rentals')) {
            return $this;
        }

        $this->loadMissing('productRental');
        $serviceId = $this->productRental?->service_id;
        $this->setRelation(
            'rentals',
            $serviceId
                ? ProductRental::query()->where('service_id', $serviceId)->get()
                : Collection::make()
        );

        return $this;
    }

    /** @return Collection<int, ProductRental> */
    public function rentalFamily(): Collection
    {
        $this->loadRentalFamily();

        return $this->getRelation('rentals');
    }

    public function resolveBaseRental(): ?ProductRental
    {
        if ($this->relationLoaded('rentals')) {
            $base = $this->rentals->first(fn (ProductRental $r) => $r->isBaseListing());
            if ($base) {
                return $base;
            }

            return $this->rentals->first(fn (ProductRental $r) => $r->id === $this->id)
                ?? $this->rentals->first();
        }

        $this->loadMissing('productRental');
        if ($this->productRental?->isBaseListing()) {
            return $this->productRental;
        }

        $serviceId = $this->productRental?->service_id;
        if ($serviceId) {
            return ProductRental::query()
                ->where('service_id', $serviceId)
                ->whereColumn('ghl_id', 'service_id')
                ->first()
                ?? $this->productRental;
        }

        return $this->productRental;
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isService(): bool
    {
        return $this->product_type === 'SERVICE';
    }

    public function isRental(): bool
    {
        return (bool) $this->is_rental;
    }

    public function defaultVariantPrice(): ?float
    {
        $base = $this->resolveBaseRental();

        if ($base?->listing_price !== null) {
            return (float) $base->listing_price;
        }

        return $this->price !== null ? (float) $this->price : null;
    }

    /** @deprecated Use defaultVariantPrice() */
    public function fromPrice(): ?float
    {
        return $this->defaultVariantPrice();
    }

    public function isPhysical(): bool
    {
        return $this->product_type === 'PHYSICAL';
    }

    public function isDigital(): bool
    {
        return $this->product_type === 'DIGITAL';
    }
}
