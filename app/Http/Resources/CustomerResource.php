<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'ghl_contact_id' => $this->ghl_contact_id,
            'ghl_sync_status' => $this->ghl_sync_status,
            'ghl_last_synced_at' => $this->ghl_last_synced_at,
            'user_role' => $this->customerAccount?->role,
            'user_status' => $this->customerAccount?->customer_status,
            // True when this customer is currently archived (soft-deleted —
            // see "Customer Archive" under Key Business Logic). Lets a UI
            // that resolved this customer via a withTrashed() relation
            // (e.g. Booking/RentalTransaction::customer()) show an
            // "Archived" badge instead of silently displaying a stale name
            // with no indication the customer no longer has an active
            // account.
            'is_archived' => $this->trashed(),
            'created_by' => $this->created_by,
            'tenant_id' => $this->tenant_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
