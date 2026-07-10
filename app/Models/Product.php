<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'name',
        'product_type',
        'description',
        'status',
        'available_in_store',
        'image',
        'tax_inclusive',
        'is_taxes_enabled',
        'tenant_id',
        'slug',
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
            'tax_inclusive' => 'boolean',
            'is_taxes_enabled' => 'boolean',
            'track_product_inventory' => 'boolean',
            'engage_last_synced_at' => 'datetime',
            'quantity' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeByTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
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
        return $this->belongsToMany(Category::class, 'product_categories');
    }

    /** All rental variants of this listing (the default/base row included). */
    public function rentals(): HasMany
    {
        return $this->hasMany(ProductRental::class);
    }

    /** The default (base listing) rental variant — FK may be stale until next pull. */
    public function defaultRental(): BelongsTo
    {
        return $this->belongsTo(ProductRental::class, 'product_rental_id');
    }

    /**
     * GHL base listing row: calendar service where variantId was null
     * (ghl_id === service_id on the local row).
     */
    public function resolveBaseRental(): ?ProductRental
    {
        if ($this->relationLoaded('rentals')) {
            $base = $this->rentals->first(fn (ProductRental $r) => $r->isBaseListing());
            if ($base) {
                return $base;
            }
        }

        $base = $this->rentals()->whereColumn('ghl_id', 'service_id')->first();

        return $base ?? $this->defaultRental;
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
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
