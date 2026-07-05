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

    public const RULE_TYPES = ['date_range', 'day_of_week', 'duration_discount', 'quantity_discount'];

    public const VALUE_TYPES = ['flat', 'percentage'];

    protected $fillable = [
        'name',
        'product_type',
        'parent_product_id',
        'variant_name',
        'description',
        'sku',
        'status',
        'is_variable',
        'available_in_store',
        'image',
        'thumbnail',
        'medias',
        'display_priority',
        'tax_inclusive',
        'is_taxes_enabled',
        'site_type',
        'capacity',
        'available_quantity',
        'hookups',
        'map_position',
        'map_polygon',
        'pet_friendly',
        'ada_accessible',
        'campsite_status',
        'booking_unit',
        'min_duration',
        'max_duration',
        'duration_unit',
        'booking_start_time',
        'booking_end_time',
        'max_quantity',
        'pricing_rule',
        'tenant_id',
        'slug',
        'track_product_inventory',
        'ghl_image_url',
        'ghl_service_id',
        'industry_type',
        'ghl_service_category_id',
        'ghl_service_location_id',
        'ghl_metadata',
        'engage_product_id',
        'engage_sync_status',
        'engage_last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'medias' => 'json',
            'hookups' => 'json',
            'map_position' => 'json',
            'map_polygon' => 'json',
            'pricing_rule' => 'json',
            'ghl_metadata' => 'json',
            'is_variable' => 'boolean',
            'available_in_store' => 'boolean',
            'tax_inclusive' => 'boolean',
            'is_taxes_enabled' => 'boolean',
            'pet_friendly' => 'boolean',
            'ada_accessible' => 'boolean',
            'track_product_inventory' => 'boolean',
            'engage_last_synced_at' => 'datetime',
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

    public function scopeIndustryType($query, string $industryType)
    {
        return $query->where('industry_type', $industryType);
    }

    public function scopePhysical($query)
    {
        return $query->where('product_type', 'PHYSICAL');
    }

    public function scopeDigital($query)
    {
        return $query->where('product_type', 'DIGITAL');
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories');
    }

    /** Base product this service variant belongs to (GHL: service.variantId) */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    /** Service variants stored as their own product rows (GHL Services model) */
    public function serviceVariants(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_product_id');
    }

    /**
     * Storefront "From $/day" price: cheapest base price across this service
     * and its variants; falls back to the first product price.
     */
    public function fromPrice(): ?float
    {
        $candidates = collect([$this->pricing_rule['base_price'] ?? null])
            ->concat(
                $this->relationLoaded('serviceVariants')
                    ? $this->serviceVariants->map(fn (Product $v) => $v->pricing_rule['base_price'] ?? null)
                    : []
            )
            ->filter(fn ($price) => $price !== null)
            ->map(fn ($price) => (float) $price);

        if ($candidates->isNotEmpty()) {
            return $candidates->min();
        }

        $fallback = $this->relationLoaded('prices')
            ? $this->prices->min('amount')
            : $this->prices()->min('amount');

        return $fallback !== null ? (float) $fallback : null;
    }

    /** The pricing_rule JSON's rule list, ordered by sequence and ready to apply. */
    public function orderedPricingRules(): array
    {
        $rules = $this->pricing_rule['rules'] ?? [];

        usort($rules, fn ($a, $b) => ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0));

        return $rules;
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /** Variant groups (e.g. "Size", "Color") */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    /** All variant options across all variant groups — convenience shortcut */
    public function variantOptions(): HasMany
    {
        return $this->hasMany(ProductVariantOption::class);
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'product_amenities');
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'product_features');
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

    /** Bookable campsite/rental — SERVICE products are the bookable type */
    public function isCampsite(): bool
    {
        return $this->isService();
    }

    public function isServiceVariant(): bool
    {
        return $this->parent_product_id !== null;
    }

    /** Scheduling-layer rental service ID used for GHL calendar bookings. */
    public function ghlBookingServiceId(): ?string
    {
        return $this->ghl_service_id;
    }

    /** Payments-layer product ID (auto-created by GHL per service/variant). */
    public function ghlPaymentsProductId(): ?string
    {
        return $this->engage_product_id;
    }

    /** Base listing's scheduling service ID (self when base, parent's when variant). */
    public function ghlBaseServiceId(): ?string
    {
        if ($this->isServiceVariant()) {
            return $this->parent?->ghl_service_id;
        }

        return $this->ghl_service_id;
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
