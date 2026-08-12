<?php

namespace App\Models;

use App\Services\OrganizationLocationResolver;
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

    /**
     * No `transactions()` relation — a customer's transaction history now
     * spans two independent tables (RentalTransaction/ProductTransaction),
     * which don't collapse into one HasMany. CustomerService::hardDelete()
     * (the only place this used to be called) queries both directly.
     */
    public function locationLinks(): HasMany
    {
        return $this->hasMany(CustomerLocation::class);
    }

    /**
     * True when this customer has a real link to the given organization —
     * Customer has no direct engage_organization_location_id column of its
     * own (a customer can span multiple organizations via
     * customers_locations), so every controller action taking a
     * route-bound Customer must check this rather than a simple column
     * comparison, or a staff member could view/edit/delete another
     * organization's customer just by knowing/guessing its id.
     */
    public function belongsToLocation(string $locationId): bool
    {
        return $this->relationLoaded('locationLinks')
            ? $this->locationLinks->contains('engage_organization_location_id', $locationId)
            : $this->locationLinks()->where('engage_organization_location_id', $locationId)->exists();
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
        $locationId ??= OrganizationLocationResolver::resolveDefaultLocationId();

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
