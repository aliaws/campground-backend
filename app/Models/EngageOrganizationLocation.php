<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class EngageOrganizationLocation extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'legal_business_name',
        'business_email',
        'business_phone',
        'business_country_code',
        'business_website',
        'business_niche',
        'street_address',
        'city',
        'postal_code',
        'state',
        'country',
        'timezone',
        'business_information',
        'engage_location_id',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'business_information' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function engageTokens(): HasMany
    {
        return $this->hasMany(EngageToken::class, 'engage_organization_location_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'users_locations',
            'engage_organization_location_id',
            'user_id'
        )->withTimestamps();
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(
            Customer::class,
            'customers_locations',
            'engage_organization_location_id',
            'customer_id'
        )->withPivot(['id', 'ghl_contact_id'])->withTimestamps();
    }

    /** Enforce a single system-wide default location. */
    public static function markAsDefault(self $location): self
    {
        return DB::transaction(function () use ($location) {
            static::query()
                ->where('is_default', true)
                ->where('id', '!=', $location->id)
                ->update(['is_default' => false]);

            $location->is_default = true;
            $location->save();

            return $location->fresh();
        });
    }
}
