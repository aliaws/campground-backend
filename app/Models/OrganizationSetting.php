<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One campground's own settings (Settings page). Created on first save; until
 * then forOrganization() returns an unsaved row holding the defaults.
 */
class OrganizationSetting extends Model
{
    use HasUlids;

    /** Image fields, keyed by the upload `type`. */
    public const IMAGE_FIELDS = [
        'logo_light' => 'logo_light_url',
        'logo_dark' => 'logo_dark_url',
        'favicon' => 'favicon_url',
    ];

    public const THEMES = ['light', 'dark'];

    protected $fillable = [
        'engage_organization_location_id',
        'brand_subtitle',
        'accent_color',
        'default_theme',
        'hide_empty_categories',
        'receipt_header_note',
        'receipt_footer_note',
        'tax_label',
        'tax_rate',
        'currency',
        'support_url',
        'support_email',
        'show_footer_attribution',
    ];

    protected $attributes = [
        'hide_empty_categories' => true,
        'currency' => 'USD',
        'show_footer_attribution' => true,
    ];

    protected function casts(): array
    {
        return [
            'hide_empty_categories' => 'boolean',
            'show_footer_attribution' => 'boolean',
            'tax_rate' => 'decimal:3',
        ];
    }

    public function organizationLocation(): BelongsTo
    {
        return $this->belongsTo(EngageOrganizationLocation::class, 'engage_organization_location_id');
    }

    /** The organization's saved row, or an unsaved one with the defaults. */
    public static function forOrganization(string $locationId): self
    {
        return static::query()->firstOrNew(['engage_organization_location_id' => $locationId]);
    }
}
