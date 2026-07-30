<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locationIds = $this->relationLoaded('locationLinks')
            ? $this->locationLinks->pluck('engage_organization_location_id')->filter()->values()->all()
            : $this->locationLinks()->pluck('engage_organization_location_id')->all();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->primaryRole(),
            'roles' => $this->roleList(),
            'engage_organization_location_id' => $this->primaryLocationId(),
            'engage_organization_location_ids' => $locationIds,
            'customer_id' => $this->customer_id,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'permissions' => $this->when($request->user()?->id === $this->id, [
                'can_manage_staff' => $this->canManageStaffUsers(),
                'assignable_roles' => $this->assignableStaffRoles(),
            ]),
            'phone' => $this->when(
                $this->relationLoaded('customer'),
                fn () => $this->customer?->phone
            ),
            'address' => $this->when(
                $this->relationLoaded('customer'),
                fn () => $this->customer?->address
            ),
            'created_at' => $this->created_at,
        ];
    }
}
