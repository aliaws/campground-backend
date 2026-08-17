<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteRegistrationRequest extends FormRequest
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
            'business_email' => ['required', 'email:rfc,filter', 'max:255'],
            // Combined dial-code + number (e.g. "+1 5551234567") — digits,
            // spaces, +, -, parens only.
            'business_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'business_country_code' => ['nullable', 'string', 'max:8', Rule::exists('countries', 'iso2')],
            'business_website' => ['nullable', 'string', 'max:255', 'url'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\s]{2,20}$/'],
            'country' => ['nullable', 'string', 'max:255', Rule::exists('countries', 'name')],
            // Tiptap HTML, not plain text — the textarea this replaced was
            // capped at 5000, this needs headroom for markup overhead.
            'business_information' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
