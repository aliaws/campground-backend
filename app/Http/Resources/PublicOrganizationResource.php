<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public "Organization" filter option — deliberately minimal (id/name/count
 * only), unlike the much heavier superadmin Organization resource
 * (legal_business_name, status, blocked_at, etc.), which has no business
 * being in an unauthenticated public response.
 */
class PublicOrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'services_count' => $this->services_count ?? 0,
        ];
    }
}
