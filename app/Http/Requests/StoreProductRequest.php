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
            // Only PHYSICAL/DIGITAL are creatable here — SERVICE (rental) products
            // are created exclusively via the GHL services pull
            // (GhlServiceSyncService), never through this form/endpoint. This is
            // store-only: UpdateProductRequest still allows SERVICE, since editing
            // an existing rental's status/categories in the Services tab of
            // ProductsManager.tsx submits product_type: 'SERVICE' on every save.
            'product_type' => ['required', Rule::in(['DIGITAL', 'PHYSICAL'])],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['active', 'draft', 'archived'])],
            'available_in_store' => ['nullable', 'boolean'],
            'image' => ['nullable', 'string', 'max:2048'],
            'tax_inclusive' => ['nullable', 'boolean'],
            'is_taxes_enabled' => ['nullable', 'boolean'],
            'track_product_inventory' => ['nullable', 'boolean'],
            'slug' => ['nullable', 'string', 'max:255'],
            'sku' => [
                'nullable', 'string', 'max:32', 'regex:/^[A-Z0-9\-]+$/',
                Rule::unique('products', 'sku')->where('engage_organization_location_id', $this->user()->resolveOrganizationLocationId()),
            ],
            'price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['string', 'exists:categories,id'],
        ];
    }
}
