<?php

namespace App\Services;

use App\Models\EngageSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GhlAuthService
{
    private const AUTHORIZE_URL = 'https://marketplace.leadconnectorhq.com/oauth/chooselocation';

    private const TOKEN_URL = 'https://services.leadconnectorhq.com/oauth/token';

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
        'calendars/resources.readonly'
    ];

    public function getAuthorizationUrl(EngageSetting $setting, string $redirectUri): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $setting->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', self::DEFAULT_SCOPES),
            'state' => $setting->tenant_id,
        ]);

        return self::AUTHORIZE_URL . '?' . $params;
    }

    public function exchangeCodeForTokens(EngageSetting $setting, string $code, string $redirectUri): EngageSetting
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
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
            throw new \RuntimeException('Failed to exchange authorization code: ' . $response->body());
        }

        $data = $response->json();

        $setting->update([
            'authorization_code' => $code,
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'token_expiry' => now()->addSeconds($data['expires_in'] ?? 86400),
            'location_id' => $data['locationId'] ?? $setting->location_id,
            'user_id' => $data['userId'] ?? $setting->user_id,
            'company_id' => $data['companyId'] ?? $setting->company_id,
        ]);

        return $setting->fresh();
    }

    public function refreshAccessToken(EngageSetting $setting): EngageSetting
    {
        if (!$setting->refresh_token) {
            throw new \RuntimeException('No refresh token available. Please re-authorize.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $setting->client_id,
            'client_secret' => $setting->client_secret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $setting->refresh_token,
        ]);

        if ($response->failed()) {
            Log::error('GHL token refresh failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to refresh token: ' . $response->body());
        }

        $data = $response->json();

        $setting->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'token_expiry' => now()->addSeconds($data['expires_in'] ?? 86400),
        ]);

        return $setting->fresh();
    }

    public function getScopes(): array
    {
        return self::DEFAULT_SCOPES;
    }
}
