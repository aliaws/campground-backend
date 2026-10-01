<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EngageBooking extends Model
{
    use HasUlids;

    protected $table = 'engage_bookings';

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
        'ghl_invoice_id',
        'ghl_invoice_number',
        'ghl_invoice_status',
        'ghl_invoice_url',
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
        // withTrashed() so a booking's customer info still resolves after
        // the customer is archived (soft-deleted) — otherwise the default
        // belongsTo query excludes it and every list showing this relation
        // (Bookings, Rental Transactions) falls back to "Unknown". See
        // "Customer Archive" under Key Business Logic.
        return $this->belongsTo(EngageCustomer::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(EngageProduct::class);
    }

    /** The rental variant that was booked (null on legacy/local-only rows). */
    public function productRental(): BelongsTo
    {
        return $this->belongsTo(EngageProductRental::class);
    }

    public function organizationLocation(): BelongsTo
    {
        return $this->belongsTo(EngageOrganizationLocation::class, 'engage_organization_location_id');
    }

    /**
     * Retargeted to EngageRentalTransaction as of the 2026-08-10 transactions
     * refactor (was Transaction — that generic table/model no longer
     * exists). The relation name itself is deliberately kept as
     * `transactions` rather than renamed to `rentalTransactions` — this is
     * what lets every existing `->load(['...', 'transactions'])` call site
     * across BookingService/BookingController/CustomerPortalController/
     * GhlService/ReportService keep working unchanged.
     */
    /** Hours an invoice must have gone unpaid before it may be voided (config/booking.php, see canVoidInvoice()). */
    public static function voidInvoiceAfterHours(): int
    {
        return max(0, (int) config('booking.void_invoice_after_hours', 24));
    }

    /** Hours an unpaid booking may stay without any invoice before the scheduled command cancels it (config/booking.php). */
    public static function cancelWithoutInvoiceAfterHours(): int
    {
        return max(1, (int) config('booking.auto_cancel_without_invoice_after_hours', 24));
    }

    /** SQL counterpart of isAwaitingInvoice(), for batch work. */
    public function scopeAwaitingInvoice(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'requested'])
            ->whereNull('ghl_booking_id')
            ->whereNull('ghl_invoice_id')
            ->whereDoesntHave('transactions', fn (Builder $q) => $q->where('status', 'paid'));
    }

    /**
     * Nothing exists in Lead Connector for this booking yet: no calendar
     * booking and no invoice, and it isn't paid, confirmed or cancelled.
     * That is a cash/pay-later booking nobody has collected on, or a
     * booking whose invoice could not be created. Staff can create and send
     * its invoice or cancel it; left alone it is cancelled automatically.
     * Needs `transactions` loaded (isPaid()).
     */
    public function isAwaitingInvoice(): bool
    {
        return in_array($this->status, ['pending', 'requested'], true)
            && empty($this->ghl_booking_id)
            && empty($this->ghl_invoice_id)
            && ! $this->isPaid();
    }

    /**
     * SQL counterpart of hasOpenUnpaidInvoice() — narrows a query to bookings
     * that could have their invoice voided. Only a pre-filter for batch work:
     * each row is still checked by canVoidInvoice() before anything happens.
     */
    public function scopeWithOpenUnpaidInvoice(Builder $query): Builder
    {
        return $query->where('status', '!=', 'cancelled')
            ->whereNotNull('ghl_invoice_id')
            ->where(fn (Builder $q) => $q->whereNull('ghl_invoice_status')->orWhereNotIn('ghl_invoice_status', ['paid', 'void']))
            ->whereDoesntHave('transactions', fn (Builder $q) => $q->where('status', 'paid'));
    }

    /**
     * An invoice that can still be acted on: the booking isn't cancelled or
     * paid, and it has a Lead Connector invoice that hasn't been voided.
     * Needs `transactions` loaded (isPaid()).
     */
    public function hasOpenUnpaidInvoice(): bool
    {
        return $this->status !== 'cancelled'
            && ! empty($this->ghl_invoice_id)
            && ! in_array($this->ghl_invoice_status, ['paid', 'void'], true)
            && ! $this->isPaid();
    }

    /** Staff may send the unpaid invoice to the customer again at any time. */
    public function canResendInvoice(): bool
    {
        return $this->hasOpenUnpaidInvoice();
    }

    /** The invoice may be voided (and the booking cancelled) only once it has gone unpaid for the configured hours — 24 by default. */
    public function canVoidInvoice(): bool
    {
        return $this->hasOpenUnpaidInvoice()
            && $this->created_at !== null
            && $this->created_at->lte(now()->subHours(self::voidInvoiceAfterHours()));
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(EngageRentalTransaction::class, 'booking_id');
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

    /**
     * Public GHL-hosted invoice view page (not gated behind a GHL login,
     * unlike the GHL dashboard invoice URL) — e.g.
     * https://msgr.accuratedigitalsolutions.com/invoice/{ghl_invoice_id}.
     * GHL's invoice API doesn't return this URL directly, so it's derived
     * from the same white-label domain already present on the booking's own
     * `ghl_invoice_url` (the Text2Pay payment link, e.g. .../l/{code}) —
     * this keeps it correct per-tenant without hardcoding any one account's
     * domain.
     */
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
