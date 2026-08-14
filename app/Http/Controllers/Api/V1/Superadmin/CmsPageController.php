<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCmsPageRequest;
use App\Http\Resources\CmsPageResource;
use App\Models\CmsPage;
use Illuminate\Http\JsonResponse;

/**
 * Manages the five fixed platform CMS pages (CmsPage::SLUGS) — not a
 * general page builder, so there's no store()/destroy(); every slug
 * already exists via CmsPageSeeder and only its title/content are edited.
 */
class CmsPageController extends Controller
{
    public function index(): JsonResponse
    {
        // Sorted in PHP by the fixed slug order (CmsPage::SLUGS) rather than
        // an ORDER BY, to stay Postgres/SQLite-agnostic — only 5 rows ever
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

        $page = CmsPage::query()->updateOrCreate(
            ['slug' => $slug],
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'data' => new CmsPageResource($page),
            'message' => 'Page saved.',
        ]);
    }
}
