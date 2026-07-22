<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSiteMapElementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Every field is independently optional — the inspector panel PATCHes one property at a time (drag = x/y, resize = width/height, restyle = color/opacity, etc.). */
    public function rules(): array
    {
        return [
            'icon_key' => ['sometimes', 'nullable', 'string', 'max:50'],
            'icon_type_id' => ['sometimes', 'nullable', 'string', 'exists:site_map_icon_types,id'],
            'shape' => ['sometimes', 'string', Rule::in(['circle', 'rectangle'])],
            'icon_style' => ['sometimes', 'string', Rule::in(['filled', 'line', 'color'])],
            'font_size' => ['sometimes', 'nullable', 'integer', 'min:6', 'max:48'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'x' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'y' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'width' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'height' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'rotation' => ['sometimes', 'numeric', 'min:-360', 'max:360'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'opacity' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'z_index' => ['sometimes', 'integer'],
            'is_visible' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
