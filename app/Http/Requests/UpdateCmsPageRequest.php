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
                'content.background_color' => ['nullable', 'string', 'max:20'],
            ];
        }

        if ($slug === CmsPage::SLUG_FAQ) {
            return $rules + [
                'content' => ['required', 'array'],
                'content.items' => ['present', 'array'],
                'content.items.*.id' => ['required', 'string', 'max:64'],
                'content.items.*.question' => ['required', 'string', 'max:500'],
                'content.items.*.answer' => ['required', 'string', 'max:5000'],
                'content.items.*.sort_order' => ['required', 'integer'],
                'content.background_color' => ['nullable', 'string', 'max:20'],
            ];
        }

        if ($slug === CmsPage::SLUG_HEADER) {
            return $rules + $this->siteTitleRules() + $this->styleRules() + [
                'content' => ['required', 'array'],
                'content.menu_items' => ['present', 'array'],
                'content.menu_items.*.id' => ['required', 'string', 'max:64'],
                'content.menu_items.*.label' => ['required', 'string', 'max:100'],
                'content.menu_items.*.href' => ['required', 'string', 'max:500'],
                'content.menu_items.*.sort_order' => ['required', 'integer'],
                'content.layout' => ['required', 'array'],
                'content.layout.logo_position' => ['required', 'string', 'in:left,right'],
                'content.layout.theme_toggle_position' => ['required', 'string', 'in:left,right'],
                'content.layout.login_order' => ['required', 'array', 'size:2'],
                'content.layout.login_order.*' => ['required', 'string', 'in:customer,staff'],
            ];
        }

        if ($slug === CmsPage::SLUG_FOOTER) {
            return $rules + $this->siteTitleRules() + $this->styleRules() + [
                'content' => ['required', 'array'],
                'content.description' => ['nullable', 'string', 'max:1000'],
                'content.sections' => ['required', 'array'],
                'content.sections.explore.title' => ['required', 'string', 'max:100'],
                'content.sections.explore.items' => ['present', 'array'],
                'content.sections.explore.items.*.id' => ['required', 'string', 'max:64'],
                'content.sections.explore.items.*.label' => ['required', 'string', 'max:100'],
                'content.sections.explore.items.*.href' => ['required', 'string', 'max:500'],
                'content.sections.explore.items.*.sort_order' => ['required', 'integer'],
                'content.sections.legal.title' => ['required', 'string', 'max:100'],
                'content.sections.legal.items' => ['present', 'array'],
                'content.sections.legal.items.*.id' => ['required', 'string', 'max:64'],
                'content.sections.legal.items.*.label' => ['required', 'string', 'max:100'],
                'content.sections.legal.items.*.href' => ['required', 'string', 'max:500'],
                'content.sections.legal.items.*.sort_order' => ['required', 'integer'],
                'content.contact_section_title' => ['required', 'string', 'max:100'],
                'content.contact_fields_order' => ['required', 'array', 'size:3'],
                'content.contact_fields_order.*' => ['required', 'string', 'in:address,phone,email'],
                'content.copyright_text' => ['required', 'string', 'max:500'],
                // Order of the four footer columns themselves (brand/logo
                // block, Explore, Legal, Get in Touch) — independent of
                // contact_fields_order above, which only reorders the
                // fields *inside* the Get in Touch column.
                'content.column_order' => ['required', 'array', 'size:4'],
                'content.column_order.*' => ['required', 'string', 'in:brand,explore,legal,contact'],
            ];
        }

        return $rules + [
            'content' => ['required', 'array'],
            'content.body' => ['required', 'string', 'max:50000'],
            'content.background_color' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** Shared by header and footer — both have a logo + two-tone site title. */
    private function siteTitleRules(): array
    {
        return [
            'content.site_title' => ['required', 'array'],
            'content.site_title.primary_text' => ['required', 'string', 'max:100'],
            'content.site_title.secondary_text' => ['nullable', 'string', 'max:100'],
            'content.site_title.primary_color' => ['required', 'string', 'max:20'],
            'content.site_title.secondary_color' => ['required', 'string', 'max:20'],
            // Optional dark-mode overrides — fall back to the light-mode
            // colors above when unset (see CmsSiteTitle's frontend doc).
            'content.site_title.primary_color_dark' => ['nullable', 'string', 'max:20'],
            'content.site_title.secondary_color_dark' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** Shared by header and footer — background type/color/gradient/hover, image set separately via uploadImage(). */
    private function styleRules(): array
    {
        return [
            'content.style' => ['required', 'array'],
            'content.style.background_type' => ['required', 'string', 'in:default,solid,gradient,image'],
            'content.style.background_color' => ['nullable', 'string', 'max:20'],
            'content.style.gradient_from' => ['nullable', 'string', 'max:20'],
            'content.style.gradient_to' => ['nullable', 'string', 'max:20'],
            'content.style.gradient_direction' => ['nullable', 'string', 'max:20'],
            'content.style.hover_color' => ['nullable', 'string', 'max:20'],
        ];
    }
}
