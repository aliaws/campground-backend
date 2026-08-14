<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CmsPageResource;
use App\Models\CmsPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only, unauthenticated — Terms of Service / Privacy Policy / Support /
 * About Us / Contact Us / Header / Footer are genuinely global platform
 * content (see CmsPage's own doc comment), so there's no tenant/
 * organization scoping here at all, unlike most other /public/* controllers.
 *
 * Redis-cached forever per slug (every visitor hits header/footer on every
 * page load, so this is the hottest read in the whole CMS feature) —
 * Superadmin\CmsPageController::update()/uploadImage()/deleteImage() each
 * call Cache::forget() for the one slug they touched, so a super-admin
 * edit is visible on the very next public request with no manual/scheduled
 * cache-clearing step.
 */
class PublicCmsPageController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $data = Cache::rememberForever(CmsPage::cacheKey($slug), function () use ($slug) {
            $page = CmsPage::query()->where('slug', $slug)->first();

            return $page ? (new CmsPageResource($page))->resolve() : null;
        });

        if (! $data) {
            return response()->json([
                'success' => false,
                'message' => 'Page not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
