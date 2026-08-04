<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'image' => $this->image,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'is_rental' => $this->is_rental,
            'association_id' => $this->association_id,
            'engage_collection_id' => $this->engage_collection_id,
            'engage_sync_status' => $this->engage_sync_status,
            'engage_last_synced_at' => $this->engage_last_synced_at,
            'products_count' => $this->whenCounted('products'),
            'engage_organization_location_id' => $this->engage_organization_location_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
