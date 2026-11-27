<?php

namespace App\Http\Requests\Organization;

use App\Models\OrganizationSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
    }

    public function rules(): array
    {
        return [
            'brand_subtitle' => ['sometimes', 'nullable', 'string', 'max:150'],
            'accent_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'default_theme' => ['sometimes', 'nullable', Rule::in(OrganizationSetting::THEMES)],
            'hide_empty_categories' => ['sometimes', 'boolean'],
            'receipt_header_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'receipt_footer_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'tax_label' => ['sometimes', 'nullable', 'string', 'max:50'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'support_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'show_footer_attribution' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'accent_color.regex' => 'The accent color must be a hex color such as #1d7a62.',
        ];
    }
}
