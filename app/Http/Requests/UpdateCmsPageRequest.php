<?php

namespace App\Http\Requests;

use App\Models\CmsPage;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCmsPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * content's shape depends on which fixed slug is being edited — the
     * four freeform pages take a single body string, contact-us takes
     * structured phone/email/address/text fields instead. No slug accepts
     * both; StoreEngageSettingRequest already established the convention
     * of one FormRequest branching on context.
     */
    public function rules(): array
    {
        $slug = $this->route('slug');

        $rules = [
            'title' => ['required', 'string', 'max:255'],
        ];

        if ($slug === CmsPage::SLUG_CONTACT_US) {
            return $rules + [
                'content' => ['required', 'array'],
                'content.phone' => ['nullable', 'string', 'max:100'],
                'content.email' => ['nullable', 'email', 'max:255'],
                'content.address' => ['nullable', 'string', 'max:500'],
                'content.text' => ['nullable', 'string', 'max:5000'],
            ];
        }

        return $rules + [
            'content' => ['required', 'array'],
            'content.body' => ['required', 'string', 'max:50000'],
        ];
    }
}
