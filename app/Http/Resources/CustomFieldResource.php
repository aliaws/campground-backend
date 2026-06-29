<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'field_name' => $this->field_name,
            'field_type' => $this->field_type,
            'tenant_id' => $this->tenant_id,
            'created_at' => $this->created_at,
        ];
    }
}
