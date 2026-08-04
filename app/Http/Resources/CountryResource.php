<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'flag_emoji' => $this->flag_emoji,
            'iso2' => $this->iso2,
            'dial_code' => $this->dial_code,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
