<?php

namespace App\Integrations\GHL;

use App\Models\EngageSetting;
use App\Models\EngageToken;
use App\Services\GhlAuthService;
use App\Services\GhlLocationContext;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class GhlClient
{
    public const BOOKING_API_VERSION = '2021-04-15';

    public const SERVICES_BASE_URL = 'https://services.leadconnectorhq.com/';

    public const BACKEND_BASE_URL = 'https://backend.leadconnectorhq.com/';

    /**
     * Connect/read timeouts (seconds) for outbound GHL calls. None of these
     * existed before — an unresponsive GHL endpoint could hang a web worker
     * indefinitely. GHL sync failures are already caught/logged and never
     * block the main operation everywhere this client is used, so turning a
     * hang into a timeout exception after a generous window is a pure
     * reliability/performance fix, not a behavior change for any request
     * that would have succeeded anyway.
     */
    private const CONNECT_TIMEOUT = 10;

    private const REQUEST_TIMEOUT = 30;

    private ?string $baseUrl = null;

    private ?string $accessToken = null;

    private ?EngageSetting $setting = null;

    private ?EngageToken $token = null;

    /**
     * The location id credentials were last resolved for — a real string,
     * null (deliberately resolved with no location context), or the
     * sentinel below meaning "never resolved yet." Needed because null is
     * itself a legitimate, distinct resolution outcome (falls back to
     * the historical ::first() behavior), so it can't double as "unresolved."
     */
    private string|null|false $resolvedForLocationId = false;

    /**
     * GhlClient is bound as a container *singleton* (AppServiceProvider) —
     * one instance lives for an entire request or console command, which is
     * exactly why credentials must never be resolved once, eagerly, in the
     * constructor: GhlDailySync/GhlFullSyncService::pullAll() process
     * *multiple* locations in a single process, and every previous version
     * of this class kept using whichever location's token it happened to
     * resolve first for the rest of that run — silently pulling/pushing
     * one organization's Lead Connector data using another organization's
     * connection. Credentials are now resolved lazily, on first real use,
     * keyed off GhlLocationContext's current value, and re-resolved
     * whenever that value changes (see ensureCredentials()).
     */
    public function __construct(private GhlLocationContext $locationContext) {}

    /**
     * Resolves (or re-resolves) which organization's Lead Connector
     * connection this client talks as, called at the top of every method
     * that actually reads credentials or fires a request.
     *
     * Resolution order: (1) GhlLocationContext's explicit value, set by
     * GhlFullSyncService::pullAll() for each location it processes in turn;
     * (2) the currently authenticated staff user's own organization, for
     * the (vastly more common) case of a normal HTTP request triggering a
     * sync/push action — covers every controller that never explicitly
     * sets the context; (3) falls back to the original ::first() behavior
     * only when neither is available (a console context with no location
     * option, or a single-organization deployment), preserving prior
     * behavior exactly for that case.
     */
    private function ensureCredentials(): void
    {
        $locationId = $this->locationContext->get() ?? $this->authenticatedUserLocationId();

        if ($this->resolvedForLocationId === $locationId) {
            return;
        }

        $token = $locationId
            ? EngageToken::with('setting')->where('engage_organization_location_id', $locationId)->first()
            : null;

        if ($token && $token->setting) {
            $this->token = $token;
            $this->setting = $token->setting;
        } else {
            $this->setting = EngageSetting::with('token')->first();
            $this->token = $this->setting?->token
                ?? ($this->setting ? EngageToken::query()->where('engage_setting_id', $this->setting->id)->first() : null);
        }

        $this->baseUrl = $this->setting?->api_base_url ?: self::SERVICES_BASE_URL;
        $this->accessToken = $this->token?->access_token;
        $this->resolvedForLocationId = $locationId;
    }

    private function authenticatedUserLocationId(): ?string
    {
        try {
            $user = Auth::guard('api')->user();

            return $user?->resolveOrganizationLocationId();
        } catch (\Throwable) {
            return null;
        }
    }

    public function post(string $endpoint, array $data, array $query = [], ?string $version = null): array
    {
        return $this->request('post', $endpoint, $data, $query, $version);
    }

    public function postToBackend(string $endpoint, array $data, array $query = [], ?string $version = null): array
    {
        return $this->request('post', $endpoint, $data, $query, $version, self::BACKEND_BASE_URL);
    }

    public function put(string $endpoint, array $data, array $query = [], ?string $version = null): array
    {
        return $this->request('put', $endpoint, $data, $query, $version);
    }

    public function putToBackend(string $endpoint, array $data, array $query = [], ?string $version = null): array
    {
        return $this->request('put', $endpoint, $data, $query, $version, self::BACKEND_BASE_URL);
    }

    public function get(string $endpoint, array $query = [], ?string $version = null): array
    {
        return $this->request('get', $endpoint, $query, [], $version);
    }

    public function delete(string $endpoint, array $query = [], ?string $version = null): array
    {
        return $this->request('delete', $endpoint, [], $query, $version);
    }

    public function getLocationId(): ?string
    {
        $this->ensureCredentials();

        return $this->token?->location_id;
    }

    public function getUserId(): ?string
    {
        $this->ensureCredentials();

        return $this->token?->user_id;
    }

    public function getTimezone(): string
    {
        $this->ensureCredentials();

        return $this->setting?->timezone ?: 'America/New_York';
    }

    public function getSetting(): ?EngageSetting
    {
        $this->ensureCredentials();

        return $this->setting;
    }

    public function getToken(): ?EngageToken
    {
        $this->ensureCredentials();

        return $this->token;
    }

    /**
     * Fires multiple GET requests concurrently instead of one at a time —
     * same total number of GHL calls as calling get() in a loop, just
     * issued in parallel, so it doesn't add rate-limit risk.
     *
     * Http::pool() bypasses request()'s inline 401-detect-refresh-retry
     * logic, so this method (1) proactively refreshes the token before
     * building the pool if it's already known-expired, and (2) after the
     * pool resolves, retries any 401s once, sequentially, behind a single
     * refreshToken() call — never let concurrent 401s each trigger their
     * own refresh, since GHL's refresh token is one-time-use/rotating and
     * two racing refreshes would corrupt the location's stored token.
     *
     * @param  array<string, array{endpoint: string, query?: array}>  $requests  keyed by caller-chosen id
     * @return array<string, array|\Throwable> same keys as $requests; each value is the decoded
     *                                         JSON body, or a Throwable if that request ultimately failed
     */
    public function poolGet(array $requests, ?string $version = null): array
    {
        $this->ensureCredentials();

        if (! $this->accessToken) {
            throw new \RuntimeException('GHL access token not configured. Please authorize via OAuth.');
        }

        if (empty($requests)) {
            return [];
        }

        if ($this->token?->isTokenExpired() && $this->token->refresh_token) {
            $this->refreshToken();
        }

        $results = $this->firePool($requests, $version);

        $retryKeys = array_keys(array_filter(
            $results,
            fn ($result) => $result instanceof Response && $result->status() === 401
        ));

        if (! empty($retryKeys) && $this->token?->refresh_token) {
            $this->refreshToken();
            $retryResults = $this->firePool(array_intersect_key($requests, array_flip($retryKeys)), $version);
            $results = array_replace($results, $retryResults);
        }

        return array_map(function ($result) {
            if ($result instanceof Response) {
                return $result->failed()
                    ? new \RuntimeException("GHL API error: {$result->status()} - {$result->body()}")
                    : ($result->json() ?? []);
            }

            // Http::pool() returns a ConnectionException (or similar Throwable) in
            // place of a Response when a request fails at the transport level.
            return $result instanceof \Throwable ? $result : new \RuntimeException('GHL pooled request failed');
        }, $results);
    }

    /** @return array<string, Response|\Throwable> */
    private function firePool(array $requests, ?string $version): array
    {
        $headers = $this->buildHeaders($version);
        $baseUrl = rtrim($this->baseUrl, '/');

        return Http::pool(fn ($pool) => collect($requests)->map(
            fn (array $req, string $key) => $pool->as($key)
                ->withHeaders($headers)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->get($baseUrl.'/'.ltrim($req['endpoint'], '/'), $req['query'] ?? [])
        )->all());
    }

    private function buildHeaders(?string $version): array
    {
        $headers = [
            'Authorization' => "Bearer {$this->accessToken}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $apiVersion = $version ?? $this->setting?->api_version;
        if ($apiVersion) {
            $headers['Version'] = $apiVersion;
        }

        return $headers;
    }

    private function request(
        string $method,
        string $endpoint,
        array $data = [],
        array $query = [],
        ?string $version = null,
        ?string $baseUrl = null,
    ): array {
        $this->ensureCredentials();

        if (! $this->accessToken) {
            throw new \RuntimeException('GHL access token not configured. Please authorize via OAuth.');
        }

        if ($this->token?->isTokenExpired() && $this->token->refresh_token) {
            $this->refreshToken();
        }

        $headers = $this->buildHeaders($version);

        $url = rtrim($baseUrl ?? $this->baseUrl, '/').'/'.ltrim($endpoint, '/');
        if (! empty($query)) {
            $url .= '?'.http_build_query($query);
        }

        $http = Http::withHeaders($headers)->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::REQUEST_TIMEOUT);
        $response = match ($method) {
            'get' => $http->get($url, $data),
            'delete' => $http->delete($url),
            default => $http->{$method}($url, $data),
        };

        if ($response->status() === 401 && $this->token?->refresh_token) {
            $this->refreshToken();
            $http = Http::withHeaders($this->buildHeaders($version))->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::REQUEST_TIMEOUT);
            $response = match ($method) {
                'get' => $http->get($url, $data),
                'delete' => $http->delete($url),
                default => $http->{$method}($url, $data),
            };
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                "GHL API error: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Upload a file to GHL's media library.
     * Uses multipart/form-data — NOT JSON.
     */
    public function uploadFile(string $filePath, string $filename, string $mimeType = 'application/octet-stream'): array
    {
        $this->ensureCredentials();

        if (! $this->accessToken) {
            throw new \RuntimeException('GHL access token not configured. Please authorize via OAuth.');
        }

        if ($this->token?->isTokenExpired() && $this->token->refresh_token) {
            $this->refreshToken();
        }

        $locationId = $this->getLocationId();

        $headers = [
            'Authorization' => "Bearer {$this->accessToken}",
            'Version' => $this->setting?->api_version ?: '2021-07-28',
            'Accept' => 'application/json',
        ];

        $fileContents = file_get_contents($filePath);
        $formFields = [
            'hosted' => 'true',
            'locationId' => $locationId,
        ];

        $response = Http::withHeaders($headers)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->attach('file', $fileContents, $filename, ['Content-Type' => $mimeType])
            ->post("{$this->baseUrl}medias/upload-file?locationId={$locationId}", $formFields);

        if ($response->status() === 401 && $this->token?->refresh_token) {
            $this->refreshToken();
            $headers['Authorization'] = "Bearer {$this->accessToken}";
            $response = Http::withHeaders($headers)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->attach('file', $fileContents, $filename, ['Content-Type' => $mimeType])
                ->post("{$this->baseUrl}medias/upload-file?locationId={$locationId}", $formFields);
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                "GHL media upload error: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json() ?? [];
    }

    private function refreshToken(): void
    {
        if (! $this->setting || ! $this->token) {
            throw new \RuntimeException('GHL token refresh failed: no Engage settings/token resolved.');
        }

        try {
            $authService = app(GhlAuthService::class);
            $this->token = $authService->refreshAccessToken($this->setting, $this->token);
            $this->accessToken = $this->token->access_token;
        } catch (\Exception $e) {
            throw new \RuntimeException('GHL token refresh failed: '.$e->getMessage());
        }
    }
}
