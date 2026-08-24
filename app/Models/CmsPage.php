<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CmsPage extends Model
{
    use HasUlids;

    public const SLUG_TERMS_OF_SERVICE = 'terms-of-service';

    public const SLUG_PRIVACY_POLICY = 'privacy-policy';

    public const SLUG_SUPPORT = 'support';

    public const SLUG_ABOUT_US = 'about-us';

    public const SLUG_CONTACT_US = 'contact-us';

    public const SLUG_HEADER = 'header';

    public const SLUG_FOOTER = 'footer';

    public const SLUG_FAQ = 'faq';

    /** Homepage content — the hero banner AND the "Our Rentals" section below it, as two named sections of one page (content.hero / content.rentals_section). Not just the hero on its own. */
    public const SLUG_HOME_PAGE = 'home-page';

    public const SLUG_SHOP = 'shop';

    /** The ten fixed slugs this table holds — not a general-purpose page builder. */
    public const SLUGS = [
        self::SLUG_TERMS_OF_SERVICE,
        self::SLUG_PRIVACY_POLICY,
        self::SLUG_SUPPORT,
        self::SLUG_ABOUT_US,
        self::SLUG_CONTACT_US,
        self::SLUG_HEADER,
        self::SLUG_FOOTER,
        self::SLUG_FAQ,
        self::SLUG_HOME_PAGE,
        self::SLUG_SHOP,
    ];

    /** header/footer have a logo_url field superadmin can upload an image into — see Superadmin\CmsPageController::uploadLogo(). */
    public const LOGO_SLUGS = [
        self::SLUG_HEADER,
        self::SLUG_FOOTER,
    ];

    /**
     * Pages with a background_image_url field (inside a `style` object)
     * superadmin can upload an image into — a superset of LOGO_SLUGS:
     * header/footer have both a logo AND a background image, home-page's
     * hero section has a background image only (no logo field on its
     * content shape at all, so it's never valid to upload a `type=logo`
     * image for this slug — see Superadmin\CmsPageController::uploadImage()).
     * Where exactly `style` lives differs per slug — header/footer keep it
     * at `content.style`, home-page nests it one level deeper at
     * `content.hero.style` (it's the hero section's own background, not
     * the whole page's) — see CmsPageController::backgroundImagePath().
     */
    public const BACKGROUND_IMAGE_SLUGS = [
        self::SLUG_HEADER,
        self::SLUG_FOOTER,
        self::SLUG_HOME_PAGE,
    ];

    protected $fillable = [
        'slug',
        'title',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }

    /** Cache key for the public-facing payload of this page — see PublicCmsPageController/Superadmin\CmsPageController. */
    public static function cacheKey(string $slug): string
    {
        return "cms:page:{$slug}";
    }
}
