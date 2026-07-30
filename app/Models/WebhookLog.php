<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Outbound/inbound Engage webhook audit log.
 *
 * Optional location scope is stored in the historical `tenant_id` column;
 * app code uses engage_organization_location_id.
 */
class WebhookLog extends Model
{
    use HasUlids;

    public const LOCATION_COLUMN = 'tenant_id';

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

    public function getEngageOrganizationLocationIdAttribute(): ?string
    {
        return $this->attributes[self::LOCATION_COLUMN] ?? null;
    }

    public function setEngageOrganizationLocationIdAttribute(?string $value): void
    {
        $this->attributes[self::LOCATION_COLUMN] = $value;
    }
}
