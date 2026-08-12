<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'ghl_category_id' => $this->ghl_category_id,
            'engage_sync_status' => $this->engage_sync_status,
            'engage_last_synced_at' => $this->engage_last_synced_at,
            'rentals_count' => $this->whenCounted('rentals'),
            'engage_organization_location_id' => $this->engage_organization_location_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
