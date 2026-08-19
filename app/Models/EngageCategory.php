<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EngageCategory extends Model
{
    use HasUlids;

    public const INDUSTRY_TYPE_POS = 'pos';

    public const INDUSTRY_TYPE_RENTAL = 'rental';

    protected $table = 'engage_categories';

    protected $fillable = [
        'name',
        'slug',
        'image',
        'sort_order',
        'is_active',
        'engage_organization_location_id',
        'engage_collection_id',
        'engage_sync_status',
        'engage_last_synced_at',
        'industry_type',
        'rental_category_id',
        'show_on_homepage',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_on_homepage' => 'boolean',
            'engage_last_synced_at' => 'datetime',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(EngageProduct::class, 'engage_product_categories', 'category_id', 'product_id');
    }

    /**
     * Set only when GHL's calendars/service-categories pull matched this
     * category's engage_collection_id against a service category's
     * associationId — see GhlServiceSyncService::pullServiceCategories().
     */
    public function rentalCategory(): BelongsTo
    {
        return $this->belongsTo(EngageProductRentalCategory::class, 'rental_category_id');
    }
}
