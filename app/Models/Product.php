<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
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
        'tenant_id',
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
            'is_variable' => 'boolean',
            'available_in_store' => 'boolean',
            'tax_inclusive' => 'boolean',
            'is_taxes_enabled' => 'boolean',
            'pet_friendly' => 'boolean',
            'ada_accessible' => 'boolean',
            'engage_last_synced_at' => 'datetime',
        ];
    }

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

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
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

    public function isService(): bool
    {
        return $this->product_type === 'SERVICE';
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
