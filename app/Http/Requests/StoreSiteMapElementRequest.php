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
            'type' => ['required', 'string', Rule::in(['icon', 'rental'])],
            'product_rental_id' => ['required_if:type,rental', 'nullable', 'string', 'exists:product_rentals,id'],
            'icon_key' => ['nullable', 'string', 'max:50'],
            'icon_type_id' => ['nullable', 'string', 'exists:site_map_icon_types,id'],
            'shape' => ['sometimes', 'string', Rule::in(['circle', 'rectangle'])],
            'icon_style' => ['sometimes', 'string', Rule::in(['filled', 'line', 'color'])],
            'label' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'x' => ['required', 'numeric', 'min:0', 'max:100'],
            'y' => ['required', 'numeric', 'min:0', 'max:100'],
            'width' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'height' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'rotation' => ['sometimes', 'numeric', 'min:-360', 'max:360'],
            'color' => ['nullable', 'string', 'max:20'],
            'opacity' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'z_index' => ['sometimes', 'integer'],
            'is_visible' => ['sometimes', 'boolean'],
            'category' => ['nullable', 'string', 'max:100'],
        ];
    }
}
