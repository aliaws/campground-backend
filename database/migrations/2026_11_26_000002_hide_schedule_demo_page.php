<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HighLevel Marketplace clean-up, step A2: switch the Schedule Demo page off
 * by hiding its three sections (Content, iframe, Videos). Their content is
 * kept, so the superadmin can switch them back on in Site Pages > Schedule
 * Demo. With every section hidden the public /schedule-demo page sends
 * visitors to the home page.
 *
 * down() switches all three back on. It does not remember which ones were
 * already off before up() ran.
 */
return new class extends Migration
{
    private const SLUG = 'schedule-demo';

    public function up(): void
    {
        $this->setSections(false);
    }

    public function down(): void
    {
        $this->setSections(true);
    }

    private function setSections(bool $shown): void
    {
        $page = DB::table('cms_pages')->where('slug', self::SLUG)->first();
        if (! $page) {
            return;
        }

        $content = json_decode($page->content, true) ?: [];
        $content['show_body'] = $shown;
        $content['show_videos'] = $shown;
        $content['embed'] = array_merge(
            ['code' => null, 'title' => null, 'height' => 700],
            is_array($content['embed'] ?? null) ? $content['embed'] : [],
            ['enabled' => $shown]
        );

        DB::table('cms_pages')->where('slug', self::SLUG)->update([
            'content' => json_encode($content),
            'updated_at' => now(),
        ]);
        // The public site reads CMS pages from cache.
        Cache::forget('cms:page:'.self::SLUG);
    }
};
