<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'transactionable_type',
        'transactionable_id',
        'total_amount',
        'payment_method',
        'payment_status',
        'invoice_status',
        'transaction_date',
        'engage_organization_location_id',
        'ghl_invoice_id',
        'ghl_invoice_number',
        'ghl_invoice_status',
        'ghl_invoice_url',
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

    /** Booking or Order. */
    public function transactionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function booking(): ?Booking
    {
        return $this->transactionable instanceof Booking ? $this->transactionable : null;
    }

    public function order(): ?Order
    {
        return $this->transactionable instanceof Order ? $this->transactionable : null;
    }

    public function isForBooking(): bool
    {
        return $this->transactionable_type === Booking::class;
    }

    public function isForOrder(): bool
    {
        return $this->transactionable_type === Order::class;
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

    public function ghlInvoiceViewUrl(): ?string
    {
        if (! $this->ghl_invoice_id || ! $this->ghl_invoice_url) {
            return null;
        }

        $host = parse_url($this->ghl_invoice_url, PHP_URL_HOST);
        $scheme = parse_url($this->ghl_invoice_url, PHP_URL_SCHEME) ?? 'https';

        if (! $host) {
            return null;
        }

        return "{$scheme}://{$host}/invoice/{$this->ghl_invoice_id}";
    }
}
