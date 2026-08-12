<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductTransactionItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'product_transaction_id',
        'product_id',
        'product_name_snapshot',
        'product_type',
        'quantity',
        'unit_price',
        'rental_start',
        'rental_end',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'rental_start' => 'date',
            'rental_end' => 'date',
        ];
    }

    public function productTransaction(): BelongsTo
    {
        return $this->belongsTo(ProductTransaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
