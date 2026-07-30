<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'ghl_sync_status',
        'ghl_last_synced_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'json',
            'ghl_last_synced_at' => 'datetime',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function locationLinks(): HasMany
    {
        return $this->hasMany(CustomerLocation::class);
    }

    public function organizationLocations(): BelongsToMany
    {
        return $this->belongsToMany(
            EngageOrganizationLocation::class,
            'customers_locations',
            'customer_id',
            'engage_organization_location_id'
        )->withPivot(['id', 'ghl_contact_id'])->withTimestamps();
    }

    /** The customer portal login linked to this customer, if one has been created. */
    public function customerAccount(): HasOne
    {
        return $this->hasOne(User::class, 'customer_id');
    }

    public function ghlContactIdFor(?string $locationId = null): ?string
    {
        $locationId ??= \App\Services\OrganizationLocationResolver::resolveDefaultLocationId();

        $link = $this->relationLoaded('locationLinks')
            ? $this->locationLinks->firstWhere('engage_organization_location_id', $locationId)
            : $this->locationLinks()->where('engage_organization_location_id', $locationId)->first();

        return $link?->ghl_contact_id;
    }

    public function setGhlContactIdFor(string $locationId, ?string $ghlContactId): CustomerLocation
    {
        $link = $this->locationLinks()->firstOrNew([
            'engage_organization_location_id' => $locationId,
        ]);
        $link->ghl_contact_id = $ghlContactId;
        $link->customer_id = $this->id;
        $link->save();

        return $link;
    }

    public function attachLocation(string $locationId, ?string $ghlContactId = null): CustomerLocation
    {
        return $this->setGhlContactIdFor($locationId, $ghlContactId);
    }
}
