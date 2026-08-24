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
     * freeform pages (terms/privacy/support/about) take a single body
     * string, contact-us/faq/header/footer/home-page/shop each take their
     * own structured shape instead. No slug accepts more than one shape;
     * StoreEngageSettingRequest already established the convention of one
     * FormRequest branching on context.
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

        if ($slug === CmsPage::SLUG_FAQ) {
            return $rules + [
                'content' => ['required', 'array'],
                'content.items' => ['present', 'array'],
                'content.items.*.id' => ['required', 'string', 'max:64'],
                'content.items.*.question' => ['required', 'string', 'max:500'],
                'content.items.*.answer' => ['required', 'string', 'max:5000'],
                'content.items.*.sort_order' => ['required', 'integer'],
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
                'content.layout.store_switcher_position' => ['required', 'string', 'in:left,right,after-login'],
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
                // The footer's own address/phone/email — independently
                // editable from the identically-shaped Contact Us fields,
                // not derived from them.
                'content.address' => ['nullable', 'string', 'max:500'],
                'content.phone' => ['nullable', 'string', 'max:100'],
                'content.email' => ['nullable', 'email', 'max:255'],
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

        if ($slug === CmsPage::SLUG_HOME_PAGE) {
            // Two named sections of one page, not just a hero on its own —
            // the hero banner AND the "Our Rentals" section right below it
            // on the homepage (app/(customer)/page.tsx) are both edited
            // together here. $this->siteTitleRules()/styleRules() are
            // plain dot-path builders, so nesting them under `hero.` needs
            // no changes to either helper.
            return $rules + $this->siteTitleRules('hero.heading') + $this->styleRules('hero.style') + [
                'content' => ['required', 'array'],
                'content.hero' => ['required', 'array'],
                'content.hero.badge' => ['required', 'array'],
                // Built-in icon-gallery key (lib/utils/mapIcons.ts on the
                // frontend) — free-text rather than an `in:` whitelist kept
                // in sync with that registry, same trust level already
                // given to icon keys elsewhere (Amenity/Feature/site-map
                // icon fields aren't whitelisted server-side either); an
                // unrecognized key just falls back to the default pin icon
                // at render time, it can't produce broken output.
                'content.hero.badge.icon' => ['required', 'string', 'max:64'],
                'content.hero.badge.text' => ['required', 'string', 'max:100'],
                'content.hero.subtext' => ['required', 'string', 'max:500'],
                // Optional overrides for the badge/subtext text color —
                // null means "keep the current theme-token look", same
                // fallback convention as the dark-mode color pairs above.
                'content.hero.text_color' => ['nullable', 'string', 'max:20'],
                'content.hero.text_color_dark' => ['nullable', 'string', 'max:20'],
                'content.rentals_section' => ['required', 'array'],
                'content.rentals_section.eyebrow_text' => ['required', 'string', 'max:100'],
                'content.rentals_section.heading' => ['required', 'string', 'max:200'],
                'content.rentals_section.show_site_map_link' => ['required', 'boolean'],
                'content.rentals_section.site_map_link_text' => ['required', 'string', 'max:100'],
            ];
        }

        if ($slug === CmsPage::SLUG_SHOP) {
            return $rules + [
                'content' => ['required', 'array'],
                'content.badge' => ['required', 'array'],
                'content.badge.icon' => ['required', 'string', 'max:64'],
                'content.badge.text' => ['required', 'string', 'max:100'],
                'content.heading' => ['required', 'string', 'max:200'],
                'content.subtext' => ['required', 'string', 'max:500'],
                'content.filters' => ['required', 'array'],
                'content.filters.show_search' => ['required', 'boolean'],
                'content.filters.show_categories' => ['required', 'boolean'],
                'content.filters.show_price' => ['required', 'boolean'],
                'content.filters.show_availability' => ['required', 'boolean'],
                'content.filters.show_sort' => ['required', 'boolean'],
                // Where the filters sidebar sits relative to the product
                // grid — left (current default) or right.
                'content.filters.position' => ['required', 'string', 'in:left,right'],
            ];
        }

        return $rules + [
            'content' => ['required', 'array'],
            'content.body' => ['required', 'string', 'max:50000'],
        ];
    }

    /**
     * Shared by header, footer (both a logo + two-tone site title) and
     * home-page's hero heading (a two-tone heading, no logo) — $path is a
     * plain dot-notation prefix (e.g. 'site_title' or 'hero.heading'), so
     * nesting this under another key needs no change to the method itself.
     */
    private function siteTitleRules(string $path = 'site_title'): array
    {
        return [
            "content.{$path}" => ['required', 'array'],
            "content.{$path}.primary_text" => ['required', 'string', 'max:100'],
            "content.{$path}.secondary_text" => ['nullable', 'string', 'max:100'],
            "content.{$path}.primary_color" => ['required', 'string', 'max:20'],
            "content.{$path}.secondary_color" => ['required', 'string', 'max:20'],
            // Optional dark-mode overrides — fall back to the light-mode
            // colors above when unset (see CmsSiteTitle's frontend doc).
            "content.{$path}.primary_color_dark" => ['nullable', 'string', 'max:20'],
            "content.{$path}.secondary_color_dark" => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * Shared by header, footer, and home-page's hero — background
     * type/color/gradient/hover, image set separately via uploadImage().
     * $path is a plain dot-notation prefix, same convention as
     * siteTitleRules() above (header/footer pass the default 'style',
     * home-page passes 'hero.style' since that background belongs to the
     * hero section specifically).
     */
    private function styleRules(string $path = 'style'): array
    {
        return [
            "content.{$path}" => ['required', 'array'],
            "content.{$path}.background_type" => ['required', 'string', 'in:default,solid,gradient,image'],
            "content.{$path}.background_color" => ['nullable', 'string', 'max:20'],
            "content.{$path}.gradient_from" => ['nullable', 'string', 'max:20'],
            "content.{$path}.gradient_to" => ['nullable', 'string', 'max:20'],
            "content.{$path}.gradient_direction" => ['nullable', 'string', 'max:20'],
            "content.{$path}.hover_color" => ['nullable', 'string', 'max:20'],
        ];
    }
}
