<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomFieldRequest;
use App\Http\Resources\CountryResource;
use App\Http\Resources\CustomFieldResource;
use App\Http\Resources\GhlSyncLogResource;
use App\Models\Country;
use App\Models\CustomField;
use App\Models\EngageOrganizationLocation;
use App\Models\EngageSetting;
use App\Models\EngageToken;
use App\Models\GhlSyncLog;
use App\Models\User;
use App\Services\EngageTokenCache;
use App\Services\GhlAuthService;
use App\Services\GhlFullSyncService;
use App\Services\OrganizationRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    public function __construct(
        private GhlAuthService $ghlAuthService,
        private EngageTokenCache $engageTokenCache,
        private GhlFullSyncService $ghlFullSyncService,
        private OrganizationRegistrationService $organizationRegistrations,
    ) {}

    /**
     * Redirect URL (and default scopes) so an owner/staff member can
     * register the right callback URL with GHL, or just see what this
     * deployment sends, without needing engage.identifiers.view (which
     * would also expose the platform's Client ID/Secret — genuinely
     * platform-wide, super-admin-only data, see globalEngageSetting()).
     */
    public function getOauthInfo(): JsonResponse
    {
        $setting = $this->globalEngageSetting();
        $redirectUri = $setting?->redirect_uri ?: (config('app.url').'/api/v1/settings/engage/callback');

        return response()->json([
            'success' => true,
            'data' => [
                'redirect_uri' => $redirectUri,
                'scopes' => $this->ghlAuthService->getScopes($setting),
                'is_configured' => (bool) ($setting?->client_id && $setting?->client_secret),
            ],
        ]);
    }

    public function getAuthorizeUrl(Request $request): JsonResponse
    {
        $setting = $this->globalEngageSetting();

        if (! $setting || ! $setting->client_id || ! $setting->client_secret) {
            return response()->json([
                'success' => false,
                'message' => 'Engage identifiers have not been configured yet. Contact your platform administrator.',
            ], 422);
        }

        $locationId = $request->user()->resolveOrganizationLocationId();
        $redirectUri = $setting->redirect_uri ?: (config('app.url').'/api/v1/settings/engage/callback');

        $authorizeUrl = $this->ghlAuthService->getAuthorizationUrl($setting, $locationId, $redirectUri);

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
            Log::error('Lead Connector OAuth callback missing code', [
                'url' => $request->fullUrl(),
            ]);

            return $this->callbackRedirect('error=missing_params');
        }

        $setting = $this->globalEngageSetting();

        if (! $setting) {
            Log::error('Lead Connector OAuth callback: Engage identifiers not configured');

            return $this->callbackRedirect('error=settings_not_found');
        }

        $redirectUri = $setting->redirect_uri ?: (config('app.url').'/api/v1/settings/engage/callback');

        // The `state` param carries the organization's id (see
        // getAuthorizeUrl()) for the existing owner/admin/staff "connect
        // this org's own GHL location" flow. The public "Register for
        // Application" marketplace-install flow doesn't go through
        // getAuthorizeUrl() at all (it redirects straight to GHL's own
        // hosted install page), so GHL calls this same callback back with
        // only `code` — no `state` — for that flow. Both are handled here
        // since GHL only lets one redirect_uri be registered per app.
        $state = $request->input('state');

        if ($state) {
            try {
                $this->ghlAuthService->exchangeCodeForTokens($setting, $state, $code, $redirectUri);

                return $this->callbackRedirect('success=true');
            } catch (\Exception $e) {
                return $this->callbackRedirect('error='.urlencode($e->getMessage()));
            }
        }

        try {
            $data = $this->ghlAuthService->exchangeCodeForTokensWithoutOrganization($setting, $code, $redirectUri);
            $ghlLocationId = $data['locationId'] ?? null;

            if (! $ghlLocationId) {
                Log::error('Lead Connector OAuth callback (organization registration): no locationId in token response', [
                    'response' => $data,
                ]);

                return $this->registrationCallbackRedirect('error=no_location_id');
            }

            $organization = $this->organizationRegistrations->findOrCreateByGhlLocationId($ghlLocationId);
            $this->saveTokenForOrganization($setting, $organization, $code, $data);

            // findOrCreateByGhlLocationId() only ever creates a brand-new
            // row (status 'uninstalled') for a location GHL has never sent
            // us before — that's the one case that genuinely needs the
            // Complete Registration flow (business info -> email verify ->
            // owner password). Any organization that already exists here
            // (status 'active', or even 'blocked') isn't a new
            // registration — it's an existing org's GHL connection being
            // re-authorized (e.g. an already-logged-in admin clicking
            // Authorize from /admin/engages/tokens, which goes through the
            // marketplace-install URL and so, unlike getAuthorizeUrl(),
            // never carries `state`). Sending an already-set-up org through
            // the registration-complete redirect would either 404 or
            // re-trigger onboarding for an org that's done with it — send
            // it back to the real Engage Tokens page instead.
            if ($organization->status !== EngageOrganizationLocation::STATUS_UNINSTALLED) {
                return $this->callbackRedirect('success=true');
            }

            return $this->registrationCallbackRedirect(http_build_query([
                'organization' => $organization->id,
                'location_id' => $organization->engage_location_id,
            ]));
        } catch (\Exception $e) {
            return $this->registrationCallbackRedirect('error='.urlencode($e->getMessage()));
        }
    }

    /**
     * Mirrors what GhlAuthService::exchangeCodeForTokens()'s
     * resolveTokenForOrganization()+fill()->save() does internally, but for
     * an organization only just identified via the response body itself
     * (see handleCallback()'s state-less branch) rather than known upfront.
     */
    private function saveTokenForOrganization(EngageSetting $setting, EngageOrganizationLocation $organization, string $code, array $data): EngageToken
    {
        $token = EngageToken::query()
            ->where('engage_organization_location_id', $organization->id)
            ->first() ?? new EngageToken([
                'engage_organization_location_id' => $organization->id,
                'token_type' => EngageToken::TYPE_LOCATION,
            ]);

        $previousLocationId = $token->location_id;

        $token->fill([
            'engage_setting_id' => $setting->id,
            'authorization_code' => $code,
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'token_expiry' => now()->addSeconds($data['expires_in'] ?? 86400),
            'location_id' => $data['locationId'] ?? $token->location_id,
            'user_id' => $data['userId'] ?? $token->user_id,
            'company_id' => $data['companyId'] ?? $token->company_id,
        ])->save();

        $fresh = $token->fresh();
        $this->engageTokenCache->refresh($fresh, $previousLocationId);

        return $fresh;
    }

    public function refreshToken(Request $request): JsonResponse
    {
        $setting = $this->globalEngageSetting();
        $token = $this->resolveOwnToken($request->user());

        if (! $setting || ! $token?->refresh_token) {
            return response()->json([
                'success' => false,
                'message' => 'No refresh token available. Please authorize first.',
            ], 422);
        }

        try {
            $token = $this->ghlAuthService->refreshAccessToken($setting, $token);
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
        $setting = $this->globalEngageSetting();

        if (! $setting) {
            return response()->json([
                'success' => false,
                'message' => 'Engage identifiers have not been configured yet. Contact your platform administrator.',
            ], 422);
        }

        // Keyed by this organization's own location id, not engage_setting_id
        // — the setting is a shared, global row now (2026-08-14), so keying
        // by it would overwrite whichever other organization last saved
        // tokens here instead of creating this organization's own row.
        $existing = EngageToken::query()->where('engage_organization_location_id', $locationId)->first();
        $previousLocationId = $existing?->location_id;

        $updateData = [
            'engage_setting_id' => $setting->id,
            'token_type' => $request->input('token_type', EngageToken::TYPE_LOCATION),
        ];
        foreach (['authorization_code', 'access_token', 'refresh_token', 'location_id', 'user_id', 'company_id'] as $field) {
            if ($request->has($field)) {
                $updateData[$field] = $request->input($field);
            }
        }

        $token = EngageToken::updateOrCreate(
            ['engage_organization_location_id' => $locationId],
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
        // /settings/engage/tokens was the pre-RBAC-redesign route — that
        // whole app/settings/** tree was deleted outright when the real
        // Engage Tokens page moved to /admin/engages/tokens (see CLAUDE.md's
        // 2026-08-13 RBAC changelog entry), but this redirect target was
        // never updated to match, so a real, successful token
        // refresh/reconnect landed on a 404 every time.
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        return redirect("{$frontendUrl}/admin/engages/tokens?{$query}");
    }

    private function registrationCallbackRedirect(string $query): RedirectResponse
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        return redirect("{$frontendUrl}/register-application/complete?{$query}");
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

    /**
     * The Client ID/Secret/etc. credentials — genuinely global (2026-08-14),
     * the platform's own registered GHL marketplace app, not per-organization
     * data. Managed exclusively via Superadmin\EngageSettingsController.
     */
    private function globalEngageSetting(): ?EngageSetting
    {
        return EngageSetting::query()->first();
    }

    /** This organization's own OAuth grant (access/refresh token, GHL location id, etc.) — always per-org, unlike the setting above. */
    private function resolveOwnToken(User $user): ?EngageToken
    {
        return EngageToken::query()
            ->where('engage_organization_location_id', $user->resolveOrganizationLocationId())
            ->first();
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
