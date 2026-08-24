<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locationId = $request->user()?->resolveOrganizationLocationId();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'ghl_contact_id' => $this->ghlContactIdFor($locationId),
            'ghl_sync_status' => $this->ghl_sync_status,
            'ghl_last_synced_at' => $this->ghl_last_synced_at,
            'user_role' => $this->customerAccount?->primaryRole(),
            'user_roles' => $this->customerAccount?->roleList(),
            'user_status' => $this->customerAccount?->status,
            // True when this customer is currently archived (soft-deleted —
            // see "Customer Archive" under Key Business Logic). Lets a UI
            // that resolved this customer via a withTrashed() relation
            // (e.g. Booking/EngageRentalTransaction::customer()) show an
            // "Archived" badge instead of silently displaying a stale name
            // with no indication the customer no longer has an active
            // account.
            'is_archived' => $this->trashed(),
            'created_by' => $this->created_by,
            'engage_organization_location_ids' => $this->when(
                $this->relationLoaded('locationLinks'),
                fn () => $this->locationLinks->pluck('engage_organization_location_id')->values()->all()
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
