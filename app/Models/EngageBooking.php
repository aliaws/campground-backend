<?php

namespace App\Models;

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
