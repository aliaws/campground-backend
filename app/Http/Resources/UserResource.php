<?php

namespace App\Http\Resources;

use App\Models\EngageOrganizationLocation;
use App\Support\PermissionMatrix;
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
        // Blocked organizations are excluded — a blocked org can't be
        // selected, so it shouldn't be offered as a choice.
        $organizations = count($locationIds) > 1
            ? $this->organizationLocations()->active()
                ->get(['engage_organization_locations.id', 'engage_organization_locations.name'])
                ->map(fn ($loc) => ['id' => $loc->id, 'name' => $loc->name])
                ->values()->all()
            : [];

        // Feeds the frontend footer (organization name + GHL location id) —
        // null for super-admin (org-less by design) or any user with no
        // resolved organization yet.
        $activeLocationId = $this->activeOrPrimaryLocationId();
        $organization = null;
        if (is_string($activeLocationId) && $activeLocationId !== '') {
            $org = EngageOrganizationLocation::query()
                ->find($activeLocationId, ['id', 'name', 'engage_location_id', 'status']);
            if ($org) {
                $organization = [
                    'id' => $org->id,
                    'name' => $org->name,
                    'engage_location_id' => $org->engage_location_id,
                    'status' => $org->status,
                ];
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'role' => $this->primaryRole(),
            'roles' => $this->roleList(),
            'engage_organization_location_id' => $activeLocationId,
            'engage_organization_location_ids' => $locationIds,
            'organization' => $organization,
            'organizations' => $organizations,
            /** Only meaningful alongside `organizations` — false means the frontend should show the picker. */
            'organization_selected' => $this->hasSelectedActiveLocation(),
            'customer_id' => $this->customer_id,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'permissions' => $this->when($request->user()?->id === $this->id, fn () => [
                'can_manage_staff' => $this->canManageStaffUsers(),
                'assignable_roles' => $this->assignableStaffRoles(),
                'allowed_actions' => PermissionMatrix::allowedFor($this->resource),
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
