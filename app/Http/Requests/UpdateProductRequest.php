<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'product_type' => ['sometimes', Rule::in(['DIGITAL', 'PHYSICAL', 'SERVICE'])],
            'parent_product_id' => ['nullable', 'string', 'max:26', 'exists:products,id'],
            'variant_name' => ['nullable', 'string', 'max:255'],
            'ghl_service_id' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sku' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'draft', 'archived'])],
            'is_variable' => ['nullable', 'boolean'],
            'available_in_store' => ['nullable', 'boolean'],
            'image' => ['nullable', 'string', 'max:2048'],
            'thumbnail' => ['nullable', 'string', 'max:2048'],
            'medias' => ['nullable', 'array'],
            'display_priority' => ['nullable', 'integer', 'min:0'],
            'tax_inclusive' => ['nullable', 'boolean'],
            'is_taxes_enabled' => ['nullable', 'boolean'],
            'site_type' => ['nullable', Rule::in(['tent', 'rv', 'cabin', 'glamping', 'group'])],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'available_quantity' => ['nullable', 'integer', 'min:0'],
            'hookups' => ['nullable', 'array'],
            'hookups.*' => [Rule::in(['electric_30amp', 'electric_50amp', 'water', 'sewer', 'none'])],
            'map_position' => ['nullable', 'array'],
            'map_polygon' => ['nullable', 'array'],
            'pet_friendly' => ['nullable', 'boolean'],
            'ada_accessible' => ['nullable', 'boolean'],
            'campsite_status' => ['nullable', Rule::in(['available', 'occupied', 'maintenance', 'reserved'])],
            'booking_unit' => ['nullable', Rule::in(['day'])],
            'min_duration' => ['nullable', 'integer', 'min:1'],
            'max_duration' => ['nullable', 'integer', 'min:1', 'gte:min_duration'],
            'duration_unit' => ['nullable', Rule::in(['day'])],
            'booking_start_time' => ['nullable', 'date_format:H:i'],
            'booking_end_time' => ['nullable', 'date_format:H:i'],
            'max_quantity' => ['nullable', 'integer', 'min:1'],
            ...StoreProductRequest::pricingRuleRules(),
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['string', 'exists:categories,id'],
            'amenity_ids' => ['nullable', 'array'],
            'amenity_ids.*' => ['string', 'exists:amenities,id'],
            'feature_ids' => ['nullable', 'array'],
            'feature_ids.*' => ['string', 'exists:features,id'],
            // Variants
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['nullable', 'string'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:100'],
            'variants.*.position' => ['nullable', 'integer', 'min:0'],
            'variants.*.options' => ['nullable', 'array'],
            'variants.*.options.*.id' => ['nullable', 'string'],
            'variants.*.options.*.name' => ['required_with:variants.*.options', 'string', 'max:100'],
            'variants.*.options.*.position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
