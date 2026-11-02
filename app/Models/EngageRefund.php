<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund recorded against a paid campsite or product transaction. The
 * money itself is sent back from Lead Connector (its API has no refund
 * call); see the engage_refunds migration.
 */
class EngageRefund extends Model
{
    use HasUlids;

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_CONFIRMED = 'confirmed';

    protected $table = 'engage_refunds';

    protected $fillable = [
        'engage_organization_location_id',
        'rental_transaction_id',
        'product_transaction_id',
        'booking_id',
        'customer_id',
        'amount',
        'reason',
        'status',
        'ghl_invoice_id',
        'booking_cancelled',
        'created_by_user_id',
        'created_by',
        'confirmed_at',
        'confirmed_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'booking_cancelled' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    public function rentalTransaction(): BelongsTo
    {
        return $this->belongsTo(EngageRentalTransaction::class, 'rental_transaction_id');
    }

    public function productTransaction(): BelongsTo
    {
        return $this->belongsTo(EngageProductTransaction::class, 'product_transaction_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(EngageBooking::class, 'booking_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(EngageCustomer::class, 'customer_id')->withTrashed();
    }

    public function scopeByLocation(Builder $query, string $locationId): Builder
    {
        return $query->where('engage_organization_location_id', $locationId);
    }

    /** 'campsite' or 'product'. */
    public function type(): string
    {
        return $this->rental_transaction_id || $this->booking_id ? 'campsite' : 'product';
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }
}
