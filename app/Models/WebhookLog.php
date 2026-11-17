<?php

namespace App\Models;

use App\Services\GhlLocationContext;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Outbound/inbound Engage webhook audit log.
 *
 * The organization is stored in the historical `tenant_id` column; app code
 * uses engage_organization_location_id. A row created without one takes it
 * from the Lead Connector location id in its payload, else from the
 * organization the current request/job is acting for.
 */
class WebhookLog extends Model
{
    use HasUlids;

    public const LOCATION_COLUMN = 'tenant_id';

    /** Outbound calls are logged with an `outbound.` event type prefix. */
    public const OUTBOUND_PREFIX = 'outbound.';

    /** Payload keys that hold a Lead Connector location id (altId is the location on invoice payloads). */
    private const LOCATION_KEYS = ['locationid', 'location_id', 'altid'];

    /**
     * Records a log can link to, by morph alias. Order = priority: when one
     * webhook touches several records (a paid invoice updates the booking and
     * its campsite transaction), the log keeps the one listed first.
     */
    public const LOGGABLE_TYPES = [
        'booking' => EngageBooking::class,
        'product_transaction' => EngageProductTransaction::class,
        'campsite_transaction' => EngageRentalTransaction::class,
        'customer' => EngageCustomer::class,
        'campsite' => EngageProductRental::class,
        'refund' => EngageRefund::class,
    ];

    protected $fillable = [
        'source',
        'event_type',
        'payload',
        'status',
        'engage_organization_location_id',
        'loggable_type',
        'loggable_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'json',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WebhookLog $log) {
            if (! empty($log->attributes[self::LOCATION_COLUMN])) {
                return;
            }

            $payload = is_array($log->payload) ? $log->payload : [];
            $log->attributes[self::LOCATION_COLUMN] = self::organizationIdForPayload($payload)
                ?? app(GhlLocationContext::class)->get();
        });
    }

    public function organizationLocation(): BelongsTo
    {
        return $this->belongsTo(EngageOrganizationLocation::class, self::LOCATION_COLUMN);
    }

    public function getEngageOrganizationLocationIdAttribute(): ?string
    {
        return $this->attributes[self::LOCATION_COLUMN] ?? null;
    }

    public function setEngageOrganizationLocationIdAttribute(?string $value): void
    {
        $this->attributes[self::LOCATION_COLUMN] = $value;
    }

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Link this log to a record. An existing link is only replaced by a record
     * of a higher-priority type (see LOGGABLE_TYPES).
     */
    public function linkTo(Model $record): void
    {
        $alias = $record->getMorphClass();
        $priority = array_keys(self::LOGGABLE_TYPES);

        if (! in_array($alias, $priority, true) || ! $this->exists) {
            return;
        }

        if ($this->loggable_type && $this->loggable_type !== $alias) {
            $current = array_search($this->loggable_type, $priority, true);
            if ($current !== false && $current <= array_search($alias, $priority, true)) {
                return;
            }
        }

        if ($this->loggable_type === $alias && $this->loggable_id) {
            return; // keep the first record of the same type
        }

        $this->forceFill(['loggable_type' => $alias, 'loggable_id' => (string) $record->getKey()])->saveQuietly();
    }

    public function isOutbound(): bool
    {
        return str_starts_with((string) $this->event_type, self::OUTBOUND_PREFIX);
    }

    /** Our organization id for the Lead Connector location named in a payload, or null. */
    public static function organizationIdForPayload(array $payload): ?string
    {
        $ghlLocationId = self::ghlLocationIdFromPayload($payload);

        if (! $ghlLocationId) {
            return null;
        }

        return EngageOrganizationLocation::where('engage_location_id', $ghlLocationId)->value('id');
    }

    /** First Lead Connector location id found in the payload: top-level keys first, then nested ones. */
    public static function ghlLocationIdFromPayload(array $payload): ?string
    {
        $nested = [];

        foreach ($payload as $key => $value) {
            if (is_string($value) && $value !== '' && in_array(strtolower((string) $key), self::LOCATION_KEYS, true)) {
                return $value;
            }
            if (is_array($value)) {
                $nested[] = $value;
            }
        }

        foreach ($nested as $value) {
            if ($found = self::ghlLocationIdFromPayload($value)) {
                return $found;
            }
        }

        return null;
    }
}
