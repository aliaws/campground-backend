<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateCmsPageRequest;
use App\Models\CmsPage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Only the new page-section and hero-video rules; needs no database. The
 * rest of each page's content is deliberately left out of the data, so
 * these assert on the new keys' errors rather than on the whole result.
 */
class CmsPageSectionsValidationTest extends TestCase
{
    private function errorsFor(string $slug, array $content): array
    {
        $request = UpdateCmsPageRequest::create("/api/v1/superadmin/pages/{$slug}", 'PUT');
        $request->setRouteResolver(fn () => new class($slug)
        {
            public function __construct(private string $slug) {}

            public function parameter(string $name, mixed $default = null): mixed
            {
                return $name === 'slug' ? $this->slug : $default;
            }
        });

        return Validator::make(['title' => 'Page', 'content' => $content], $request->rules())->errors()->toArray();
    }

    private function sectionErrors(array $errors): array
    {
        return array_filter(
            $errors,
            fn (string $key) => str_starts_with($key, 'content.page_sections') || str_starts_with($key, 'content.hero.video') || str_starts_with($key, 'content.hero.media'),
            ARRAY_FILTER_USE_KEY
        );
    }

    public function test_home_page_accepts_built_in_and_custom_sections_and_a_hero_video(): void
    {
        $errors = $this->errorsFor(CmsPage::SLUG_HOME_PAGE, [
            'hero' => ['media_type' => 'video', 'video_code' => '<iframe src="https://www.youtube.com/embed/abc"></iframe>'],
            'page_sections' => [
                ['id' => 'hero', 'type' => 'hero', 'enabled' => false],
                ['id' => 'custom-1', 'type' => 'custom', 'enabled' => true, 'title' => 'Tour', 'html' => '<iframe src="https://x.test"></iframe>'],
            ],
        ]);

        $this->assertSame([], $this->sectionErrors($errors));
    }

    public function test_a_page_saved_before_sections_existed_still_validates(): void
    {
        $this->assertSame([], $this->sectionErrors($this->errorsFor(CmsPage::SLUG_SHOP, ['badge' => []])));
    }

    public function test_a_section_type_from_another_page_is_refused(): void
    {
        $errors = $this->errorsFor(CmsPage::SLUG_SHOP, [
            'page_sections' => [['id' => 'hero', 'type' => 'hero', 'enabled' => true]],
        ]);

        $this->assertArrayHasKey('content.page_sections.0.type', $errors);
    }

    public function test_duplicate_section_ids_are_refused(): void
    {
        $errors = $this->errorsFor(CmsPage::SLUG_HOME_PAGE, [
            'page_sections' => [
                ['id' => 'a', 'type' => 'custom', 'enabled' => true],
                ['id' => 'a', 'type' => 'custom', 'enabled' => true],
            ],
        ]);

        $this->assertArrayHasKey('content.page_sections.0.id', $errors);
    }

    public function test_hero_part_switches_and_video_settings_are_validated(): void
    {
        $errors = $this->errorsFor(CmsPage::SLUG_HOME_PAGE, ['hero' => [
            'show_badge' => false,
            'show_heading' => false,
            'show_subtext' => false,
            'show_text_color' => false,
            'show_background' => false,
            'video_sound_button' => true,
            'video_overlay_opacity' => 0,
        ]]);
        $this->assertSame([], array_filter($errors, fn (string $key) => preg_match('/hero\.(show_|video_)/', $key), ARRAY_FILTER_USE_KEY));

        $errors = $this->errorsFor(CmsPage::SLUG_HOME_PAGE, ['hero' => ['show_badge' => 'maybe', 'video_overlay_opacity' => 101]]);
        $this->assertArrayHasKey('content.hero.show_badge', $errors);
        $this->assertArrayHasKey('content.hero.video_overlay_opacity', $errors);
    }

    public function test_an_unknown_hero_media_type_is_refused(): void
    {
        $errors = $this->errorsFor(CmsPage::SLUG_HOME_PAGE, ['hero' => ['media_type' => 'gif']]);

        $this->assertArrayHasKey('content.hero.media_type', $errors);
    }
}
