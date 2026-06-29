<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariation extends Model
{
    use HasUlids;

    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'price_modifier',
    ];

    protected function casts(): array
    {
        return [
            'price_modifier' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
