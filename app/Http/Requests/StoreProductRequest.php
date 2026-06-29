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
            'type' => ['required', Rule::in(['rental', 'physical', 'service', 'addon'])],
            'sub_type' => ['nullable', Rule::in(['campsite', 'equipment', 'merchandise', null])],
            'category_id' => ['nullable', 'string', 'max:26', 'exists:categories,id'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'location' => ['nullable', 'string', 'max:255'],
            'rental_duration_unit' => ['nullable', Rule::in(['night', 'hour', 'day'])],
            'min_rental_duration' => ['nullable', 'integer', 'min:1'],
            'max_rental_duration' => ['nullable', 'integer', 'min:1', 'gte:min_rental_duration'],
            'status' => ['required', Rule::in(['available', 'booked', 'maintenance', 'inactive'])],
            'image_url' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
