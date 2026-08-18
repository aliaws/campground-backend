<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateOrganizationProfileRequest;
use App\Http\Resources\EngageOrganizationLocationProfileResource;
use App\Models\EngageOrganizationLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service — an owner/admin viewing/editing their OWN organization's
 * business profile (Profile page's "Business Information" section). Not
 * the superadmin cross-org drill-down (Superadmin\OrganizationController/
 * OrganizationDataController), which is read-only and covers every
 * organization, not just the caller's own.
 */
class OrganizationProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $organization = $this->resolveOwnOrganization($request);

        return response()->json([
            'success' => true,
            'data' => new EngageOrganizationLocationProfileResource($organization),
        ]);
    }

    public function update(UpdateOrganizationProfileRequest $request): JsonResponse
    {
        $organization = $this->resolveOwnOrganization($request);
        $organization->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => new EngageOrganizationLocationProfileResource($organization->fresh()),
            'message' => 'Business profile updated.',
        ]);
    }

    private function resolveOwnOrganization(Request $request): EngageOrganizationLocation
    {
        $locationId = $request->user()->resolveOrganizationLocationId();

        return EngageOrganizationLocation::query()->findOrFail($locationId);
    }
}
