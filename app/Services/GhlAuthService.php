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
    ];

    public function getAuthorizationUrl(EngageSetting $setting, string $redirectUri): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $setting->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $this->getScopes($setting)),
            'state' => $setting->tenant_id,
        ]);

        return self::AUTHORIZE_URL.'?'.$params;
    }

    public function exchangeCodeForTokens(EngageSetting $setting, string $code, string $redirectUri): EngageSetting
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
        $token = $this->resolveToken($setting);

        $token->fill([
            'authorization_code' => $code,
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'token_expiry' => now()->addSeconds($data['expires_in'] ?? 86400),
            'location_id' => $data['locationId'] ?? $token->location_id,
            'user_id' => $data['userId'] ?? $token->user_id,
            'company_id' => $data['companyId'] ?? $token->company_id,
        ])->save();

        return $setting->fresh(['token']);
    }

    public function refreshAccessToken(EngageSetting $setting): EngageSetting
    {

        $token = $this->resolveToken($setting);

        if (! $token->refresh_token) {
            throw new \RuntimeException('No refresh token available. Please re-authorize.');
        }

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
            'refresh_token' => $data['refresh_token'],
            'token_expiry' => now()->addSeconds($data['expires_in'] ?? 86400),
        ]);

        return $setting->fresh(['token']);
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

    private function resolveToken(EngageSetting $setting): EngageToken
    {
        $token = $setting->token ?? EngageToken::query()->where('tenant_id', $setting->tenant_id)->first();

        if ($token) {
            if (! $token->engage_setting_id) {
                $token->update(['engage_setting_id' => $setting->id]);
            }

            return $token;
        }

        return EngageToken::create([
            'tenant_id' => $setting->tenant_id,
            'engage_setting_id' => $setting->id,
        ]);
    }
}
