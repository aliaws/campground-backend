<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A campground's settings plus the business-profile values the Settings page
 * and receipts reuse (name, contact, address, timezone). The profile values
 * are read-only here; they're edited through /organization/profile.
 */
class OrganizationSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $organization = $this->organizationLocation;

        return [
            'brand' => [
                'name' => $organization?->name,
                'subtitle' => $this->brand_subtitle,
                'logo_light_url' => $this->logo_light_url,
                'logo_dark_url' => $this->logo_dark_url,
                'favicon_url' => $this->favicon_url,
            ],
            'appearance' => [
                'accent_color' => $this->accent_color,
                'default_theme' => $this->default_theme,
                'hide_empty_categories' => (bool) $this->hide_empty_categories,
            ],
            'receipts' => [
                'header_note' => $this->receipt_header_note,
                'footer_note' => $this->receipt_footer_note,
                'tax_label' => $this->tax_label,
                'tax_rate' => $this->tax_rate !== null ? (float) $this->tax_rate : null,
                'currency' => $this->currency,
            ],
            'support' => [
                'url' => $this->support_url,
                'email' => $this->support_email,
                'show_footer_attribution' => (bool) $this->show_footer_attribution,
            ],
            'business' => [
                'email' => $organization?->business_email,
                'phone' => $organization?->business_phone,
                'street_address' => $organization?->street_address,
                'city' => $organization?->city,
                'state' => $organization?->state,
                'postal_code' => $organization?->postal_code,
                'country' => $organization?->country,
                'timezone' => $organization?->timezone,
            ],
            'saved' => $this->exists,
            'updated_at' => $this->updated_at,
        ];
    }
}
