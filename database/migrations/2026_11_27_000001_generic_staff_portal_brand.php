<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HighLevel Marketplace clean-up, step B4: the staff portal's top-left brand
 * (Site Pages > Staff Portal) read "Camp Manager", a vendor name. It becomes
 * the generic "Campground POS" until per-location branding exists (Part D).
 *
 * Only changes the two title words when they are still the original
 * "Camp" / "Manager", so a name the superadmin has set is kept. The logo,
 * colors and tagline are left as they are. down() reverses the same way.
 */
return new class extends Migration
{
    private const SLUG = 'portal-branding';

    public function up(): void
    {
        $this->swapTitle(['Camp', 'Manager'], ['Campground', 'POS']);
    }

    public function down(): void
    {
        $this->swapTitle(['Campground', 'POS'], ['Camp', 'Manager']);
    }

    private function swapTitle(array $from, array $to): void
    {
        $page = DB::table('cms_pages')->where('slug', self::SLUG)->first();
        if (! $page) {
            return;
        }

        $content = json_decode($page->content, true) ?: [];
        $title = $content['site_title'] ?? [];
        if (($title['primary_text'] ?? null) !== $from[0] || ($title['secondary_text'] ?? null) !== $from[1]) {
            return;
        }

        $content['site_title']['primary_text'] = $to[0];
        $content['site_title']['secondary_text'] = $to[1];

        DB::table('cms_pages')->where('slug', self::SLUG)->update([
            'content' => json_encode($content),
            'updated_at' => now(),
        ]);
        // The staff portal reads this page from the public CMS endpoint, which is cached.
        Cache::forget('cms:page:'.self::SLUG);
    }
};
