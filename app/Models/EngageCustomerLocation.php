<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngageCustomerLocation extends Model
{
    use HasUlids;

    protected $table = 'engage_customers_locations';

    protected $fillable = [
        'customer_id',
        'engage_organization_location_id',
        'ghl_contact_id',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(EngageCustomer::class);
    }

    public function organizationLocation(): BelongsTo
    {
        return $this->belongsTo(EngageOrganizationLocation::class, 'engage_organization_location_id');
    }
}
