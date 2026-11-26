<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HighLevel Marketplace clean-up, step A3: the white-label homepage hero
 * (spec "Hero Section — Option 2: Pure White-Label Demo").
 *
 * - Badge ("CAMPGROUND SOFTWARE DEMO" on live) -> "Whispering Pines Campground".
 * - Heading and subtext (the "free 90-day pilot" / "we can match your brand"
 *   pitch) -> the spec's reservation copy.
 * - Search bar button -> "Check Availability".
 * - Removes custom page sections that embed a YouTube video or carry the
 *   pilot / agency pitch (live: the Accurate Digital Solutions video).
 * - A hero background video, if one is set, is switched back to the image.
 *
 * down() puts back the hero text that was live on 2026-11-26. Removed custom
 * sections and a cleared hero video are not restored.
 */
return new class extends Migration
{
    private const SLUG = 'home-page';

    private const NEW_TEXT = [
        'badge' => 'Whispering Pines Campground',
        'primary' => 'Reserve Your Next Stay',
        'secondary' => 'Under the Pines',
        'subtext' => 'Explore RV sites, tent camping, and cozy cabins with real-time availability. Reserve your spot online in minutes with instant confirmation.',
        'button' => 'Check Availability',
    ];

    private const OLD_TEXT = [
        'badge' => 'Campground software demo',
        'primary' => 'Run your campground',
        'secondary' => 'from one screen',
        'subtext' => "You're looking at a live Camp Manager demo. Online booking, front desk, payments, and reports work together in one system, and we can match your brand or connect to your existing site. Try the guest booking below, then book a walkthrough and start your free 90-day pilot.",
        'button' => 'Find Campsites',
    ];

    /** Text that marks a custom section as off-platform promotion. */
    private const PROMO_MARKERS = [
        'youtube.com', 'youtu.be', 'youtube-nocookie.com',
        'accuratedigital', '90-day', '90 day', 'pilot',
    ];

    public function up(): void
    {
        $this->editHome(function (array $content) {
            $content = $this->setText($content, self::NEW_TEXT);

            $hero = $content['hero'] ?? [];
            if (($hero['media_type'] ?? 'background') === 'video') {
                $hero['media_type'] = 'background';
            }
            $hero['video_code'] = null;
            $content['hero'] = $hero;

            $content['page_sections'] = array_values(array_filter(
                $content['page_sections'] ?? [],
                fn ($section) => ! $this->isPromoSection($section)
            ));

            return $content;
        });
    }

    public function down(): void
    {
        $this->editHome(fn (array $content) => $this->setText($content, self::OLD_TEXT));
    }

    private function setText(array $content, array $text): array
    {
        $content['hero']['badge']['text'] = $text['badge'];
        $content['hero']['show_badge'] = true;
        $content['hero']['heading']['primary_text'] = $text['primary'];
        $content['hero']['heading']['secondary_text'] = $text['secondary'];
        $content['hero']['subtext'] = $text['subtext'];
        $content['search_filters']['search_button_text'] = $text['button'];

        return $content;
    }

    private function isPromoSection(mixed $section): bool
    {
        if (! is_array($section) || ($section['type'] ?? null) !== 'custom') {
            return false;
        }
        $text = strtolower(($section['title'] ?? '').' '.($section['html'] ?? ''));
        foreach (self::PROMO_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function editHome(callable $edit): void
    {
        $page = DB::table('cms_pages')->where('slug', self::SLUG)->first();
        if (! $page) {
            return;
        }

        $content = json_decode($page->content, true) ?: [];
        DB::table('cms_pages')->where('slug', self::SLUG)->update([
            'content' => json_encode($edit($content)),
            'updated_at' => now(),
        ]);
        // The public site reads CMS pages from cache.
        Cache::forget('cms:page:'.self::SLUG);
    }
};
