<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCmsPageRequest;
use App\Http\Resources\CmsPageResource;
use App\Models\CmsPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Manages the seven fixed platform CMS pages (CmsPage::SLUGS) — not a
 * general page builder, so there's no store()/destroy(); every slug
 * already exists via CmsPageSeeder and only its title/content are edited.
 *
 * These endpoints always read/write the database directly (never the
 * Redis cache) — the cache exists purely to spare the *public* site
 * repeated queries; the editor itself should always show what's really in
 * the database. Every write here calls Cache::forget(CmsPage::cacheKey())
 * for just that slug, so the very next public read repopulates the cache
 * with the fresh value instead of serving stale content.
 */
class CmsPageController extends Controller
{
    public function index(): JsonResponse
    {
        // Sorted in PHP by the fixed slug order (CmsPage::SLUGS) rather than
        // an ORDER BY, to stay Postgres/SQLite-agnostic — only 7 rows ever
        // exist, so this costs nothing.
        $pages = CmsPage::all()->sortBy(fn ($p) => array_search($p->slug, CmsPage::SLUGS, true))->values();

        return response()->json([
            'success' => true,
            'data' => CmsPageResource::collection($pages),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $page = CmsPage::query()->where('slug', $slug)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new CmsPageResource($page),
        ]);
    }

    public function update(UpdateCmsPageRequest $request, string $slug): JsonResponse
    {
        if (! in_array($slug, CmsPage::SLUGS, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unknown page.',
            ], 404);
        }

        $existing = CmsPage::query()->where('slug', $slug)->first();
        $data = $request->validated();

        // logo_url/background_image_url are managed exclusively via
        // uploadImage()/deleteImage() below, never through this form (the
        // request validation above deliberately never accepts them) — carry
        // whatever is already on the row forward so a routine text/style
        // save can never blow away an uploaded image.
        if (in_array($slug, CmsPage::LOGO_SLUGS, true)) {
            $data['content']['logo_url'] = $existing?->content['logo_url'] ?? null;
            $data['content']['style']['background_image_url'] = $existing?->content['style']['background_image_url'] ?? null;
        }

        $page = CmsPage::query()->updateOrCreate(['slug' => $slug], $data);

        Cache::forget(CmsPage::cacheKey($slug));

        return response()->json([
            'success' => true,
            'data' => new CmsPageResource($page),
            'message' => 'Page saved.',
        ]);
    }

    /** One endpoint for both images header/footer can carry — `type` picks which content key gets the resulting URL. */
    public function uploadImage(Request $request, string $slug): JsonResponse
    {
        if (! in_array($slug, CmsPage::LOGO_SLUGS, true)) {
            return response()->json(['success' => false, 'message' => 'This page has no manageable images.'], 404);
        }

        $request->validate([
            'type' => ['required', 'string', 'in:logo,background'],
            'image' => ['required', 'file', 'mimes:svg,png,jpg,jpeg,gif,webp', 'max:2048'],
        ]);

        $page = CmsPage::query()->where('slug', $slug)->firstOrFail();
        $path = $request->file('image')->store('cms-images', 'public');
        $url = Storage::url($path);

        $content = $page->content;
        if ($request->input('type') === 'logo') {
            $content['logo_url'] = $url;
        } else {
            $content['style']['background_image_url'] = $url;
        }
        $page->update(['content' => $content]);

        Cache::forget(CmsPage::cacheKey($slug));

        return response()->json([
            'success' => true,
            'data' => new CmsPageResource($page->fresh()),
            'message' => 'Image uploaded.',
        ]);
    }

    public function deleteImage(Request $request, string $slug): JsonResponse
    {
        if (! in_array($slug, CmsPage::LOGO_SLUGS, true)) {
            return response()->json(['success' => false, 'message' => 'This page has no manageable images.'], 404);
        }

        $request->validate([
            'type' => ['required', 'string', 'in:logo,background'],
        ]);

        $page = CmsPage::query()->where('slug', $slug)->firstOrFail();

        $content = $page->content;
        if ($request->input('type') === 'logo') {
            $content['logo_url'] = null;
        } else {
            $content['style']['background_image_url'] = null;
        }
        $page->update(['content' => $content]);

        Cache::forget(CmsPage::cacheKey($slug));

        return response()->json([
            'success' => true,
            'data' => new CmsPageResource($page->fresh()),
            'message' => 'Image removed.',
        ]);
    }
}
