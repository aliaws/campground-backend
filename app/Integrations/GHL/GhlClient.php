<?php

namespace App\Integrations\GHL;

use App\Models\EngageSetting;
use App\Services\GhlAuthService;
use Illuminate\Support\Facades\Http;

class GhlClient
{
    private ?string $baseUrl = null;

    private ?string $accessToken = null;

    private ?EngageSetting $setting = null;

    public function __construct(?string $tenantId = null)
    {
        $this->setting = EngageSetting::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->first();

        if ($this->setting) {
            $this->baseUrl = $this->setting->api_base_url ?: 'https://services.leadconnectorhq.com/';
            $this->accessToken = $this->setting->access_token;

            if ($this->setting->isTokenExpired() && $this->setting->refresh_token) {
                $this->refreshToken();
            }
        }
    }

    public function post(string $endpoint, array $data): array
    {
        return $this->request('post', $endpoint, $data);
    }

    public function put(string $endpoint, array $data): array
    {
        return $this->request('put', $endpoint, $data);
    }

    public function get(string $endpoint, array $query = []): array
    {
        return $this->request('get', $endpoint, $query);
    }

    public function delete(string $endpoint): array
    {
        return $this->request('delete', $endpoint);
    }

    public function getLocationId(): ?string
    {
        return $this->setting?->location_id;
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->accessToken) {
            throw new \RuntimeException('GHL access token not configured. Please authorize via OAuth.');
        }

        $headers = [
            'Authorization' => "Bearer {$this->accessToken}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($this->setting?->api_version) {
            $headers['Version'] = $this->setting->api_version;
        }

        $response = Http::withHeaders($headers)->{$method}("{$this->baseUrl}{$endpoint}", $data);

        if ($response->status() === 401 && $this->setting?->refresh_token) {
            $this->refreshToken();
            $headers['Authorization'] = "Bearer {$this->accessToken}";
            $response = Http::withHeaders($headers)->{$method}("{$this->baseUrl}{$endpoint}", $data);
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                "GHL API error: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json();
    }

    private function refreshToken(): void
    {
        try {
            $authService = app(GhlAuthService::class);
            $this->setting = $authService->refreshAccessToken($this->setting);
            $this->accessToken = $this->setting->access_token;
        } catch (\Exception $e) {
            throw new \RuntimeException('GHL token refresh failed: ' . $e->getMessage());
        }
    }
}
