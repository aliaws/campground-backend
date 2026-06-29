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
        'currency',
        'variation_id',
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
            'track_inventory' => 'boolean',
            'deleted' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }
}
