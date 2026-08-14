<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEngageSettingRequest;
use App\Http\Resources\EngageOrganizationLocationResource;
use App\Http\Resources\EngageSettingResource;
use App\Models\EngageOrganizationLocation;
use App\Models\EngageSetting;
use App\Models\EngageToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    /** Read-only, cross-org — the summary list on the Engage Identifiers page (no refresh/data-sync actions live here). */
    public function engageIdentifiers(): JsonResponse
    {
        $organizations = EngageOrganizationLocation::query()
            ->with('engageTokens')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => EngageOrganizationLocationResource::collection($organizations),
        ]);
    }

    /**
     * Client ID/Client Secret/API Version/Base URL/Timezone editing
     * (2026-08-14) — moved here from owner/admin's /settings/engage.
     * Owner/admin never see or set these at all now; they keep their own
     * per-org Refresh Token / Data Sync actions (which just use whatever
     * super-admin already configured for their organization here).
     * Mirrors SettingsController::getEngage()/storeEngage() exactly, only
     * scoped by the explicit {organization} route id instead of the
     * caller's own resolved location (super-admin has none).
     */
    public function showEngageSettings(EngageOrganizationLocation $organization): JsonResponse
    {
        $setting = $this->resolveEngageSettingFor($organization->id);

        return response()->json([
            'success' => true,
            'data' => $setting ? new EngageSettingResource($setting) : null,
        ]);
    }

    public function updateEngageSettings(StoreEngageSettingRequest $request, EngageOrganizationLocation $organization): JsonResponse
    {
        $existing = $this->resolveEngageSettingFor($organization->id);
        $oauthStateKey = $existing?->oauth_state_key ?? (string) Str::ulid();

        $setting = EngageSetting::updateOrCreate(
            [EngageSetting::OAUTH_STATE_COLUMN => $oauthStateKey],
            $request->validated() + ['oauth_state_key' => $oauthStateKey]
        );

        EngageToken::updateOrCreate(
            ['engage_setting_id' => $setting->id],
            [
                'token_type' => EngageToken::TYPE_LOCATION,
                'engage_organization_location_id' => $organization->id,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => new EngageSettingResource($setting->load('token')),
            'message' => 'Engage settings saved.',
        ]);
    }

    private function resolveEngageSettingFor(string $organizationId): ?EngageSetting
    {
        $token = EngageToken::with('setting.token')
            ->where('engage_organization_location_id', $organizationId)
            ->first();

        return $token?->setting?->loadMissing('token');
    }
}
