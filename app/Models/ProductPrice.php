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
        'label',
        'price',
        'valid_from',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
