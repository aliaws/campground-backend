<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EngageProduct extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'engage_products';

    protected $fillable = [
        'name',
        'product_type',
        'description',
        'status',
        'is_active',
        'available_in_store',
        'image',
        'images',
        'tax_inclusive',
        'is_taxes_enabled',
        'engage_organization_location_id',
        'slug',
        'sku',
        'quantity',
        'price',
        'product_rental_id',
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
            'is_active' => 'boolean',
            'images' => 'array',
            'tax_inclusive' => 'boolean',
            'is_taxes_enabled' => 'boolean',
            'track_product_inventory' => 'boolean',
            'engage_last_synced_at' => 'datetime',
            'quantity' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    /**
     * `image` is a computed attribute, not a real column — `images` (a real
     * JSON column) is the single source of truth for every image this
     * product has. This keeps every pre-existing `$product->image` read and
     * `['image' => $url]` write across the app (product cards, the staff
     * upload form, GhlImageSyncService/GhlProductSyncService's GHL-CDN
     * upload-cache logic, etc.) working completely unchanged: reading
     * returns the position:0 image's URL, and writing replaces (or
     * removes, on null) just the position:0 entry while preserving every
     * other image a multi-photo rental service might have — a single-image
     * write path (a regular product upload, a GHL products pull) can never
     * accidentally wipe out a rental's other gallery photos.
     */
    protected function image(): Attribute
    {
        return Attribute::make(
            get: function () {
                $images = $this->images ?? [];
                if ($images === []) {
                    return null;
                }

                $first = collect($images)->firstWhere('position', 0) ?? $images[0];

                return $first['url'] ?? null;
            },
            set: function (?string $value) {
                $rest = collect($this->images ?? [])
                    ->filter(fn ($img) => ($img['position'] ?? null) !== 0)
                    ->values()
                    ->all();

                // A mutator that returns [otherAttribute => value] bypasses
                // that attribute's own cast pipeline entirely (Laravel sets
                // the raw value straight into $attributes) — `images` still
                // has its own `array` cast via casts(), which expects a JSON
                // string in storage, so it must be encoded here explicitly
                // or the next read of $product->images crashes json_decode()
                // on an already-decoded array. Confirmed via a live crash
                // during verification, not a hypothetical.
                if ($value === null || $value === '') {
                    return ['images' => json_encode($rest)];
                }

                $newFirst = ['url' => $value, 'name' => $this->name, 'position' => 0, '_id' => null];

                return ['images' => json_encode(array_merge([$newFirst], $rest))];
            },
        );
    }

    /**
     * The images array to expose when no live GHL data is available — the
     * real stored `images` column (populated by GhlServiceSyncService's
     * pull, see "Support Multiple Service Images"), or an empty array when
     * this product has no image at all.
     *
     * @return array<int, array{_id: ?string, url: ?string, name: ?string, position: int}>
     */
    public function localImagesFallback(): array
    {
        return $this->images ?? [];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeByLocation($query, string $tenantId)
    {
        return $query->where('engage_organization_location_id', $tenantId);
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

    /** Bookable rental listings: products with a GHL-linked default rental variant. */
    public function scopeRental($query)
    {
        return $query->whereNotNull('product_rental_id');
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(EngageCategory::class, 'engage_product_categories', 'product_id', 'category_id');
    }

    /** Added for the public cross-org storefront (2026-08-19) — lets a guest see which organization/"store" a product belongs to, since a cart can only ever check out items from one organization at a time. */
    public function organizationLocation(): BelongsTo
    {
        return $this->belongsTo(EngageOrganizationLocation::class, 'engage_organization_location_id');
    }

    /** Amenities assigned to this service listing (Services module concept — see engage_product_rental_amenities). */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'engage_product_rental_amenities', 'product_id', 'amenity_id');
    }

    /** Features assigned to this service listing (Services module concept — see engage_product_rental_features). */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'engage_product_rental_features', 'product_id', 'feature_id');
    }

    /** All rental variants of this listing (the default/base row included). */
    public function rentals(): HasMany
    {
        return $this->hasMany(EngageProductRental::class, 'product_id');
    }

    /** The default (base listing) rental variant — FK may be stale until next pull. */
    public function defaultRental(): BelongsTo
    {
        return $this->belongsTo(EngageProductRental::class, 'product_rental_id');
    }

    /**
     * GHL base listing row: calendar service where variantId was null
     * (ghl_id === service_id on the local row).
     */
    public function resolveBaseRental(): ?EngageProductRental
    {
        if ($this->relationLoaded('rentals')) {
            $base = $this->rentals->first(fn (EngageProductRental $r) => $r->isBaseListing());
            if ($base) {
                return $base;
            }
        }

        $base = $this->rentals()->whereColumn('ghl_id', 'service_id')->first();

        return $base ?? $this->defaultRental;
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(EngageBooking::class, 'product_id');
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

    /**
     * Bookable rental listing — has a GHL-linked default variant. This is the
     * single deciding rule (replaces the old rentals.industry_type check):
     * only the GHL rental pull creates product_rentals rows, so local-only
     * SERVICE products are never bookable rentals by construction.
     */
    public function isRental(): bool
    {
        return $this->product_rental_id !== null;
    }

    /** Storefront "From $/day" — always the GHL base variant (variantId = null). */
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
