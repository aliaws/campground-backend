<?php

namespace App\Services;

use App\Models\EngageSetting;
use App\Models\EngageToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GhlAuthService
{
    private const AUTHORIZE_URL = 'https://marketplace.leadconnectorhq.com/oauth/chooselocation';

    private const TOKEN_URL = 'https://services.leadconnectorhq.com/oauth/token';

    /** Mirrors GhlClient's timeouts — a hung OAuth token call previously had no ceiling. */
    private const CONNECT_TIMEOUT = 10;

    private const REQUEST_TIMEOUT = 30;

    private const DEFAULT_SCOPES = [
        'contacts.readonly',
        'contacts.write',
        'products.readonly',
        'products.write',
        'products/prices.readonly',
        'products/prices.write',
        'products/collection.readonly',
        'products/collection.write',
        'invoices.readonly',
        'invoices.write',
        'invoices/schedule.readonly',
        'invoices/schedule.write',
        'calendars.readonly',
        'calendars.write',
        'calendars/events.readonly',
        'calendars/events.write',
        'calendars/resources.readonly',
        // Added 2026-08-11 for GET calendars/service-categories (Service
        // Categories feature) — that endpoint isn't in GHL's own published
        // scope docs (confirmed by reading the live Scopes reference
        // directly), but a real live call with every scope above already
        // granted still returned 401 "not authorized for this scope", so
        // something is missing. `calendars/groups.readonly` is the closest
        // documented analog (GHL's own "Calendar Groups" concept — the
        // closest existing publicly-documented parallel to a rental
        // account's "service categories") and is the best-informed
        // candidate, not a confirmed fix — it only takes effect once the
        // GHL connection is re-authorized (Engage Settings -> Authorize),
        // since a token refresh alone cannot add scopes to an existing
        // grant. Re-test after re-authorizing; if it's still 401, the real
        // scope name differs and needs to come from GHL support/docs.
        'calendars/groups.readonly',
        // 2026-08-12 correction: a `calendars/groups.write` scope was
        // briefly added here on the theory that POST/PUT/DELETE
        // calendars/service-categories 401ing was a missing-write-scope
        // problem, mirroring the readonly entry above. Real live testing
        // (create -> pull -> delete round trip, using this connection's
        // *existing*, never-re-authorized token) proved that wrong: every
        // write call succeeds today with zero scope changes — the actual
        // cause of the original 401s was a missing `industryType=rental`
        // query param on the write calls themselves (GET already sent it;
        // POST/PUT/DELETE didn't). See
        // GhlServiceSyncService::syncServiceCategoryToGhl()'s own doc
        // comment for the full, now-confirmed request shape. Removed rather
        // than left in place, since it was never actually needed and
        // requesting an unnecessary scope only widens what a future
        // re-authorization asks the user to grant.
    ];

    public function __construct(
        private EngageTokenCache $engageTokenCache,
    ) {}

    /**
     * @param  string  $organizationLocationId  Encoded as OAuth `state` so the callback
     *                                          (which only receives the shared, global
     *                                          EngageSetting's credentials back) knows
     *                                          which organization's EngageToken to
     *                                          attach the resulting grant to.
     */
    public function getAuthorizationUrl(EngageSetting $setting, string $organizationLocationId, string $redirectUri): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $setting->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $this->getScopes($setting)),
            'state' => $organizationLocationId,
        ]);

        return self::AUTHORIZE_URL.'?'.$params;
    }

    public function exchangeCodeForTokens(EngageSetting $setting, string $organizationLocationId, string $code, string $redirectUri): EngageToken
    {
        $response = Http::asForm()->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::REQUEST_TIMEOUT)->post(self::TOKEN_URL, [
            'client_id' => $setting->client_id,
            'client_secret' => $setting->client_secret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if ($response->failed()) {
            Log::error('GHL token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to exchange authorization code: '.$response->body());
        }

        $data = $response->json();
        $token = $this->resolveTokenForOrganization($setting, $organizationLocationId);
        $previousLocationId = $token->location_id;

        $token->fill([
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

    /**
     * Sibling of exchangeCodeForTokens() for the self-service organization
     * registration flow (SettingsController::handleCallback()'s state-less
     * branch) — the marketplace "install to location" URL a visitor is
     * redirected to isn't generated by getAuthorizationUrl() above, so
     * there's no `state` param on the way back to identify which
     * organization this is for. Returns GHL's raw decoded token response
     * (which includes `locationId`/`userId`/`companyId`) so the caller can
     * look up (or create) the right EngageOrganizationLocation itself
     * before persisting an EngageToken — deliberately has NO EngageToken
     * side effects, unlike exchangeCodeForTokens().
     *
     * @return array<string, mixed>
     */
    public function exchangeCodeForTokensWithoutOrganization(EngageSetting $setting, string $code, string $redirectUri): array
    {
        $response = Http::asForm()->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::REQUEST_TIMEOUT)->post(self::TOKEN_URL, [
            'client_id' => $setting->client_id,
            'client_secret' => $setting->client_secret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if ($response->failed()) {
            Log::error('GHL token exchange (organization registration) failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to exchange authorization code: '.$response->body());
        }

        return $response->json();
    }

    /** $token is this organization's own EngageToken — passed explicitly, never re-resolved, since EngageSetting is now shared platform-wide and no longer identifies an organization on its own. */
    public function refreshAccessToken(EngageSetting $setting, EngageToken $token): EngageToken
    {
        if (! $token->refresh_token) {
            throw new \RuntimeException('No refresh token available. Please re-authorize.');
        }

        $previousLocationId = $token->location_id;

        $response = Http::asForm()->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::REQUEST_TIMEOUT)->post(self::TOKEN_URL, [
            'client_id' => $setting->client_id,
            'client_secret' => $setting->client_secret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $token->refresh_token,
        ]);

        if ($response->failed()) {
            Log::error('GHL token refresh failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to refresh token: '.$response->body());
        }

        $data = $response->json();

        $token->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $token->refresh_token,
            'token_expiry' => now()->addSeconds($data['expires_in'] ?? 86400),
            'location_id' => $data['locationId'] ?? $token->location_id,
            'user_id' => $data['userId'] ?? $token->user_id,
            'company_id' => $data['companyId'] ?? $token->company_id,
        ]);

        $fresh = $token->fresh();
        $this->engageTokenCache->refresh($fresh, $previousLocationId);

        return $fresh;
    }

    /**
     * @return list<string>
     */
    public function getScopes(?EngageSetting $setting = null): array
    {
        $scopes = $setting?->scopes;

        if (is_array($scopes) && $scopes !== []) {
            return array_values(array_filter($scopes, fn ($s) => is_string($s) && $s !== ''));
        }

        return self::DEFAULT_SCOPES;
    }

    /**
     * Find-or-create this organization's own token row. Deliberately keyed
     * by engage_organization_location_id, not engage_setting_id — the
     * setting is a shared, global row now (2026-08-14), so keying by it
     * would collapse every organization's OAuth grant onto the same token.
     */
    private function resolveTokenForOrganization(EngageSetting $setting, string $organizationLocationId): EngageToken
    {
        $token = EngageToken::query()->where('engage_organization_location_id', $organizationLocationId)->first();

        if ($token) {
            if ($token->engage_setting_id !== $setting->id) {
                $token->update(['engage_setting_id' => $setting->id]);
            }

            return $token;
        }

        return EngageToken::create([
            'engage_setting_id' => $setting->id,
            'token_type' => EngageToken::TYPE_LOCATION,
            'engage_organization_location_id' => $organizationLocationId,
        ]);
    }
}
