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
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'draft', 'archived'])],
            'available_in_store' => ['nullable', 'boolean'],
            'image' => ['nullable', 'string', 'max:2048'],
            'tax_inclusive' => ['nullable', 'boolean'],
            'is_taxes_enabled' => ['nullable', 'boolean'],
            'track_product_inventory' => ['nullable', 'boolean'],
            'slug' => ['nullable', 'string', 'max:255'],
            'sku' => [
                'nullable', 'string', 'max:32', 'regex:/^[A-Z0-9\-]+$/',
                Rule::unique('engage_products', 'sku')
                    ->where('engage_organization_location_id', $this->user()->resolveOrganizationLocationId())
                    ->ignore($this->route('product')),
            ],
            'price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['string', 'exists:engage_categories,id'],
            'amenity_ids' => ['nullable', 'array'],
            'amenity_ids.*' => ['string', 'exists:amenities,id'],
            'feature_ids' => ['nullable', 'array'],
            'feature_ids.*' => ['string', 'exists:features,id'],
            // Manage Service's Pricing section — display/reference values on
            // the base rental row, not the live GHL booking quote. Harmless
            // to accept generically (the goods form never sends these keys),
            // same convention as amenity_ids/feature_ids above.
            'listing_price' => ['nullable', 'numeric', 'min:0'],
            'service_duration_unit' => ['nullable', Rule::in(['hour', 'day', 'week', 'month'])],
            'security_deposit_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
