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

    /** The eight fixed slugs this table holds — not a general-purpose page builder. */
    public const SLUGS = [
        self::SLUG_TERMS_OF_SERVICE,
        self::SLUG_PRIVACY_POLICY,
        self::SLUG_SUPPORT,
        self::SLUG_ABOUT_US,
        self::SLUG_CONTACT_US,
        self::SLUG_HEADER,
        self::SLUG_FOOTER,
        self::SLUG_FAQ,
    ];

    /** header/footer have a logo_url field superadmin can upload an image into — see Superadmin\CmsPageController::uploadLogo(). */
    public const LOGO_SLUGS = [
        self::SLUG_HEADER,
        self::SLUG_FOOTER,
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
