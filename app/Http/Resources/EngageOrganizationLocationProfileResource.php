<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner/admin's own-organization business profile view (Profile page) —
 * deliberately a separate resource from EngageOrganizationLocationResource
 * (superadmin's cross-org "identifiers only" view), since this one is for
 * the org's own owner/admin editing their own full business detail, not a
 * platform-wide read-only drill-down.
 */
class EngageOrganizationLocationProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_business_name' => $this->legal_business_name,
            'business_email' => $this->business_email,
            'business_phone' => $this->business_phone,
            'business_country_code' => $this->business_country_code,
            'business_website' => $this->business_website,
            'street_address' => $this->street_address,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'business_information' => $this->business_information,
            'engage_location_id' => $this->engage_location_id,
            'status' => $this->status,
        ];
    }
}
