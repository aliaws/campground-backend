<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Booking extends Model
{
    use HasUlids;

    protected $fillable = [
        'customer_id',
        'product_id',
        'product_rental_id',
        'check_in_date',
        'check_out_date',
        'check_in',
        'check_out',
        'booking_start_time',
        'booking_end_time',
        'quantity',
        'notes',
        'base_amount',
        'discount_amount',
        'total_amount',
        'security_deposit_amount',
        'price_breakdown',
        'status',
        'ghl_opportunity_id',
        'ghl_booking_id',
        'engage_organization_location_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'check_in' => 'datetime',
            'check_out' => 'datetime',
            'quantity' => 'integer',
            'base_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'security_deposit_amount' => 'decimal:2',
            'price_breakdown' => 'json',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productRental(): BelongsTo
    {
        return $this->belongsTo(ProductRental::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }

    /** Invoice-bearing transaction for this booking (GHL invoice lives here). */
    public function primaryTransaction(): ?Transaction
    {
        $this->loadMissing('transactions');

        return $this->transactions->first(fn (Transaction $t) => filled($t->ghl_invoice_id))
            ?? $this->transactions->sortByDesc('created_at')->first();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isPaid(): bool
    {
        $this->loadMissing('transactions');

        return $this->transactions->contains(fn ($t) => $t->isPaid());
    }

    public function ghlInvoiceViewUrl(): ?string
    {
        return $this->primaryTransaction()?->ghlInvoiceViewUrl();
    }
}
