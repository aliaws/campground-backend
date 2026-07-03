<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPrice extends Model
{
    use HasUlids;

    protected $fillable = [
        'product_id',
        'name',
        'type',
        'amount',
        'compare_at_price',
        'currency',
        'variant_option_ids',
        'track_inventory',
        'available_quantity',
        'recurring_interval',
        'recurring_interval_count',
        'sku',
        'deleted',
        'engage_price_id',
        'engage_sync_status',
        'sync_error_message',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'variant_option_ids' => 'json',
            'track_inventory' => 'boolean',
            'deleted' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
