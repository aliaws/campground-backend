<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomFieldRequest;
use App\Http\Requests\StoreEngageSettingRequest;
use App\Http\Resources\CountryResource;
use App\Http\Resources\CustomFieldResource;
use App\Http\Resources\EngageSettingResource;
use App\Http\Resources\GhlSyncLogResource;
use App\Models\Country;
use App\Models\CustomField;
use App\Models\EngageSetting;
use App\Models\EngageToken;
use App\Models\GhlSyncLog;
use App\Models\User;
use App\Services\EngageTokenCache;
use App\Services\GhlAuthService;
use App\Services\GhlFullSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function __construct(
        private GhlAuthService $ghlAuthService,
        private EngageTokenCache $engageTokenCache,
        private GhlFullSyncService $ghlFullSyncService,
    ) {}

    public function getEngage(Request $request): JsonResponse
    {
        $setting = $this->resolveEngageSetting($request->user());

        if (! $setting) {
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
        $locationId = $request->user()->resolveOrganizationLocationId();
        $existing = $this->resolveEngageSetting($request->user());
        $oauthStateKey = $existing?->oauth_state_key ?? (string) Str::ulid();

        $setting = EngageSetting::updateOrCreate(
            [EngageSetting::OAUTH_STATE_COLUMN => $oauthStateKey],
            $request->validated() + ['oauth_state_key' => $oauthStateKey]
        );

        EngageToken::updateOrCreate(
            ['engage_setting_id' => $setting->id],
            [
                'token_type' => EngageToken::TYPE_LOCATION,
                'engage_organization_location_id' => $locationId,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => new EngageSettingResource($setting->load('token')),
            'message' => 'Engage settings saved.',
        ]);
    }

    public function getAuthorizeUrl(Request $request): JsonResponse
    {
        $setting = $this->resolveEngageSetting($request->user());

        if (! $setting || ! $setting->client_id || ! $setting->client_secret) {
            return response()->json([
                'success' => false,
                'message' => 'Please save your Client ID and Client Secret first.',
            ], 422);
        }

        $redirectUri = $request->input('redirect_uri', config('app.url').'/api/v1/settings/engage/callback');

        $authorizeUrl = $this->ghlAuthService->getAuthorizationUrl($setting, $redirectUri);

        return response()->json([
            'success' => true,
            'data' => [
                'authorize_url' => $authorizeUrl,
                'redirect_uri' => $redirectUri,
                'scopes' => $this->ghlAuthService->getScopes($setting),
            ],
            'message' => 'Authorization URL generated.',
        ]);
    }

    public function handleCallback(Request $request): mixed
    {
        Log::info('Lead Connector OAuth callback received', [
            'all_params' => $request->all(),
            'query' => $request->query(),
        ]);

        $code = $request->input('code');

        if (! $code) {
            Log::error('Lead Connector OAuth callback missing params', [
                'code' => $code,
                'url' => $request->fullUrl(),
            ]);
            return $this->callbackRedirect('error=missing_params');
        }

        $setting = EngageSetting::with('token')->first();

        if (! $setting) {
            return $this->callbackRedirect('error=settings_not_found');
        }

        try {
            $redirectUri = config('app.url').'/api/v1/settings/engage/callback';
            $this->ghlAuthService->exchangeCodeForTokens($setting, $code, $redirectUri);

            return $this->callbackRedirect('success=true');
        } catch (\Exception $e) {
            return $this->callbackRedirect('error='.urlencode($e->getMessage()));
        }
    }

    public function refreshToken(Request $request): JsonResponse
    {
        $setting = $this->resolveEngageSetting($request->user());

        $token = $setting?->token;

        if (! $setting || ! $token?->refresh_token) {
            return response()->json([
                'success' => false,
                'message' => 'No refresh token available. Please authorize first.',
            ], 422);
        }

        try {
            $token = $this->ghlAuthService->refreshAccessToken($setting);
            $payload = $this->engageTokenCache->buildPayload($token);

            return response()->json([
                'success' => true,
                'data' => $payload,
                'message' => 'Token refreshed successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed: '.$e->getMessage(),
            ], 422);
        }
    }

    public function getTokens(Request $request): JsonResponse
    {
        $token = EngageToken::query()
            ->where('engage_organization_location_id', $request->user()->resolveOrganizationLocationId())
            ->first();

        $payload = $this->engageTokenCache->rememberForLocation(
            $token?->location_id,
            $token,
        );

        if (! $payload) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No engage tokens found.',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $payload,
            'message' => 'Token info retrieved.',
        ]);
    }

    public function saveTokens(Request $request): JsonResponse
    {
        $request->validate([
            'authorization_code' => ['nullable', 'string'],
            'access_token' => ['nullable', 'string'],
            'refresh_token' => ['nullable', 'string'],
            'location_id' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'string', 'max:255'],
        ]);

        $locationId = $request->user()->resolveOrganizationLocationId();
        $setting = $this->resolveEngageSetting($request->user());

        if (! $setting) {
            return response()->json([
                'success' => false,
                'message' => 'Please save engage settings first.',
            ], 422);
        }

        $existing = EngageToken::query()->where('engage_setting_id', $setting->id)->first();
        $previousLocationId = $existing?->location_id;

        $updateData = [
            'engage_setting_id' => $setting->id,
            'token_type' => $request->input('token_type', EngageToken::TYPE_LOCATION),
            'engage_organization_location_id' => $locationId,
        ];
        foreach (['authorization_code', 'access_token', 'refresh_token', 'location_id', 'user_id', 'company_id'] as $field) {
            if ($request->has($field)) {
                $updateData[$field] = $request->input($field);
            }
        }

        $token = EngageToken::updateOrCreate(
            ['engage_setting_id' => $setting->id],
            $updateData
        );

        $payload = $this->engageTokenCache->refresh($token->fresh(), $previousLocationId);

        return response()->json([
            'success' => true,
            'data' => $payload,
            'message' => 'Tokens saved.',
        ]);
    }

    private function callbackRedirect(string $query): RedirectResponse
    {
        $frontendUrl = config('app.frontend_url');

        return redirect("{$frontendUrl}/settings/engage/tokens?{$query}");
    }

    public function getCountries(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => CountryResource::collection(
                Country::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ),
            'message' => 'Countries retrieved.',
        ]);
    }

    public function getCustomFields(Request $request): JsonResponse
    {
        $fields = CustomField::where('engage_organization_location_id', $request->user()->resolveOrganizationLocationId())
            ->when($request->entity_type, fn ($q, $v) => $q->where('entity_type', $v))
            ->get();

        return response()->json([
            'success' => true,
            'data' => CustomFieldResource::collection($fields),
            'message' => 'Custom fields retrieved.',
        ]);
    }

    public function storeCustomField(StoreCustomFieldRequest $request): JsonResponse
    {
        $field = CustomField::create(
            $request->validated() + ['engage_organization_location_id' => $request->user()->resolveOrganizationLocationId()]
        );

        return response()->json([
            'success' => true,
            'data' => new CustomFieldResource($field),
            'message' => 'Custom field created.',
        ], 201);
    }

    private function resolveEngageSetting(User $user): ?EngageSetting
    {
        $locationId = $user->resolveOrganizationLocationId();

        $token = EngageToken::with('setting.token')
            ->where('engage_organization_location_id', $locationId)
            ->first();

        if ($token?->setting) {
            return $token->setting->loadMissing('token');
        }

        return EngageSetting::with('token')->first();
    }

    /**
     * Runs synchronously (click → wait → see results), matching every other
     * pull-ghl button in this app. A full pull (contacts + categories +
     * products + services/rentals + all paid invoices) can take a couple of
     * minutes on a large account — raising the time limit here only affects
     * PHP's own limit; a reverse proxy with a shorter timeout in front of
     * this server could still show the browser an error while the backend
     * keeps running to completion regardless.
     */
    public function pullAllGhlData(Request $request): JsonResponse
    {
        set_time_limit(300);

        $locationId = $request->user()->resolveOrganizationLocationId();

        try {
            $log = $this->ghlFullSyncService->pullAll($locationId, 'manual');

            return response()->json([
                'success' => $log->status !== 'failed',
                'data' => new GhlSyncLogResource($log),
                'message' => match ($log->status) {
                    'success' => 'Lead Connector data pulled successfully.',
                    'partial' => 'Lead Connector data pulled with some errors — see details.',
                    default => 'Lead Connector data pull failed.',
                },
            ], $log->status === 'failed' ? 422 : 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lead Connector data pull failed: '.$e->getMessage(),
            ], 422);
        }
    }

    public function getLatestSyncLog(Request $request): JsonResponse
    {
        $log = GhlSyncLog::where('engage_organization_location_id', $request->user()->resolveOrganizationLocationId())
            ->latest('started_at')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $log ? new GhlSyncLogResource($log) : null,
            'message' => $log ? 'Latest sync log retrieved.' : 'No sync has run yet.',
        ]);
    }
}
