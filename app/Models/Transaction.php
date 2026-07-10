<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'booking_id',
        'total_amount',
        'payment_method',
        'payment_status',
        'invoice_status',
        'transaction_date',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'transaction_date' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isPending(): bool
    {
        return $this->payment_status === 'pending';
    }

    public function isDraft(): bool
    {
        return $this->payment_status === 'draft';
    }
}
