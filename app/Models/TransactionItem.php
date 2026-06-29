<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'transaction_id',
        'product_id',
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

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
