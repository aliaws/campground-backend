<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Services-module counterpart to Category (Product Categories) —
 * mirrors its shape but is keyed to GHL by `ghl_category_id`
 * (calendars/service-categories' `_id`), not `engage_collection_id`
 * (products/collections), and relates to rentals rather than products.
 */
class ProductRentalCategory extends Model
{
    use HasUlids;

    protected $table = 'product_rental_categories';

    protected $fillable = [
        'name',
        'is_active',
        'ghl_category_id',
        'engage_sync_status',
        'engage_last_synced_at',
        'engage_organization_location_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'engage_last_synced_at' => 'datetime',
        ];
    }

    /**
     * A rental only ever belongs to one category, so this is a straight
     * hasMany keyed on the raw GHL id ProductRental already stores — not a
     * pivot (unlike Category::products(), which is many-to-many).
     */
    public function rentals(): HasMany
    {
        return $this->hasMany(EngageProductRental::class, 'service_category_id', 'ghl_category_id');
    }
}
