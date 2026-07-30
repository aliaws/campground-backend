<?php

namespace App\Services;

use App\Models\EngageToken;
use App\Support\Jwt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Builds + Redis-caches the GET /settings/engage/tokens payload.
 * Cache key is always the Engage location_id (super key); TTL matches token_expiry.
 */
class EngageTokenCache
{
    private const CACHE_PREFIX = 'engage:tokens:location:';

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(EngageToken $token): array
    {
        $decoded = $this->decodeToken($token->access_token) ?? [];
        $oauthMeta = is_array($decoded['oauthMeta'] ?? null) ? $decoded['oauthMeta'] : [];
        $scopes = $oauthMeta['scopes'] ?? [];

        return [
            'has_access_token' => ! empty($token->access_token),
            'has_refresh_token' => ! empty($token->refresh_token),
            'authorization_code' => $token->authorization_code,
            'access_token' => $token->access_token,
            'refresh_token' => $token->refresh_token,
            'token_expiry' => $token->token_expiry?->toIso8601String(),
            'is_token_expired' => $token->isTokenExpired(),
            'location_id' => $token->location_id,
            'user_id' => $token->user_id,
            'company_id' => $token->company_id,
            'scopes' => is_array($scopes) ? array_values($scopes) : [],
            'auth_class' => is_string($decoded['authClass'] ?? null) ? $decoded['authClass'] : null,
        ];
    }

    /**
     * Read from Redis by location_id when possible; otherwise build from DB and cache.
     *
     * @return array<string, mixed>|null
     */
    public function rememberForLocation(?string $locationId, ?EngageToken $token): ?array
    {
        if (is_string($locationId) && $locationId !== '') {
            try {
                $cached = Cache::get($this->cacheKey($locationId));
                if (is_array($cached)) {
                    return $cached;
                }
            } catch (\Throwable $e) {
                Log::warning('Engage token cache read failed', ['error' => $e->getMessage()]);
            }
        }

        if (! $token) {
            return null;
        }

        $payload = $this->buildPayload($token);
        $this->put($token, $payload);

        return $payload;
    }

    /** Store payload until access-token expiry; keyed by Engage location_id. */
    public function put(EngageToken $token, ?array $payload = null): void
    {
        $locationId = $token->location_id;
        if (! is_string($locationId) || $locationId === '') {
            return;
        }

        $payload ??= $this->buildPayload($token);
        $ttl = $this->ttlSeconds($token);
        if ($ttl < 1) {
            $this->forget($locationId);

            return;
        }

        try {
            Cache::put($this->cacheKey($locationId), $payload, $ttl);
        } catch (\Throwable $e) {
            Log::warning('Engage token cache write failed', [
                'location_id' => $locationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Drop cached payload for a location (call before/after issuing a new token). */
    public function forget(?string $locationId): void
    {
        if (! is_string($locationId) || $locationId === '') {
            return;
        }

        try {
            Cache::forget($this->cacheKey($locationId));
        } catch (\Throwable $e) {
            Log::warning('Engage token cache forget failed', [
                'location_id' => $locationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate prior location key (if any) then cache the fresh token payload.
     *
     * @return array<string, mixed>
     */
    public function refresh(EngageToken $token, ?string $previousLocationId = null): array
    {
        if ($previousLocationId && $previousLocationId !== $token->location_id) {
            $this->forget($previousLocationId);
        }

        $this->forget($token->location_id);
        $payload = $this->buildPayload($token);
        $this->put($token, $payload);

        return $payload;
    }

    private function cacheKey(string $locationId): string
    {
        return self::CACHE_PREFIX.$locationId;
    }

    private function ttlSeconds(EngageToken $token): int
    {
        if (! $token->token_expiry) {
            return 0;
        }

        return max(0, $token->token_expiry->getTimestamp() - time());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeToken(?string $jwt): ?array
    {
        if (! is_string($jwt) || $jwt === '' || ! str_contains($jwt, '.')) {
            return null;
        }

        return Jwt::peekPayload($jwt);
    }
}
