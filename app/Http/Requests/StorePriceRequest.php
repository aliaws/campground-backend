<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['one_time', 'recurring'])],
            'amount' => ['required', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],
            'variant_option_ids' => ['nullable', 'array'],
            'variant_option_ids.*' => ['string'],
            'track_inventory' => ['nullable', 'boolean'],
            'available_quantity' => ['nullable', 'integer', 'min:0'],
            'recurring_interval' => ['nullable', Rule::in(['day', 'week', 'month', 'year'])],
            'recurring_interval_count' => ['nullable', 'integer', 'min:1'],
            'sku' => ['nullable', 'string', 'max:255'],
        ];
    }
}
