<?php

namespace App\Integrations\GHL;

use App\Models\EngageSetting;
use App\Services\GhlAuthService;
use Illuminate\Support\Facades\Http;

class GhlClient
{
    public const BOOKING_API_VERSION = '2021-04-15';

    public const SERVICES_BASE_URL = 'https://services.leadconnectorhq.com/';

    public const BACKEND_BASE_URL = 'https://backend.leadconnectorhq.com/';

    private ?string $baseUrl = null;

    private ?string $accessToken = null;

    private ?EngageSetting $setting = null;

    public function __construct(?string $tenantId = null)
    {
        $this->setting = EngageSetting::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->first();

        if ($this->setting) {
            $this->baseUrl = $this->setting->api_base_url ?: self::SERVICES_BASE_URL;
            $this->accessToken = $this->setting->access_token;
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
        return $this->setting?->location_id;
    }

    public function getTimezone(): string
    {
        return $this->setting?->timezone ?: 'America/New_York';
    }

    public function getSetting(): ?EngageSetting
    {
        return $this->setting;
    }

    private function request(
        string $method,
        string $endpoint,
        array $data = [],
        array $query = [],
        ?string $version = null,
        ?string $baseUrl = null,
    ): array {
        if (! $this->accessToken) {
            throw new \RuntimeException('GHL access token not configured. Please authorize via OAuth.');
        }

        if ($this->setting?->isTokenExpired() && $this->setting->refresh_token) {
            $this->refreshToken();
        }

        $headers = [
            'Authorization' => "Bearer {$this->accessToken}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $apiVersion = $version ?? $this->setting?->api_version;
        if ($apiVersion) {
            $headers['Version'] = $apiVersion;
        }

        $url = rtrim($baseUrl ?? $this->baseUrl, '/').'/'.ltrim($endpoint, '/');
        if (! empty($query)) {
            $url .= '?'.http_build_query($query);
        }

        $http = Http::withHeaders($headers);
        $response = match ($method) {
            'get' => $http->get($url, $data),
            'delete' => $http->delete($url),
            default => $http->{$method}($url, $data),
        };

        if ($response->status() === 401 && $this->setting?->refresh_token) {
            $this->refreshToken();
            $headers['Authorization'] = "Bearer {$this->accessToken}";
            $http = Http::withHeaders($headers);
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
        if (! $this->accessToken) {
            throw new \RuntimeException('GHL access token not configured. Please authorize via OAuth.');
        }

        if ($this->setting?->isTokenExpired() && $this->setting->refresh_token) {
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
            ->attach('file', $fileContents, $filename, ['Content-Type' => $mimeType])
            ->post("{$this->baseUrl}medias/upload-file?locationId={$locationId}", $formFields);

        if ($response->status() === 401 && $this->setting?->refresh_token) {
            $this->refreshToken();
            $headers['Authorization'] = "Bearer {$this->accessToken}";
            $response = Http::withHeaders($headers)
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
        try {
            $authService = app(GhlAuthService::class);
            $this->setting = $authService->refreshAccessToken($this->setting);
            $this->accessToken = $this->setting->access_token;
        } catch (\Exception $e) {
            throw new \RuntimeException('GHL token refresh failed: '.$e->getMessage());
        }
    }
}
