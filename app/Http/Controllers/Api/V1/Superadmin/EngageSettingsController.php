<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEngageSettingRequest;
use App\Http\Resources\EngageSettingResource;
use App\Models\EngageSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Engage Identifiers (Client ID/Secret/API Version/Base URL/Redirect
 * URI/Timezone/Scopes) — genuinely global, platform-wide (2026-08-14): the
 * credentials for this platform's own registered GHL marketplace app, not
 * per-organization data. There is exactly one EngageSetting row; owner/admin
 * never see or edit it at all — each organization's own OAuth grant
 * (access/refresh token, GHL location id) still lives separately, per-org,
 * in engage_tokens (see SettingsController::getAuthorizeUrl()/refreshToken()).
 */
class EngageSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $setting = EngageSetting::query()->first();

        return response()->json([
            'success' => true,
            'data' => $setting ? new EngageSettingResource($setting) : null,
        ]);
    }

    public function update(StoreEngageSettingRequest $request): JsonResponse
    {
        $setting = EngageSetting::query()->first() ?? new EngageSetting;
        $setting->fill($request->validated());

        if (! $setting->exists) {
            $setting->oauth_state_key = (string) Str::ulid();
        }

        $setting->save();

        return response()->json([
            'success' => true,
            'data' => new EngageSettingResource($setting),
            'message' => 'Engage identifiers saved.',
        ]);
    }
}
