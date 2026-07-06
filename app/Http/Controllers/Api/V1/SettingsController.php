<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEngageSettingRequest;
use App\Http\Requests\StoreCustomFieldRequest;
use App\Http\Resources\CountryResource;
use App\Http\Resources\CustomFieldResource;
use App\Http\Resources\EngageSettingResource;
use App\Models\Country;
use App\Models\CustomField;
use App\Models\EngageSetting;
use App\Services\GhlAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        private GhlAuthService $ghlAuthService,
    ) {}

    public function getEngage(Request $request): JsonResponse
    {
        $setting = EngageSetting::where('tenant_id', $request->user()->tenant_id)->first();

        if (!$setting) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Engage settings not configured.',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => new EngageSettingResource($setting),
            'message' => 'Engage settings retrieved.',
        ]);
    }

    public function storeEngage(StoreEngageSettingRequest $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $setting = EngageSetting::updateOrCreate(
            ['tenant_id' => $tenantId],
            $request->validated() + ['tenant_id' => $tenantId]
        );

        return response()->json([
            'success' => true,
            'data' => new EngageSettingResource($setting),
            'message' => 'Engage settings saved.',
        ]);
    }

    public function getAuthorizeUrl(Request $request): JsonResponse
    {
        $setting = EngageSetting::where('tenant_id', $request->user()->tenant_id)->first();

        if (!$setting || !$setting->client_id || !$setting->client_secret) {
            return response()->json([
                'success' => false,
                'message' => 'Please save your Client ID and Client Secret first.',
            ], 422);
        }

        $redirectUri = $request->input('redirect_uri', config('app.url') . '/api/v1/settings/engage/callback');

        $authorizeUrl = $this->ghlAuthService->getAuthorizationUrl($setting, $redirectUri);

        return response()->json([
            'success' => true,
            'data' => [
                'authorize_url' => $authorizeUrl,
                'redirect_uri' => $redirectUri,
                'scopes' => $this->ghlAuthService->getScopes(),
            ],
            'message' => 'Authorization URL generated.',
        ]);
    }

    public function handleCallback(Request $request): mixed
    {
        \Illuminate\Support\Facades\Log::info('GHL OAuth callback received', [
            'all_params' => $request->all(),
            'query' => $request->query(),
        ]);

        $code = $request->input('code');
        $tenantId = $request->input('state');

        if (!$code || !$tenantId) {
            \Illuminate\Support\Facades\Log::error('GHL OAuth callback missing params', [
                'code' => $code,
                'state' => $tenantId,
                'url' => $request->fullUrl(),
            ]);
            return $this->callbackRedirect('error=missing_params');
        }

        $setting = EngageSetting::where('tenant_id', $tenantId)->first();

        if (!$setting) {
            return $this->callbackRedirect('error=settings_not_found');
        }

        try {
            $redirectUri = config('app.url') . '/api/v1/settings/engage/callback';
            $this->ghlAuthService->exchangeCodeForTokens($setting, $code, $redirectUri);

            return $this->callbackRedirect('success=true');
        } catch (\Exception $e) {
            return $this->callbackRedirect('error=' . urlencode($e->getMessage()));
        }
    }

    public function refreshToken(Request $request): JsonResponse
    {
        $setting = EngageSetting::where('tenant_id', $request->user()->tenant_id)->first();

        if (!$setting || !$setting->refresh_token) {
            return response()->json([
                'success' => false,
                'message' => 'No refresh token available. Please authorize first.',
            ], 422);
        }

        try {
            $setting = $this->ghlAuthService->refreshAccessToken($setting);

            return response()->json([
                'success' => true,
                'data' => new EngageSettingResource($setting),
                'message' => 'Token refreshed successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function getTokens(Request $request): JsonResponse
    {
        $setting = EngageSetting::where('tenant_id', $request->user()->tenant_id)->first();

        if (!$setting) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No engage settings found.',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'has_access_token' => !empty($setting->access_token),
                'has_refresh_token' => !empty($setting->refresh_token),
                'authorization_code' => $setting->authorization_code,
                'access_token' => $setting->access_token,
                'refresh_token' => $setting->refresh_token,
                'token_expiry' => $setting->token_expiry,
                'is_token_expired' => $setting->isTokenExpired(),
                'location_id' => $setting->location_id,
                'user_id' => $setting->user_id,
            ],
            'message' => 'Token info retrieved.',
        ]);
    }

    public function saveTokens(Request $request): JsonResponse
    {
        $request->validate([
            'authorization_code' => ['nullable', 'string'],
            'access_token' => ['nullable', 'string'],
            'refresh_token' => ['nullable', 'string'],
        ]);

        $setting = EngageSetting::where('tenant_id', $request->user()->tenant_id)->first();

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Please save engage settings first.',
            ], 422);
        }

        $updateData = [];
        if ($request->has('authorization_code')) {
            $updateData['authorization_code'] = $request->input('authorization_code');
        }
        if ($request->has('access_token')) {
            $updateData['access_token'] = $request->input('access_token');
        }
        if ($request->has('refresh_token')) {
            $updateData['refresh_token'] = $request->input('refresh_token');
        }

        $setting->update($updateData);

        return response()->json([
            'success' => true,
            'data' => new EngageSettingResource($setting->fresh()),
            'message' => 'Tokens saved.',
        ]);
    }

    private function callbackRedirect(string $query): \Illuminate\Http\RedirectResponse
    {
        $frontendUrl = config('app.frontend_url');

        return redirect("{$frontendUrl}/settings/engage/tokens?{$query}");
    }

    public function getCountries(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => CountryResource::collection(Country::orderBy('name')->get()),
            'message' => 'Countries retrieved.',
        ]);
    }

    public function getCustomFields(Request $request): JsonResponse
    {
        $fields = CustomField::where('tenant_id', $request->user()->tenant_id)
            ->when($request->entity_type, fn($q, $v) => $q->where('entity_type', $v))
            ->get();

        return response()->json([
            'success' => true,
            'data' => CustomFieldResource::collection($fields),
            'message' => 'Custom fields retrieved.',
        ]);
    }

    public function storeCustomField(StoreCustomFieldRequest $request): JsonResponse
    {
        $field = CustomField::create($request->validated());

        return response()->json([
            'success' => true,
            'data' => new CustomFieldResource($field),
            'message' => 'Custom field created.',
        ], 201);
    }
}
