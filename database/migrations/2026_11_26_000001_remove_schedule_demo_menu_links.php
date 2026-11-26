<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HighLevel Marketplace clean-up, step A1: remove every "Schedule Demo" link
 * (href /schedule-demo) from the site header menu and from the footer's link
 * sections. The header is shared by the customer site and the staff /login
 * page, so this removes the link from both. The Schedule Demo page itself is
 * left in place (hidden in a later step).
 *
 * Done as a migration so each server is updated on deploy without re-running
 * CmsPageSeeder, which would reset every page's content.
 */
return new class extends Migration
{
    private const HREF = '/schedule-demo';

    public function up(): void
    {
        $this->editPage('header', function (array $content) {
            $content['menu_items'] = $this->withoutScheduleDemo($content['menu_items'] ?? []);

            return $content;
        });

        $this->editPage('footer', function (array $content) {
            foreach ($content['sections'] ?? [] as $key => $section) {
                if (is_array($section) && isset($section['items']) && is_array($section['items'])) {
                    $content['sections'][$key]['items'] = $this->withoutScheduleDemo($section['items']);
                }
            }

            return $content;
        });
    }

    /**
     * Puts the header link back (as 2026_10_12_000001 added it). Footer links
     * removed by up() are not restored: their position isn't recorded.
     */
    public function down(): void
    {
        $this->editPage('header', function (array $content) {
            $items = $content['menu_items'] ?? [];
            foreach ($items as $item) {
                if (($item['href'] ?? null) === self::HREF) {
                    return $content;
                }
            }
            $items[] = [
                'id' => 'schedule-demo',
                'label' => 'Schedule Demo',
                'href' => self::HREF,
                'sort_order' => collect($items)->max('sort_order') + 1,
            ];
            $content['menu_items'] = $items;

            return $content;
        });
    }

    private function withoutScheduleDemo(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn ($item) => rtrim((string) ($item['href'] ?? ''), '/') !== self::HREF
        ));
    }

    private function editPage(string $slug, callable $edit): void
    {
        $page = DB::table('cms_pages')->where('slug', $slug)->first();
        if (! $page) {
            return;
        }

        $content = json_decode($page->content, true) ?: [];
        $updated = $edit($content);
        if ($updated === $content) {
            return;
        }

        DB::table('cms_pages')->where('slug', $slug)->update([
            'content' => json_encode($updated),
            'updated_at' => now(),
        ]);
        // The public site reads CMS pages from cache.
        Cache::forget('cms:page:'.$slug);
    }
};
