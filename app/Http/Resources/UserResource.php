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

        // id+name pairs for the "select organization" picker — only needed
        // when there's actually a choice to make, so this stays a light
        // query (organizationLocations() is a plain BelongsToMany, no heavy
        // eager-load required) rather than something every response pays for.
        $organizations = count($locationIds) > 1
            ? $this->organizationLocations()->get(['engage_organization_locations.id', 'engage_organization_locations.name'])
                ->map(fn ($loc) => ['id' => $loc->id, 'name' => $loc->name])
                ->values()->all()
            : [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->primaryRole(),
            'roles' => $this->roleList(),
            'engage_organization_location_id' => $this->activeOrPrimaryLocationId(),
            'engage_organization_location_ids' => $locationIds,
            'organizations' => $organizations,
            /** Only meaningful alongside `organizations` — false means the frontend should show the picker. */
            'organization_selected' => $this->hasSelectedActiveLocation(),
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
