<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the public "Schedule Demo" CMS page (Site Pages > Schedule Demo) and
 * a "Schedule Demo" link at the end of the site header menu. Done here
 * rather than in CmsPageSeeder so it exists without re-running the seeder,
 * which would reset every other page's content. An existing page row, or a
 * header that already links to /schedule-demo, is left untouched.
 */
return new class extends Migration
{
    private const SLUG = 'schedule-demo';

    private const HREF = '/schedule-demo';

    public function up(): void
    {
        if (! DB::table('cms_pages')->where('slug', self::SLUG)->exists()) {
            DB::table('cms_pages')->insert([
                'id' => (string) Str::ulid(),
                'slug' => self::SLUG,
                'title' => 'Schedule Demo',
                'content' => json_encode([
                    'heading' => 'Schedule a Demo',
                    'subheading' => 'See how it works before you book. Watch a walkthrough or ask us for a live demo.',
                    'body' => '<p>Tell us a little about what you need and we will set up a time to walk you through it.</p>',
                    'show_body' => true,
                    'embed' => ['enabled' => true, 'code' => null, 'title' => null, 'height' => 700],
                    'videos_heading' => 'Watch a walkthrough',
                    'videos' => [],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->editHeaderMenu(function (array $items) {
            foreach ($items as $item) {
                if (($item['href'] ?? null) === self::HREF) {
                    return $items;
                }
            }
            $items[] = [
                'id' => 'schedule-demo',
                'label' => 'Schedule Demo',
                'href' => self::HREF,
                'sort_order' => collect($items)->max('sort_order') + 1,
            ];

            return $items;
        });
    }

    public function down(): void
    {
        DB::table('cms_pages')->where('slug', self::SLUG)->delete();
        Cache::forget('cms:page:'.self::SLUG);

        $this->editHeaderMenu(fn (array $items) => array_values(
            array_filter($items, fn ($item) => ($item['href'] ?? null) !== self::HREF)
        ));
    }

    private function editHeaderMenu(callable $edit): void
    {
        $header = DB::table('cms_pages')->where('slug', 'header')->first();
        if (! $header) {
            return;
        }

        $content = json_decode($header->content, true) ?: [];
        $content['menu_items'] = $edit($content['menu_items'] ?? []);

        DB::table('cms_pages')->where('slug', 'header')->update(['content' => json_encode($content)]);
        // The public site reads the header from cache.
        Cache::forget('cms:page:header');
    }
};
