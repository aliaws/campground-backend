<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'product_type' => ['required', Rule::in(['DIGITAL', 'PHYSICAL', 'SERVICE'])],
            'description' => ['nullable', 'string'],
            'sku' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'draft', 'archived'])],
            'is_variable' => ['nullable', 'boolean'],
            'available_in_store' => ['nullable', 'boolean'],
            'image' => ['nullable', 'string', 'max:2048'],
            'thumbnail' => ['nullable', 'string', 'max:2048'],
            'medias' => ['nullable', 'array'],
            'display_priority' => ['nullable', 'integer', 'min:0'],
            'tax_inclusive' => ['nullable', 'boolean'],
            'is_taxes_enabled' => ['nullable', 'boolean'],
            // SERVICE-type (campsite) fields
            'site_type' => ['nullable', Rule::in(['tent', 'rv', 'cabin', 'glamping', 'group'])],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'available_quantity' => ['nullable', 'integer', 'min:0'],
            'hookups' => ['nullable', 'array'],
            'hookups.*' => [Rule::in(['electric_30amp', 'electric_50amp', 'water', 'sewer', 'none'])],
            'map_position' => ['nullable', 'array'],
            'map_position.lat' => ['required_with:map_position', 'numeric'],
            'map_position.lng' => ['required_with:map_position', 'numeric'],
            'map_polygon' => ['nullable', 'array'],
            'map_polygon.*.lat' => ['required', 'numeric'],
            'map_polygon.*.lng' => ['required', 'numeric'],
            'pet_friendly' => ['nullable', 'boolean'],
            'ada_accessible' => ['nullable', 'boolean'],
            'campsite_status' => ['nullable', Rule::in(['available', 'occupied', 'maintenance', 'reserved'])],
            // Relations
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['string', 'exists:categories,id'],
            'amenity_ids' => ['nullable', 'array'],
            'amenity_ids.*' => ['string', 'exists:amenities,id'],
            'feature_ids' => ['nullable', 'array'],
            'feature_ids.*' => ['string', 'exists:features,id'],
        ];
    }
}
