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
            'status' => ['required', Rule::in(['active', 'draft', 'archived'])],
            'available_in_store' => ['nullable', 'boolean'],
            'image' => ['nullable', 'string', 'max:2048'],
            'tax_inclusive' => ['nullable', 'boolean'],
            'is_taxes_enabled' => ['nullable', 'boolean'],
            'track_product_inventory' => ['nullable', 'boolean'],
            'slug' => ['nullable', 'string', 'max:255'],
            'sku' => [
                'nullable', 'string', 'max:32', 'regex:/^[A-Z0-9\-]+$/',
                Rule::unique('products', 'sku')->where('tenant_id', $this->user()->tenant_id),
            ],
            'price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['string', 'exists:categories,id'],
        ];
    }
}
