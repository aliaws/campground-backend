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
        'type',
        'sub_type',
        'category_id',
        'base_price',
        'stock_qty',
        'capacity',
        'location',
        'rental_duration_unit',
        'min_rental_duration',
        'max_rental_duration',
        'status',
        'image_url',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
        ];
    }

    public function scopeCampsites($query)
    {
        return $query->where('type', 'rental')->where('sub_type', 'campsite');
    }

    public function scopeRentals($query)
    {
        return $query->where('type', 'rental');
    }

    public function scopePhysical($query)
    {
        return $query->where('type', 'physical');
    }

    public function scopeService($query)
    {
        return $query->where('type', 'service');
    }

    public function scopeAddon($query)
    {
        return $query->where('type', 'addon');
    }

    public function scopeByTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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

    public function isCampsite(): bool
    {
        return $this->type === 'rental' && $this->sub_type === 'campsite';
    }

    public function isRental(): bool
    {
        return $this->type === 'rental';
    }

    public function isPhysical(): bool
    {
        return $this->type === 'physical';
    }
}
