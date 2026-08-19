<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EngageOrganizationLocationResource;
use App\Models\EngageOrganizationLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin's cross-org platform view — the org-less "sees every
 * organization" side of the RBAC redesign. Nothing here scopes by the
 * actor's own organization (they don't have one); every method operates
 * across the whole engage_organization_locations table or a single
 * explicitly-routed {organization}.
 */
class OrganizationController extends Controller
{
    public function index(): JsonResponse
    {
        $organizations = EngageOrganizationLocation::query()
            ->withCount(['users', 'customers'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => EngageOrganizationLocationResource::collection($organizations),
        ]);
    }

    public function show(EngageOrganizationLocation $organization): JsonResponse
    {
        $organization->loadCount(['users', 'customers'])->load('blockedByUser', 'engageTokens');

        return response()->json([
            'success' => true,
            'data' => new EngageOrganizationLocationResource($organization),
        ]);
    }

    public function block(Request $request, EngageOrganizationLocation $organization): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $organization->block($request->user(), $request->input('reason'));

        return response()->json([
            'success' => true,
            'data' => new EngageOrganizationLocationResource($organization),
            'message' => 'Organization blocked.',
        ]);
    }

    public function unblock(EngageOrganizationLocation $organization): JsonResponse
    {
        $organization->unblock();

        return response()->json([
            'success' => true,
            'data' => new EngageOrganizationLocationResource($organization),
            'message' => 'Organization unblocked.',
        ]);
    }
}
