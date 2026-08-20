<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The sole source of truth for every product-sale transaction — GHL
 * pull/cron, POS Product Sales, and the New Booking cart's "extras" all
 * write here (2026-08-10 refactor; previously this was a GHL-pull-only
 * read ledger, separate from the now-removed generic `transactions`
 * table). `status` holds the same lifecycle the old
 * `transactions.payment_status` did: 'draft'|'pending'|'paid'.
 * `booking_id` is set only for the "extras" case (a product-sale
 * transaction created alongside a rental booking) — null for an ordinary
 * walk-up POS sale or a GHL-synced invoice. Line items live on the
 * `items()` relation (`ProductTransactionItem`) going forward — the
 * legacy `items` JSON column is kept but frozen (no longer written).
 */
class EngageProductTransaction extends Model
{
    use HasUlids;

    protected $table = 'engage_product_transactions';

    protected $fillable = [
        'engage_organization_location_id',
        'ghl_invoice_id',
        'ghl_invoice_number',
        'ghl_invoice_status',
        'ghl_invoice_url',
        'booking_id',
        'customer_id',
        'customer_name',
        'customer_email',
        'amount',
        'status',
        'payment_method',
        'items',
        'paid_at',
        'invoice_date',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'items' => 'array',
            'paid_at' => 'datetime',
            'invoice_date' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(EngageCustomer::class);
    }

    public function organizationLocation(): BelongsTo
    {
        return $this->belongsTo(EngageOrganizationLocation::class, 'engage_organization_location_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(EngageBooking::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EngageProductTransactionItem::class, 'product_transaction_id');
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

    /**
     * Public GHL-hosted invoice view page (derived from ghl_invoice_url host).
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
