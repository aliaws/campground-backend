<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EngageSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $token = $this->token;

        return [
            'id' => $this->id,
            'oauth_state_key' => $this->oauth_state_key,
            'location_id' => $token?->location_id,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'api_version' => $this->api_version,
            'api_base_url' => $this->api_base_url,
            'redirect_uri' => $this->redirect_uri,
            'location_installation_url_template' => $this->location_installation_url_template,
            'timezone' => $this->timezone,
            'scopes' => $this->scopes ?? [],
            'user_id' => $token?->user_id,
            'company_id' => $token?->company_id,
            'has_client_secret' => ! empty($this->client_secret),
            'has_access_token' => ! empty($token?->access_token),
            'has_refresh_token' => ! empty($token?->refresh_token),
            'token_expiry' => $token?->token_expiry,
            'is_token_expired' => $token?->isTokenExpired() ?? true,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
