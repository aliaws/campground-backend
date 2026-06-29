<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EngageSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'location_id' => $this->location_id,
            'client_id' => $this->client_id,
            'api_version' => $this->api_version,
            'api_base_url' => $this->api_base_url,
            'user_id' => $this->user_id,
            'company_id' => $this->company_id,
            'client_secret' => $this->client_secret,
            'has_access_token' => !empty($this->access_token),
            'has_refresh_token' => !empty($this->refresh_token),
            'token_expiry' => $this->token_expiry,
            'is_token_expired' => $this->isTokenExpired(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
