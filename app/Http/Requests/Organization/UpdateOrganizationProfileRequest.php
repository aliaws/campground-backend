<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_business_name' => ['nullable', 'string', 'max:255'],
            'business_email' => ['nullable', 'email:rfc,filter', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'business_country_code' => ['nullable', 'string', 'max:8', 'exists:countries,iso2'],
            'business_website' => ['nullable', 'string', 'max:255', 'url'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\s]{2,20}$/'],
            'country' => ['nullable', 'string', 'max:255', 'exists:countries,name'],
            'business_information' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
