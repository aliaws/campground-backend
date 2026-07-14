<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'tenant_id' => $this->tenant_id,
            'customer_id' => $this->customer_id,
            'guest_status' => $this->guest_status,
            'phone' => $this->when(
                $this->relationLoaded('customer'),
                fn () => $this->customer?->phone
            ),
            'address' => $this->when(
                $this->relationLoaded('customer'),
                fn () => $this->customer?->address
            ),
            'created_at' => $this->created_at,
        ];
    }
}
