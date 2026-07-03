<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEngageSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string'],
            'api_version' => ['required', 'string', 'max:50'],
            'api_base_url' => ['nullable', 'string', 'url', 'max:500'],
            'timezone' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string'],
        ];
    }
}
