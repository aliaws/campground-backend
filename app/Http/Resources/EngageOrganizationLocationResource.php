<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Super-admin's cross-org "Engage Identifiers" view — deliberately exposes
 * only identifiers/status/token METADATA, never the actual access_token/
 * refresh_token/client_secret an owner/admin's Engage Tokens page can see.
 * Do not reuse whatever resource backs the owner/admin token page for this.
 */
class EngageOrganizationLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $token = $this->relationLoaded('engageTokens') ? $this->engageTokens->first() : $this->engageTokens()->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_business_name' => $this->legal_business_name,
            'engage_location_id' => $this->engage_location_id,
            'timezone' => $this->timezone,
            'is_default' => $this->is_default,
            'status' => $this->status,
            'blocked_at' => $this->blocked_at,
            'block_reason' => $this->block_reason,
            'blocked_by' => $this->whenLoaded('blockedByUser', fn () => $this->blockedByUser?->name),
            'users_count' => $this->when(isset($this->users_count), fn () => $this->users_count),
            'customers_count' => $this->when(isset($this->customers_count), fn () => $this->customers_count),
            'has_token' => (bool) $token,
            'token_type' => $token?->token_type,
            'token_expires_at' => $token?->token_expiry,
            'created_at' => $this->created_at,
        ];
    }
}
