<?php

namespace App\Models;

use App\Services\GhlLocationContext;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected $fillable = [
        'source',
        'event_type',
        'payload',
        'status',
        'engage_organization_location_id',
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
