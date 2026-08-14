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

    /** The five fixed slugs this table holds — not a general-purpose page builder. */
    public const SLUGS = [
        self::SLUG_TERMS_OF_SERVICE,
        self::SLUG_PRIVACY_POLICY,
        self::SLUG_SUPPORT,
        self::SLUG_ABOUT_US,
        self::SLUG_CONTACT_US,
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
}
