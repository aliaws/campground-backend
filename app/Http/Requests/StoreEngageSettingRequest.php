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
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string'],
            'api_version' => ['required', 'string', 'max:50'],
            'api_base_url' => ['nullable', 'string', 'url', 'max:500'],
            'redirect_uri' => ['nullable', 'string', 'url', 'max:500'],
            // Not validated with `url` — it's a template containing a literal
            // {location_id} placeholder (substituted client-side before the
            // user is redirected), not a directly-usable URL on its own.
            'location_installation_url_template' => ['nullable', 'string', 'max:1000'],
            'timezone' => ['nullable', 'string', 'max:100'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', 'max:255'],
        ];
    }
}
