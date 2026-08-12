<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerArchiveResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'ghl_contact_id' => $this->ghl_contact_id,
            'ghl_sync_status' => $this->ghl_sync_status,
            'ghl_last_synced_at' => $this->ghl_last_synced_at,
            'created_by' => $this->created_by,
            'engage_organization_location_id' => $this->engage_organization_location_id,
            'original_created_at' => $this->original_created_at,
            'archived_at' => $this->archived_at,
            'archived_reason' => $this->archived_reason,
        ];
    }
}
