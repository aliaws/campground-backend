<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EngageSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $token = $this->relationLoaded('token') ? $this->token : $this->token()->first();

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'client_id' => $this->client_id,
            'api_version' => $this->api_version,
            'api_base_url' => $this->api_base_url,
            'timezone' => $this->timezone ?? 'America/New_York',
            'scopes' => $this->scopes ?? [],
            'client_secret' => $this->client_secret,
            'location_id' => $token?->location_id,
            'user_id' => $token?->user_id,
            'company_id' => $token?->company_id,
            'has_access_token' => ! empty($token?->access_token),
            'has_refresh_token' => ! empty($token?->refresh_token),
            'token_expiry' => $token?->token_expiry,
            'is_token_expired' => $token?->isTokenExpired() ?? true,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
