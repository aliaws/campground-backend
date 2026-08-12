<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The sole source of truth for every rental/booking transaction — GHL
 * pull/cron, POS bookings, and customer-portal bookings all write here
 * (2026-08-10 refactor; previously this was a GHL-pull-only read ledger,
 * separate from the now-removed generic `transactions` table).
 * `status` holds the same lifecycle the old `transactions.payment_status`
 * did: 'draft'|'pending'|'paid'. `booking_id` links back to the owning
 * `Booking` — `Booking::transactions()` resolves here now.
 */
class RentalTransaction extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'ghl_invoice_id',
        'ghl_booking_id',
        'booking_id',
        'customer_id',
        'customer_name',
        'customer_email',
        'product_id',
        'product_rental_id',
        'rental_name',
        'amount',
        'status',
        'payment_method',
        'quantity',
        'unit_price',
        'check_in_date',
        'check_out_date',
        'notes',
        'paid_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'paid_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        // withTrashed() — same reasoning as Booking::customer(), see there.
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productRental(): BelongsTo
    {
        return $this->belongsTo(ProductRental::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
