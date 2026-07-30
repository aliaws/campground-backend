<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLocation extends Model
{
    use HasUlids;

    protected $table = 'customers_locations';

    protected $fillable = [
        'customer_id',
        'engage_organization_location_id',
        'ghl_contact_id',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function organizationLocation(): BelongsTo
    {
        return $this->belongsTo(EngageOrganizationLocation::class, 'engage_organization_location_id');
    }
}
