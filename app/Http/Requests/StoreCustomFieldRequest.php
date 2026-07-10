<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['required', Rule::in(['customer', 'booking', 'product'])],
            'field_name' => ['required', 'string', 'max:255'],
            'field_type' => ['required', 'string', 'max:255'],
            'tenant_id' => ['required', 'string', 'max:26'],
        ];
    }
}
