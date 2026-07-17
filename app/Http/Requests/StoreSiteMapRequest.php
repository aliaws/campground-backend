<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('put') || $this->isMethod('patch') ? 'sometimes' : 'required', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'icon_theme' => ['sometimes', 'string', Rule::in(['default', 'retro', 'flat', 'cartoon'])],
        ];
    }
}
