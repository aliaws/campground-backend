<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteMapElementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['icon', 'rental', 'road', 'area'])],
            'product_rental_id' => ['required_if:type,rental', 'nullable', 'string', 'exists:product_rentals,id'],
            'icon_key' => ['nullable', 'string', 'max:50'],
            'shape' => ['sometimes', 'string', Rule::in(['circle', 'rectangle'])],
            'icon_style' => ['sometimes', 'string', Rule::in(['filled', 'line', 'color'])],
            'label' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'x' => ['required_unless:type,road,area', 'numeric', 'min:0', 'max:100'],
            'y' => ['required_unless:type,road,area', 'numeric', 'min:0', 'max:100'],
            'width' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'height' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'points' => ['required_if:type,road,area', 'array', 'min:2'],
            'points.*.x' => ['required_with:points', 'numeric', 'min:0', 'max:100'],
            'points.*.y' => ['required_with:points', 'numeric', 'min:0', 'max:100'],
            'rotation' => ['sometimes', 'numeric', 'min:-360', 'max:360'],
            'color' => ['nullable', 'string', 'max:20'],
            'stroke_width' => ['sometimes', 'numeric', 'min:0.1', 'max:20'],
            'opacity' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'z_index' => ['sometimes', 'integer'],
            'is_visible' => ['sometimes', 'boolean'],
            'category' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
