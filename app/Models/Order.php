<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** POS product-sale header — linked to Transaction via morph. */
class Order extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'status',
        'total_amount',
        'engage_organization_location_id',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }

    public function primaryTransaction(): ?Transaction
    {
        $this->loadMissing('transactions');

        return $this->transactions->sortByDesc('created_at')->first();
    }
}
