<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom fields are scoped per Engage organization location.
 *
 * The DB column is still named `tenant_id` (historical). App code uses
 * engage_organization_location_id only.
 */
class CustomField extends Model
{
    use HasUlids;

    public const LOCATION_COLUMN = 'tenant_id';

    protected $fillable = [
        'entity_type',
        'field_name',
        'field_type',
        'engage_organization_location_id',
    ];

    public function getEngageOrganizationLocationIdAttribute(): ?string
    {
        return $this->attributes[self::LOCATION_COLUMN] ?? null;
    }

    public function setEngageOrganizationLocationIdAttribute(?string $value): void
    {
        $this->attributes[self::LOCATION_COLUMN] = $value;
    }

    public function scopeForOrganizationLocation($query, string $locationId)
    {
        return $query->where(self::LOCATION_COLUMN, $locationId);
    }
}
